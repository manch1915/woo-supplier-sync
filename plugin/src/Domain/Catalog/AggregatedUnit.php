<?php
/**
 * One sellable unit after merging all supplier offers for it.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Catalog;

use SupplierSync\Domain\Offer;
use SupplierSync\Domain\Pricing\PriceResult;

final class AggregatedUnit {

	public const IN_STOCK     = 'instock';
	public const ON_BACKORDER = 'onbackorder';
	public const OUT_OF_STOCK = 'outofstock';

	/**
	 * @param string                           $unit_key     Merge key.
	 * @param Offer                            $primary      Offer that supplies content and wins on price.
	 * @param PriceResult|null                 $price        Null when no offer could be priced.
	 * @param int|null                         $quantity     Summed stock, null when a supplier did not report quantity.
	 * @param string                           $stock_status instock | onbackorder | outofstock.
	 * @param list<array<string, mixed>>       $sources      Per-supplier breakdown for the log.
	 * @param string|null                      $error        Why the unit could not be priced.
	 */
	public function __construct(
		public readonly string $unit_key,
		public readonly Offer $primary,
		public readonly ?PriceResult $price,
		public readonly ?int $quantity,
		public readonly string $stock_status,
		public readonly array $sources = [],
		public readonly ?string $error = null,
	) {}

	public function sku(): ?string {
		return $this->primary->sku;
	}

	public function manage_stock(): bool {
		return null !== $this->quantity;
	}
}
