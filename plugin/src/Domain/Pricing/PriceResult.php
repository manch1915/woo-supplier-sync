<?php
/**
 * Calculated retail price with the reasoning behind it.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Pricing;

final class PriceResult {

	public function __construct(
		public readonly float $cost,
		public readonly float $regular_price,
		public readonly ?float $sale_price,
		public readonly string $rule_id,
		public readonly bool $margin_floor_applied = false,
	) {}

	/**
	 * Price the customer actually pays.
	 */
	public function final_price(): float {
		return $this->sale_price ?? $this->regular_price;
	}
}
