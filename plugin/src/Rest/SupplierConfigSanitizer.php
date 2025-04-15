<?php
/**
 * Whitelists and cleans a supplier profile coming from the admin UI.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Rest;

use SupplierSync\Domain\Supplier;
use SupplierSync\Feed\RowMapper;

final class SupplierConfigSanitizer {

	public const SECRET_MASK = '••••••••';

	private const SELECTOR_KEYS = [
		'product_links',
		'next_page',
		'name',
		'sku',
		'ean',
		'price',
		'old_price',
		'quantity',
		'available',
		'category',
		'images',
		'description',
		'vendor',
		'attribute_rows',
		'attribute_name',
		'attribute_value',
	];

	/**
	 * @param array<string, mixed> $config   Raw config from the request.
	 * @param Supplier|null        $existing Stored profile, used to keep masked secrets.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $config, ?Supplier $existing = null ): array {
		$source  = (array) ( $config['source'] ?? [] );
		$mapping = (array) ( $config['mapping'] ?? [] );
		$scraper = (array) ( $config['scraper'] ?? [] );

		$clean = [
			'source'              => [
				'url'           => esc_url_raw( trim( (string) ( $source['url'] ?? '' ) ), [ 'http', 'https' ] ),
				'attachment_id' => absint( $source['attachment_id'] ?? 0 ),
				'headers'       => $this->headers( (array) ( $source['headers'] ?? [] ), $existing ),
			],
			'currency'            => $this->currency( (string) ( $config['currency'] ?? '' ) ),
			'mapping'             => [
				'delimiter'         => in_array( $mapping['delimiter'] ?? 'auto', [ 'auto', ';', ',', "\t", '|' ], true ) ? $mapping['delimiter'] : 'auto',
				'encoding'          => in_array( $mapping['encoding'] ?? 'UTF-8', [ 'UTF-8', 'windows-1251', 'ISO-8859-1' ], true ) ? $mapping['encoding'] : 'UTF-8',
				'decimal'           => in_array( $mapping['decimal'] ?? 'auto', [ 'auto', '.', ',' ], true ) ? $mapping['decimal'] : 'auto',
				'columns'           => $this->columns( (array) ( $mapping['columns'] ?? [] ) ),
				'attribute_columns' => $this->text_list( (array) ( $mapping['attribute_columns'] ?? [] ) ),
				'attribute_prefix'  => sanitize_text_field( (string) ( $mapping['attribute_prefix'] ?? '' ) ),
				'items_path'        => sanitize_text_field( (string) ( $mapping['items_path'] ?? '' ) ),
				'item_tag'          => preg_replace( '/[^\w:.-]/', '', (string) ( $mapping['item_tag'] ?? '' ) ),
				'fields'            => $this->columns( (array) ( $mapping['fields'] ?? [] ) ),
				'attributes_path'   => sanitize_text_field( (string) ( $mapping['attributes_path'] ?? '' ) ),
				'attribute_name'    => sanitize_text_field( (string) ( $mapping['attribute_name'] ?? '@name' ) ),
			],
			'scraper'             => [
				'start_urls'   => array_values( array_filter( array_map( static fn ( $u ): string => esc_url_raw( trim( (string) $u ), [ 'http', 'https' ] ), (array) ( $scraper['start_urls'] ?? [] ) ) ) ),
				'max_pages'    => min( 500, max( 1, (int) ( $scraper['max_pages'] ?? 50 ) ) ),
				'max_products' => min( 50000, max( 1, (int) ( $scraper['max_products'] ?? 5000 ) ) ),
				'delay_ms'     => min( 10000, max( 0, (int) ( $scraper['delay_ms'] ?? 300 ) ) ),
				'selectors'    => $this->selectors( (array) ( $scraper['selectors'] ?? [] ) ),
			],
			'attributes'          => $this->pairs( (array) ( $config['attributes'] ?? [] ) ),
			'unmapped_attributes' => 'skip' === ( $config['unmapped_attributes'] ?? 'keep' ) ? 'skip' : 'keep',
			'categories'          => $this->category_map( (array) ( $config['categories'] ?? [] ) ),
			'unmapped_categories' => $this->unmapped_policy( $config['unmapped_categories'] ?? 'create' ),
			'category_root'       => is_numeric( $config['category_root'] ?? null ) ? absint( $config['category_root'] ) : sanitize_text_field( (string) ( $config['category_root'] ?? '' ) ),
			'update_fields'       => array_values( array_intersect( Supplier::UPDATABLE_FIELDS, (array) ( $config['update_fields'] ?? Supplier::UPDATABLE_FIELDS ) ) ),
			'max_removal_percent' => min( 100, max( 0, (int) ( $config['max_removal_percent'] ?? 30 ) ) ),
		];

		return $clean;
	}

	/**
	 * Hides header values (API keys) in API responses.
	 *
	 * @param array<string, mixed> $config Stored config.
	 * @return array<string, mixed>
	 */
	public function redact( array $config ): array {
		$headers = (array) ( $config['source']['headers'] ?? [] );
		foreach ( $headers as $name => $value ) {
			$headers[ $name ] = '' === (string) $value ? '' : self::SECRET_MASK;
		}
		$config['source']['headers'] = (object) $headers;
		return $config;
	}

	/**
	 * @param array<mixed>  $headers  Name => value; a masked value keeps the stored secret.
	 * @param Supplier|null $existing Stored profile.
	 * @return array<string, string>
	 */
	private function headers( array $headers, ?Supplier $existing ): array {
		$stored = (array) ( $existing?->config['source']['headers'] ?? [] );
		$clean  = [];
		foreach ( $headers as $name => $value ) {
			$name = (string) preg_replace( '/[^A-Za-z0-9-]/', '', (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$value          = (string) $value;
			$clean[ $name ] = self::SECRET_MASK === $value ? (string) ( $stored[ $name ] ?? '' ) : sanitize_text_field( $value );
		}
		return $clean;
	}

	private function currency( string $code ): string {
		$code = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', $code ) );
		return 3 === strlen( $code ) ? $code : '';
	}

	/**
	 * @param array<mixed> $columns Field => column header or path.
	 * @return array<string, string>
	 */
	private function columns( array $columns ): array {
		$clean = [];
		foreach ( RowMapper::FIELDS as $field ) {
			$value = trim( sanitize_text_field( (string) ( $columns[ $field ] ?? '' ) ) );
			if ( '' !== $value ) {
				$clean[ $field ] = $value;
			}
		}
		return $clean;
	}

	/**
	 * @param array<mixed> $selectors Field => CSS selector.
	 * @return array<string, string>
	 */
	private function selectors( array $selectors ): array {
		$clean = [];
		foreach ( self::SELECTOR_KEYS as $key ) {
			// Selectors contain ">" and quotes, so sanitize_text_field() would mangle them.
			$value = trim( wp_strip_all_tags( (string) ( $selectors[ $key ] ?? '' ) ) );
			if ( '' !== $value ) {
				$clean[ $key ] = mb_substr( $value, 0, 300 );
			}
		}
		return $clean;
	}

	/**
	 * @param array<mixed> $values Raw list.
	 * @return list<string>
	 */
	private function text_list( array $values ): array {
		return array_values( array_filter( array_map( static fn ( $v ): string => trim( sanitize_text_field( (string) $v ) ), $values ) ) );
	}

	/**
	 * @param array<mixed> $pairs Supplier attribute => store attribute.
	 * @return array<string, string>
	 */
	private function pairs( array $pairs ): array {
		$clean = [];
		foreach ( $pairs as $from => $to ) {
			$from = trim( sanitize_text_field( (string) $from ) );
			if ( '' !== $from ) {
				$clean[ $from ] = trim( sanitize_text_field( (string) $to ) );
			}
		}
		return $clean;
	}

	/**
	 * @param array<mixed> $map Supplier category => term ID or path.
	 * @return array<string, int|string>
	 */
	private function category_map( array $map ): array {
		$clean = [];
		foreach ( $map as $from => $to ) {
			$from = trim( sanitize_text_field( (string) $from ) );
			if ( '' === $from ) {
				continue;
			}
			$clean[ $from ] = is_numeric( $to ) ? absint( $to ) : trim( sanitize_text_field( (string) $to ) );
		}
		return $clean;
	}

	private function unmapped_policy( mixed $policy ): string|int {
		if ( is_numeric( $policy ) ) {
			return absint( $policy );
		}
		return in_array( $policy, [ 'create', 'skip' ], true ) ? (string) $policy : 'create';
	}
}
