<?php
/**
 * Reads an existing product in the same shape as ProductDraft::to_comparable().
 *
 * Name and description come from the last value the supplier sent (stored in meta),
 * so a manager's manual edit survives until the supplier changes that field.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure\Woo;

use SupplierSync\Domain\Catalog\ProductDraft;

final class ProductSnapshot {

	public const META_GROUP       = '_supplier_sync_group';
	public const META_NAME        = '_supplier_sync_source_name';
	public const META_DESCRIPTION = '_supplier_sync_source_description';
	public const META_IMAGES      = '_supplier_sync_images';
	public const META_IMAGES_DONE = '_supplier_sync_images_done';
	public const META_IMAGE       = '_supplier_sync_image';
	public const META_UNIT        = '_supplier_sync_unit';
	public const META_SUPPLIERS   = '_supplier_sync_suppliers';
	public const META_COST        = '_supplier_sync_cost';

	/**
	 * @return array<string, mixed>|null
	 */
	public function read( int $product_id ): ?array {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product || 'trash' === $product->get_status() ) {
			return null;
		}

		$categories = array_map( 'intval', $product->get_category_ids() );
		sort( $categories );

		$source_name = (string) $product->get_meta( self::META_NAME );
		$description = (string) $product->get_meta( self::META_DESCRIPTION );
		$images      = $product->get_meta( self::META_IMAGES );

		$data = [
			'type'         => $product->is_type( 'variable' ) ? ProductDraft::VARIABLE : ProductDraft::SIMPLE,
			'name'         => '' !== $source_name ? $source_name : $product->get_name(),
			'description'  => '' !== $description ? $description : md5( trim( $product->get_description() ) ),
			'categories'   => implode( ',', $categories ),
			'attributes'   => $this->attributes( $product ),
			'images'       => is_array( $images ) ? implode( ' ', $images ) : '',
			'stock_status' => $product->get_stock_status(),
			'status'       => $product->get_status(),
			'visibility'   => 'hidden' === $product->get_catalog_visibility() ? 'hidden' : 'visible',
		];

		if ( $product instanceof \WC_Product_Variable ) {
			$variations = [];
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() ) {
					continue;
				}
				$key                = '' !== $variation->get_sku() ? $variation->get_sku() : (string) $variation->get_meta( self::META_UNIT );
				$variations[ $key ] = [
					'attributes'    => $this->variation_attributes( $variation ),
					'regular_price' => $variation->get_regular_price(),
					'sale_price'    => $variation->get_sale_price(),
					'quantity'      => $variation->get_manage_stock() ? $variation->get_stock_quantity() : null,
					'stock_status'  => $variation->get_stock_status(),
				];
			}
			ksort( $variations );
			$data['variations'] = $variations;
		} else {
			$data['sku']           = $product->get_sku();
			$data['regular_price'] = $product->get_regular_price();
			$data['sale_price']    = $product->get_sale_price();
			$data['quantity']      = $product->get_manage_stock() ? $product->get_stock_quantity() : null;
		}

		return $data;
	}

	/**
	 * @return array<string, string>
	 */
	private function attributes( \WC_Product $product ): array {
		$attributes = [];
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				$label  = self::label( $attribute->get_name() );
				$values = array_map( static fn ( \WP_Term $t ): string => $t->name, $attribute->get_terms() ?? [] );
			} else {
				$label  = $attribute->get_name();
				$values = array_map( 'strval', $attribute->get_options() );
			}
			sort( $values );
			$attributes[ $label ] = implode( ', ', $values );
		}
		ksort( $attributes );
		return $attributes;
	}

	/**
	 * @return array<string, string>
	 */
	private function variation_attributes( \WC_Product_Variation $variation ): array {
		$attributes = [];
		foreach ( $variation->get_attributes() as $taxonomy => $slug ) {
			$term = taxonomy_exists( (string) $taxonomy ) ? get_term_by( 'slug', (string) $slug, (string) $taxonomy ) : false;

			$attributes[ self::label( (string) $taxonomy ) ] = $term instanceof \WP_Term ? $term->name : (string) $slug;
		}
		ksort( $attributes );
		return $attributes;
	}

	/**
	 * Attribute label from the registered taxonomy. wc_attribute_label() reads a cache that
	 * is stale for attributes created earlier in the same request.
	 */
	public static function label( string $taxonomy ): string {
		$object = get_taxonomy( $taxonomy );
		$label  = $object instanceof \WP_Taxonomy ? (string) $object->labels->singular_name : '';
		return '' !== $label && $label !== $taxonomy ? $label : wc_attribute_label( $taxonomy );
	}
}
