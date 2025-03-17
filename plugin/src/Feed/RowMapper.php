<?php
/**
 * Maps a flat row (CSV/XLSX line, flattened JSON object, XML item) to an Offer
 * using the column mapping from the supplier profile.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Feed;

use SupplierSync\Domain\Availability;
use SupplierSync\Domain\Offer;

final class RowMapper {

	public const FIELDS = [
		'external_id',
		'name',
		'sku',
		'ean',
		'price',
		'old_price',
		'currency',
		'quantity',
		'available',
		'category',
		'images',
		'description',
		'vendor',
		'group_id',
		'url',
	];

	/**
	 * @var array<string, string>
	 */
	private array $columns;

	/**
	 * @var list<string>
	 */
	private array $attribute_columns;

	private string $attribute_prefix;
	private string $decimal;
	private string $default_currency;

	/**
	 * @param int                  $supplier_id Supplier profile.
	 * @param array<string, mixed> $config      columns, attribute_columns, attribute_prefix, decimal, currency.
	 */
	public function __construct( private readonly int $supplier_id, array $config ) {
		$this->columns           = array_filter( array_map( 'strval', (array) ( $config['columns'] ?? [] ) ) );
		$this->attribute_columns = array_values( array_filter( array_map( 'strval', (array) ( $config['attribute_columns'] ?? [] ) ) ) );
		$this->attribute_prefix  = (string) ( $config['attribute_prefix'] ?? '' );
		$this->decimal           = (string) ( $config['decimal'] ?? 'auto' );
		$this->default_currency  = (string) ( $config['currency'] ?? '' );
	}

	/**
	 * @param array<string, mixed> $row       Header => value.
	 * @param string               $reference Row reference for error messages.
	 */
	public function map( array $row, string $reference ): Offer|RowError {
		$name = $this->text( $row, 'name' );
		if ( '' === $name ) {
			return new RowError( $reference, 'Name is empty.' );
		}

		$price = Number::parse( $this->text( $row, 'price' ), $this->decimal );
		if ( null === $price ) {
			return new RowError( $reference, sprintf( 'Price "%s" is not a number.', $this->text( $row, 'price' ) ) );
		}

		$quantity      = Number::quantity( $this->text( $row, 'quantity' ) );
		$available     = Number::flag( $this->text( $row, 'available' ) );
		$external_id   = $this->text( $row, 'external_id' );
		$category      = $this->text( $row, 'category' );
		$group_id      = $this->text( $row, 'group_id' );
		$currency      = $this->text( $row, 'currency' );
		$old_price     = Number::parse( $this->text( $row, 'old_price' ), $this->decimal );
		$sku           = $this->text( $row, 'sku' );
		$external_id   = '' !== $external_id ? $external_id : ( '' !== $sku ? $sku : $reference );
		$category_path = (string) preg_replace( '/\s*(>|\/|\||»)\s*/u', ' > ', $category );

		return new Offer(
			supplier_id: $this->supplier_id,
			external_id: $external_id,
			name: $name,
			sku: $sku,
			ean: $this->text( $row, 'ean' ),
			price: $price,
			currency: '' !== $currency ? strtoupper( $currency ) : $this->default_currency,
			old_price: $old_price,
			quantity: $quantity,
			availability: Availability::from_stock( $quantity, $available ),
			category_id: $category_path,
			category_path: $category_path,
			images: $this->images( $row ),
			attributes: $this->attributes( $row ),
			group_id: '' !== $group_id ? $group_id : null,
			description: $this->text( $row, 'description' ),
			vendor: $this->text( $row, 'vendor' ),
			url: $this->text( $row, 'url' ),
		);
	}

	/**
	 * @param array<string, mixed> $row Row.
	 * @param string               $field Offer field.
	 */
	private function text( array $row, string $field ): string {
		$column = $this->columns[ $field ] ?? '';
		if ( '' === $column || ! array_key_exists( $column, $row ) ) {
			return '';
		}
		$value = $row[ $column ];
		if ( is_array( $value ) ) {
			$value = implode( '|', array_map( 'strval', $value ) );
		}
		return trim( (string) $value );
	}

	/**
	 * @param array<string, mixed> $row Row.
	 * @return list<string>
	 */
	private function images( array $row ): array {
		$column = $this->columns['images'] ?? '';
		$values = [];

		if ( '' !== $column ) {
			foreach ( $row as $key => $value ) {
				// "images", "images.0", "images.1" from flattened JSON.
				if ( $key === $column || str_starts_with( (string) $key, $column . '.' ) ) {
					$values[] = is_array( $value ) ? implode( ' ', array_map( 'strval', $value ) ) : (string) $value;
				}
			}
		}

		$urls = [];
		foreach ( $values as $value ) {
			foreach ( (array) preg_split( '/[\s,;|]+/', $value ) as $url ) {
				$url = trim( (string) $url );
				if ( preg_match( '#^https?://#i', $url ) ) {
					$urls[] = $url;
				}
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * @param array<string, mixed> $row Row.
	 * @return array<string, string>
	 */
	private function attributes( array $row ): array {
		$attributes = [];

		foreach ( $this->attribute_columns as $column ) {
			$value = trim( (string) ( $row[ $column ] ?? '' ) );
			if ( '' !== $value ) {
				$attributes[ $column ] = $value;
			}
		}

		if ( '' !== $this->attribute_prefix ) {
			foreach ( $row as $key => $value ) {
				if ( str_starts_with( (string) $key, $this->attribute_prefix . '.' ) && is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$attributes[ substr( (string) $key, strlen( $this->attribute_prefix ) + 1 ) ] = trim( (string) $value );
				}
			}
		}

		if ( isset( $row['@attributes'] ) && is_array( $row['@attributes'] ) ) {
			foreach ( $row['@attributes'] as $name => $value ) {
				$attributes[ (string) $name ] = (string) $value;
			}
		}

		return $attributes;
	}
}
