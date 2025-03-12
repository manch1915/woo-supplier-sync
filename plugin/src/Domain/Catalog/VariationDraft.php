<?php
/**
 * Desired state of one product variation.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Catalog;

final class VariationDraft {

	/**
	 * @param string                $unit_key      Merge key of the unit.
	 * @param string|null           $sku           Variation SKU.
	 * @param array<string, string> $attributes    Attribute name => option.
	 * @param float|null            $regular_price Regular price.
	 * @param float|null            $sale_price    Sale price.
	 * @param int|null              $quantity      Managed stock or null.
	 * @param string                $stock_status  instock | onbackorder | outofstock.
	 * @param string|null           $image         Variation image URL.
	 * @param string|null           $ean           Barcode.
	 */
	public function __construct(
		public readonly string $unit_key,
		public readonly ?string $sku,
		public readonly array $attributes,
		public readonly ?float $regular_price,
		public readonly ?float $sale_price,
		public readonly ?int $quantity,
		public readonly string $stock_status,
		public readonly ?string $image = null,
		public readonly ?string $ean = null,
	) {}

	/**
	 * Stable key to match variations between runs.
	 */
	public function key(): string {
		return $this->sku ?? $this->unit_key;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_comparable(): array {
		$attributes = $this->attributes;
		ksort( $attributes );

		return [
			'attributes'    => $attributes,
			'regular_price' => $this->regular_price,
			'sale_price'    => $this->sale_price,
			'quantity'      => $this->quantity,
			'stock_status'  => $this->stock_status,
		];
	}
}
