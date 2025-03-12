<?php
/**
 * Desired state of a WooCommerce product, built from supplier offers.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Catalog;

final class ProductDraft {

	public const SIMPLE   = 'simple';
	public const VARIABLE = 'variable';

	/**
	 * @param string                                                         $group_key      Product identity in the sync tables.
	 * @param string                                                         $type           simple | variable.
	 * @param string                                                         $name           Title.
	 * @param string                                                         $description    HTML description.
	 * @param string|null                                                    $sku            SKU of a simple product.
	 * @param list<int>                                                      $category_ids   Store category IDs.
	 * @param list<array{name: string, values: list<string>, variation: bool}> $attributes   Product attributes.
	 * @param list<string>                                                   $images         Image URLs, main first.
	 * @param float|null                                                     $regular_price  Simple product price.
	 * @param float|null                                                     $sale_price     Simple product sale price.
	 * @param int|null                                                       $quantity       Managed stock, null when unknown.
	 * @param string                                                         $stock_status   instock | onbackorder | outofstock.
	 * @param string                                                         $status         publish | draft.
	 * @param string                                                         $visibility     visible | hidden.
	 * @param list<VariationDraft>                                           $variations     Variations of a variable product.
	 * @param array<string, scalar|null>                                     $meta           Extra data: EAN, cost, suppliers.
	 */
	public function __construct(
		public readonly string $group_key,
		public readonly string $type,
		public readonly string $name,
		public readonly string $description,
		public readonly ?string $sku,
		public readonly array $category_ids,
		public readonly array $attributes,
		public readonly array $images,
		public readonly ?float $regular_price,
		public readonly ?float $sale_price,
		public readonly ?int $quantity,
		public readonly string $stock_status,
		public readonly string $status,
		public readonly string $visibility,
		public readonly array $variations = [],
		public readonly array $meta = [],
	) {}

	public function is_variable(): bool {
		return self::VARIABLE === $this->type;
	}

	/**
	 * Flat, order-independent view used to diff against the current product.
	 *
	 * @return array<string, mixed>
	 */
	public function to_comparable(): array {
		$categories = $this->category_ids;
		sort( $categories );

		$attributes = [];
		foreach ( $this->attributes as $attribute ) {
			$values = $attribute['values'];
			sort( $values );
			$attributes[ $attribute['name'] ] = implode( ', ', $values );
		}
		ksort( $attributes );

		$data = [
			'type'         => $this->type,
			'name'         => $this->name,
			'description'  => md5( trim( $this->description ) ),
			'categories'   => implode( ',', $categories ),
			'attributes'   => $attributes,
			'images'       => implode( ' ', $this->images ),
			'stock_status' => $this->stock_status,
			'status'       => $this->status,
			'visibility'   => $this->visibility,
		];

		if ( $this->is_variable() ) {
			$variations = [];
			foreach ( $this->variations as $variation ) {
				$variations[ $variation->key() ] = $variation->to_comparable();
			}
			ksort( $variations );
			$data['variations'] = $variations;
		} else {
			$data['sku']           = $this->sku;
			$data['regular_price'] = $this->regular_price;
			$data['sale_price']    = $this->sale_price;
			$data['quantity']      = $this->quantity;
		}

		return $data;
	}
}
