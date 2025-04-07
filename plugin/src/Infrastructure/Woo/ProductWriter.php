<?php
/**
 * Applies a ProductDraft to WooCommerce through the CRUD API, which keeps HPOS,
 * lookup tables and caches consistent. On updates only the changed fields are written.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure\Woo;

use SupplierSync\Domain\Catalog\AggregatedUnit;
use SupplierSync\Domain\Catalog\ChangeSet;
use SupplierSync\Domain\Catalog\ProductDraft;
use SupplierSync\Domain\Catalog\VariationDraft;

final class ProductWriter {

	public function __construct( private readonly AttributeRegistry $attributes ) {}

	/**
	 * @param ProductDraft $draft      Desired state.
	 * @param ChangeSet    $changes    Diff against the current product.
	 * @param int|null     $product_id Existing product, null to create one.
	 * @return int Product ID.
	 *
	 * @throws \WC_Data_Exception On invalid data such as a duplicate SKU.
	 */
	public function write( ProductDraft $draft, ChangeSet $changes, ?int $product_id ): int {
		$product = null !== $product_id ? wc_get_product( $product_id ) : null;
		$is_new  = ! $product instanceof \WC_Product;
		$fields  = $is_new ? null : $changes->changed_fields();
		$touch   = static fn ( string $field ): bool => null === $fields || in_array( $field, $fields, true );

		if ( $is_new ) {
			$product = $draft->is_variable() ? new \WC_Product_Variable() : new \WC_Product_Simple();
		} elseif ( $touch( 'type' ) ) {
			$class   = \WC_Product_Factory::get_product_classname( (int) $product_id, $draft->type );
			$product = new $class( (int) $product_id );
		}
		assert( $product instanceof \WC_Product );

		if ( $touch( 'name' ) ) {
			$product->set_name( $draft->name );
			$product->update_meta_data( ProductSnapshot::META_NAME, $draft->name );
		}
		if ( $touch( 'description' ) ) {
			$product->set_description( wp_kses_post( $draft->description ) );
			$product->update_meta_data( ProductSnapshot::META_DESCRIPTION, md5( trim( $draft->description ) ) );
		}
		if ( $touch( 'categories' ) ) {
			$product->set_category_ids( $draft->category_ids );
		}
		if ( $touch( 'attributes' ) || ( $draft->is_variable() && $touch( 'variations' ) ) ) {
			$product->set_attributes( $this->product_attributes( $draft ) );
		}
		if ( $touch( 'images' ) ) {
			$product->update_meta_data( ProductSnapshot::META_IMAGES, $draft->images );
		}
		if ( $touch( 'status' ) ) {
			$product->set_status( $draft->status );
		}
		if ( $touch( 'visibility' ) ) {
			$product->set_catalog_visibility( $draft->visibility );
		}

		if ( ! $draft->is_variable() ) {
			if ( $touch( 'sku' ) ) {
				$product->set_sku( (string) $draft->sku );
			}
			if ( $touch( 'regular_price' ) || $touch( 'sale_price' ) ) {
				$product->set_regular_price( $this->price( $draft->regular_price ) );
				$product->set_sale_price( $this->price( $draft->sale_price ) );
			}
			if ( $touch( 'quantity' ) || $touch( 'stock_status' ) ) {
				$this->stock( $product, $draft->quantity, $draft->stock_status );
			}
			$ean = $draft->meta['ean'] ?? null;
			if ( $is_new && is_string( $ean ) ) {
				$product->set_global_unique_id( $ean );
			}
		}

		$product->update_meta_data( ProductSnapshot::META_GROUP, $draft->group_key );
		$product->update_meta_data( ProductSnapshot::META_SUPPLIERS, (string) ( $draft->meta['suppliers'] ?? '' ) );
		if ( isset( $draft->meta['cost'] ) ) {
			$product->update_meta_data( ProductSnapshot::META_COST, (string) $draft->meta['cost'] );
		}

		$id = $product->save();

		if ( $draft->is_variable() && $touch( 'variations' ) ) {
			$this->variations( $id, $draft );
			\WC_Product_Variable::sync( $id );
		}

		return $id;
	}

	/**
	 * Takes a product off sale when no supplier offers it any more.
	 */
	public function unpublish( int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$product->set_status( 'draft' );
		if ( ! $product->is_type( 'variable' ) ) {
			$this->stock( $product, 0, AggregatedUnit::OUT_OF_STOCK );
		}
		$product->save();
	}

	/**
	 * @return list<\WC_Product_Attribute>
	 */
	private function product_attributes( ProductDraft $draft ): array {
		$result = [];
		foreach ( $draft->attributes as $position => $attribute ) {
			$taxonomy = $this->attributes->taxonomy( $attribute['name'] );
			if ( null === $taxonomy ) {
				continue;
			}

			$item = new \WC_Product_Attribute();
			$item->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			$item->set_name( $taxonomy );
			$item->set_options( $this->attributes->term_ids( $taxonomy, $attribute['values'] ) );
			$item->set_position( (int) $position );
			$item->set_visible( true );
			$item->set_variation( $attribute['variation'] );
			$result[] = $item;
		}
		return $result;
	}

	private function variations( int $parent_id, ProductDraft $draft ): void {
		$parent = wc_get_product( $parent_id );
		if ( ! $parent instanceof \WC_Product_Variable ) {
			return;
		}

		$existing = [];
		foreach ( $parent->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child instanceof \WC_Product_Variation ) {
				$key              = '' !== $child->get_sku() ? $child->get_sku() : (string) $child->get_meta( ProductSnapshot::META_UNIT );
				$existing[ $key ] = $child;
			}
		}

		$seen = [];
		foreach ( $draft->variations as $index => $variation_draft ) {
			$variation = $existing[ $variation_draft->key() ] ?? new \WC_Product_Variation();
			$seen[]    = $variation_draft->key();
			$this->fill_variation( $variation, $variation_draft, $parent_id, (int) $index );
		}

		// Variations the suppliers stopped offering are switched off, not deleted:
		// orders keep pointing at them.
		foreach ( $existing as $key => $variation ) {
			if ( ! in_array( (string) $key, $seen, true ) && 'private' !== $variation->get_status() ) {
				$variation->set_status( 'private' );
				$this->stock( $variation, 0, AggregatedUnit::OUT_OF_STOCK );
				$variation->save();
			}
		}
	}

	private function fill_variation( \WC_Product_Variation $variation, VariationDraft $draft, int $parent_id, int $menu_order ): void {
		$attributes = [];
		foreach ( $draft->attributes as $label => $value ) {
			$taxonomy = $this->attributes->taxonomy( $label );
			$term_id  = null !== $taxonomy ? $this->attributes->term_id( $taxonomy, $value ) : null;
			$term     = null !== $term_id ? get_term( $term_id ) : null;
			if ( null !== $taxonomy && $term instanceof \WP_Term ) {
				$attributes[ $taxonomy ] = $term->slug;
			}
		}

		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( $attributes );
		$variation->set_status( 'publish' );
		$variation->set_menu_order( $menu_order );
		if ( null !== $draft->sku && $variation->get_sku() !== $draft->sku ) {
			$variation->set_sku( $draft->sku );
		}
		$variation->set_regular_price( $this->price( $draft->regular_price ) );
		$variation->set_sale_price( $this->price( $draft->sale_price ) );
		$this->stock( $variation, $draft->quantity, $draft->stock_status );
		$variation->update_meta_data( ProductSnapshot::META_UNIT, $draft->unit_key );
		$variation->update_meta_data( ProductSnapshot::META_IMAGE, (string) $draft->image );
		if ( null !== $draft->ean && 0 === $variation->get_id() ) {
			$variation->set_global_unique_id( $draft->ean );
		}
		$variation->save();
	}

	private function stock( \WC_Product $product, ?int $quantity, string $status ): void {
		$product->set_manage_stock( null !== $quantity );
		$product->set_backorders( AggregatedUnit::ON_BACKORDER === $status ? 'notify' : 'no' );
		if ( null !== $quantity ) {
			$product->set_stock_quantity( $quantity );
		}
		$product->set_stock_status( $status );
	}

	private function price( ?float $price ): string {
		return null === $price ? '' : wc_format_decimal( $price, wc_get_price_decimals() );
	}
}
