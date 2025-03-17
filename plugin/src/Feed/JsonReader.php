<?php
/**
 * JSON API responses and JSON Lines files. Nested objects are flattened into dot paths
 * ("price.retail", "images.0") so the same column mapping works as for CSV.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Feed;

final class JsonReader implements FeedReader, TabularSource {

	/**
	 * @param RowMapper $mapper     Column mapping.
	 * @param string    $items_path Dot path to the list of products, empty for a top-level list.
	 */
	public function __construct(
		private readonly RowMapper $mapper,
		private readonly string $items_path = '',
	) {}

	public function read( string $path, int $offset = 0 ): \Generator {
		foreach ( $this->rows( $path, $offset ) as $position => $row ) {
			yield $position => $this->mapper->map( $row, 'item ' . ( $position + 1 ) );
		}
	}

	public function rows( string $path, int $offset = 0 ): \Generator {
		if ( str_ends_with( strtolower( $path ), '.jsonl' ) ) {
			yield from $this->lines( $path, $offset );
			return;
		}

		$raw  = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new FeedException( 'The response is not valid JSON.' );
		}

		$items = $this->dig( $data, $this->items_path );
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			throw new FeedException( sprintf( 'No list of products at "%s".', '' !== $this->items_path ? $this->items_path : '(root)' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		foreach ( $items as $position => $item ) {
			if ( $position >= $offset && is_array( $item ) ) {
				yield $position => self::flatten( $item );
			}
		}
	}

	/**
	 * @param string $path   File path.
	 * @param int    $offset First line.
	 * @return \Generator<int, array<string, mixed>>
	 */
	private function lines( string $path, int $offset ): \Generator {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			throw new FeedException( 'Cannot open the JSON Lines file.' );
		}

		$position = 0;
		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( '' === trim( $line ) ) {
				continue;
			}
			if ( $position >= $offset ) {
				$item = json_decode( $line, true );
				yield $position => is_array( $item ) ? self::flatten( $item ) : [];
			}
			++$position;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * @param array<mixed> $data Decoded JSON.
	 * @param string       $path Dot path.
	 */
	private function dig( array $data, string $path ): mixed {
		if ( '' === $path ) {
			return $data;
		}
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return null;
			}
			$data = $data[ $key ];
		}
		return $data;
	}

	/**
	 * @param array<mixed> $item   Nested item.
	 * @param string       $prefix Path prefix.
	 * @return array<string, mixed>
	 */
	public static function flatten( array $item, string $prefix = '' ): array {
		$flat = [];
		foreach ( $item as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) ) {
				$flat += self::flatten( $value, $path );
			} elseif ( null !== $value ) {
				$flat[ $path ] = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
			}
		}
		return $flat;
	}
}
