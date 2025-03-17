<?php
/**
 * CSV price list reader with delimiter detection and windows-1251 support.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Feed;

final class CsvReader implements FeedReader, TabularSource {

	/**
	 * @param RowMapper $mapper    Column mapping.
	 * @param string    $delimiter Delimiter or "auto".
	 * @param string    $encoding  Source encoding.
	 */
	public function __construct(
		private readonly RowMapper $mapper,
		private readonly string $delimiter = 'auto',
		private readonly string $encoding = 'UTF-8',
	) {}

	public function read( string $path, int $offset = 0 ): \Generator {
		foreach ( $this->rows( $path, $offset ) as $position => $row ) {
			yield $position => $this->mapper->map( $row, 'row ' . ( $position + 2 ) );
		}
	}

	public function rows( string $path, int $offset = 0 ): \Generator {
		$handle = $this->open( $path );

		$first     = (string) fgets( $handle );
		$delimiter = 'auto' === $this->delimiter ? $this->detect_delimiter( $first ) : $this->delimiter;
		rewind( $handle );

		$header = fgetcsv( $handle, null, $delimiter, '"', '' );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new FeedException( 'The CSV file is empty.' );
		}
		$header    = array_map( static fn ( $h ): string => trim( (string) $h ), $header );
		$header[0] = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
		$width     = count( $header );

		$position = 0;
		while ( false !== ( $cells = fgetcsv( $handle, null, $delimiter, '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( [ null ] === $cells || [] === array_filter( $cells, static fn ( $c ): bool => '' !== trim( (string) $c ) ) ) {
				continue;
			}
			if ( $position >= $offset ) {
				$cells = array_slice( array_pad( $cells, $width, '' ), 0, $width );
				yield $position => array_combine( $header, array_map( 'strval', $cells ) );
			}
			++$position;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * @param string $path File path.
	 * @return resource
	 */
	private function open( string $path ) {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			throw new FeedException( 'Cannot open the CSV file.' );
		}
		if ( 'UTF-8' !== strtoupper( $this->encoding ) ) {
			stream_filter_append( $handle, sprintf( 'convert.iconv.%s/UTF-8', $this->encoding ), STREAM_FILTER_READ );
		}
		return $handle;
	}

	private function detect_delimiter( string $line ): string {
		$counts = [];
		foreach ( [ ';', ',', "\t", '|' ] as $candidate ) {
			$counts[ $candidate ] = substr_count( $line, $candidate );
		}
		arsort( $counts );
		return (string) array_key_first( $counts );
	}
}
