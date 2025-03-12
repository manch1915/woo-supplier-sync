<?php
/**
 * One sellable unit as a supplier describes it, normalized across feed formats.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain;

final class Offer {

	public readonly ?string $sku;
	public readonly ?string $ean;

	/**
	 * @param int                   $supplier_id   Supplier profile ID.
	 * @param string                $external_id   Supplier's own offer ID.
	 * @param string                $name          Product title.
	 * @param string|null           $sku           Manufacturer article (vendorCode), used to merge suppliers.
	 * @param string|null           $ean           Barcode, preferred merge key.
	 * @param float|null            $price         Purchase price in the supplier currency.
	 * @param string                $currency      ISO 4217 code.
	 * @param float|null            $old_price     Recommended retail price, if any.
	 * @param int|null              $quantity      Stock reported by the supplier, null if unknown.
	 * @param Availability          $availability  Supplier availability.
	 * @param string                $category_id   Supplier category ID.
	 * @param string                $category_path Supplier category path, e.g. "Audio > Headphones".
	 * @param list<string>          $images        Image URLs, main image first.
	 * @param array<string, string> $attributes    Attribute name => value.
	 * @param string|null           $group_id      Supplier group ID that ties variations together.
	 * @param string                $description   HTML description.
	 * @param string                $vendor        Brand.
	 * @param string                $url           Product page at the supplier.
	 */
	public function __construct(
		public readonly int $supplier_id,
		public readonly string $external_id,
		public readonly string $name,
		?string $sku,
		?string $ean,
		public readonly ?float $price,
		public readonly string $currency,
		public readonly ?float $old_price = null,
		public readonly ?int $quantity = null,
		public readonly Availability $availability = Availability::IN_STOCK,
		public readonly string $category_id = '',
		public readonly string $category_path = '',
		public readonly array $images = [],
		public readonly array $attributes = [],
		public readonly ?string $group_id = null,
		public readonly string $description = '',
		public readonly string $vendor = '',
		public readonly string $url = '',
	) {
		$this->sku = self::normalize_sku( $sku );
		$this->ean = self::normalize_ean( $ean );
	}

	/**
	 * Key that identifies the same physical product across suppliers.
	 */
	public function unit_key(): string {
		if ( null !== $this->ean ) {
			return 'ean:' . $this->ean;
		}
		if ( null !== $this->sku ) {
			return 'sku:' . strtolower( (string) preg_replace( '/[\s_-]+/', '', $this->sku ) );
		}
		return 'ext:' . $this->supplier_id . ':' . $this->external_id;
	}

	/**
	 * Key of the WooCommerce product this unit belongs to. Variations share it.
	 */
	public function default_group_key(): string {
		if ( null !== $this->group_id && '' !== $this->group_id ) {
			return 'grp:' . $this->supplier_id . ':' . $this->group_id;
		}
		return $this->unit_key();
	}

	public function is_available(): bool {
		return Availability::IN_STOCK === $this->availability;
	}

	/**
	 * Fingerprint used for delta updates: unchanged offers are skipped.
	 */
	public function hash(): string {
		return hash( 'xxh128', (string) json_encode( $this->to_array() ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'supplier_id'   => $this->supplier_id,
			'external_id'   => $this->external_id,
			'name'          => $this->name,
			'sku'           => $this->sku,
			'ean'           => $this->ean,
			'price'         => $this->price,
			'currency'      => $this->currency,
			'old_price'     => $this->old_price,
			'quantity'      => $this->quantity,
			'availability'  => $this->availability->value,
			'category_id'   => $this->category_id,
			'category_path' => $this->category_path,
			'images'        => $this->images,
			'attributes'    => $this->attributes,
			'group_id'      => $this->group_id,
			'description'   => $this->description,
			'vendor'        => $this->vendor,
			'url'           => $this->url,
		];
	}

	/**
	 * @param array<string, mixed> $data Output of to_array().
	 */
	public static function from_array( array $data ): self {
		return new self(
			supplier_id: (int) ( $data['supplier_id'] ?? 0 ),
			external_id: (string) ( $data['external_id'] ?? '' ),
			name: (string) ( $data['name'] ?? '' ),
			sku: isset( $data['sku'] ) ? (string) $data['sku'] : null,
			ean: isset( $data['ean'] ) ? (string) $data['ean'] : null,
			price: isset( $data['price'] ) ? (float) $data['price'] : null,
			currency: (string) ( $data['currency'] ?? '' ),
			old_price: isset( $data['old_price'] ) ? (float) $data['old_price'] : null,
			quantity: isset( $data['quantity'] ) ? (int) $data['quantity'] : null,
			availability: Availability::tryFrom( (string) ( $data['availability'] ?? '' ) ) ?? Availability::IN_STOCK,
			category_id: (string) ( $data['category_id'] ?? '' ),
			category_path: (string) ( $data['category_path'] ?? '' ),
			images: array_values( array_map( 'strval', (array) ( $data['images'] ?? [] ) ) ),
			attributes: array_map( 'strval', (array) ( $data['attributes'] ?? [] ) ),
			group_id: isset( $data['group_id'] ) ? (string) $data['group_id'] : null,
			description: (string) ( $data['description'] ?? '' ),
			vendor: (string) ( $data['vendor'] ?? '' ),
			url: (string) ( $data['url'] ?? '' ),
		);
	}

	private static function normalize_sku( ?string $sku ): ?string {
		if ( null === $sku ) {
			return null;
		}
		$sku = trim( $sku );
		return '' === $sku ? null : $sku;
	}

	/**
	 * Keeps only digits and accepts EAN-8, UPC-A, EAN-13 and GTIN-14 lengths.
	 *
	 * @param string|null $ean Raw barcode.
	 */
	private static function normalize_ean( ?string $ean ): ?string {
		if ( null === $ean ) {
			return null;
		}
		$digits = (string) preg_replace( '/\D+/', '', $ean );
		return in_array( strlen( $digits ), [ 8, 12, 13, 14 ], true ) ? $digits : null;
	}
}
