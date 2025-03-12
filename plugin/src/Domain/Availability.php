<?php
/**
 * Supplier-side availability of an offer.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain;

enum Availability: string {
	case IN_STOCK     = 'in_stock';
	case ON_ORDER     = 'on_order';
	case OUT_OF_STOCK = 'out_of_stock';

	/**
	 * Derives availability from a quantity and a supplier "available" flag.
	 * In YML, available="false" means the item can be ordered with a delay.
	 *
	 * @param int|null $quantity  Quantity reported by the supplier, null if unknown.
	 * @param bool     $available Supplier availability flag.
	 */
	public static function from_stock( ?int $quantity, bool $available ): self {
		if ( null === $quantity ) {
			return $available ? self::IN_STOCK : self::ON_ORDER;
		}
		if ( $quantity > 0 ) {
			return self::IN_STOCK;
		}
		return $available ? self::OUT_OF_STOCK : self::ON_ORDER;
	}
}
