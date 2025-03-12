<?php
/**
 * Renames supplier attributes to store attributes ("Цвет" -> "Color") and drops noise.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Catalog;

use SupplierSync\Domain\Offer;

final class AttributeMap {

	/**
	 * @var array<string, string>
	 */
	private array $map = [];

	/**
	 * @param array<string, string> $map           Supplier name => store name, empty value drops the attribute.
	 * @param bool                  $keep_unmapped Keep attributes that are not in the map.
	 */
	public function __construct( array $map, private readonly bool $keep_unmapped = true ) {
		foreach ( $map as $from => $to ) {
			$this->map[ mb_strtolower( trim( (string) $from ) ) ] = trim( (string) $to );
		}
	}

	public function apply( Offer $offer ): Offer {
		$attributes = [];
		foreach ( $offer->attributes as $name => $value ) {
			$key = mb_strtolower( trim( (string) $name ) );
			if ( array_key_exists( $key, $this->map ) ) {
				if ( '' !== $this->map[ $key ] ) {
					$attributes[ $this->map[ $key ] ] = $value;
				}
			} elseif ( $this->keep_unmapped ) {
				$attributes[ (string) $name ] = $value;
			}
		}

		if ( $attributes === $offer->attributes ) {
			return $offer;
		}

		$data               = $offer->to_array();
		$data['attributes'] = $attributes;
		return Offer::from_array( $data );
	}
}
