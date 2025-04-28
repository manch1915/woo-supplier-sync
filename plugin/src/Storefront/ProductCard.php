<?php
/**
 * Product data for the comparison table.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Storefront;

final class ProductCard {

	/**
	 * @return array<string, mixed>
	 */
	public static function from( \WC_Product $product ): array {
		$image = wp_get_attachment_image_src( (int) $product->get_image_id(), 'woocommerce_thumbnail' );
		$specs = [];

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_visible() ) {
				continue;
			}
			$label = $attribute->is_taxonomy() ? wc_attribute_label( $attribute->get_name() ) : $attribute->get_name();
			$value = $attribute->is_taxonomy()
				? implode( ', ', wp_list_pluck( $attribute->get_terms() ?? [], 'name' ) )
				: implode( ', ', $attribute->get_options() );

			$specs[ html_entity_decode( $label ) ] = html_entity_decode( $value );
		}

		return [
			'id'           => $product->get_id(),
			'name'         => html_entity_decode( $product->get_name() ),
			'url'          => $product->get_permalink(),
			'image'        => is_array( $image ) ? $image[0] : wc_placeholder_img_src( 'woocommerce_thumbnail' ),
			'price'        => self::price( $product ),
			'availability' => self::availability( $product ),
			'sku'          => $product->get_sku(),
			'specs'        => $specs,
		];
	}

	/**
	 * Plain text: "$149.99, was $173.99" or "$24.99 – $29.99" for variable products.
	 */
	private static function price( \WC_Product $product ): string {
		$format = static fn ( $amount ): string => html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ) );

		if ( $product instanceof \WC_Product_Variable ) {
			$min = $product->get_variation_price( 'min', true );
			$max = $product->get_variation_price( 'max', true );
			return $min === $max ? $format( $min ) : $format( $min ) . ' – ' . $format( $max );
		}

		$price = wc_get_price_to_display( $product );
		if ( $product->is_on_sale() && '' !== $product->get_regular_price() ) {
			/* translators: 1: sale price, 2: regular price. */
			return sprintf( __( '%1$s, was %2$s', 'woo-supplier-sync' ), $format( $price ), $format( wc_get_price_to_display( $product, [ 'price' => $product->get_regular_price() ] ) ) );
		}
		return '' === $product->get_price() ? '—' : $format( $price );
	}

	private static function availability( \WC_Product $product ): string {
		return match ( $product->get_stock_status() ) {
			'instock'     => __( 'In stock', 'woo-supplier-sync' ),
			'onbackorder' => __( 'On order', 'woo-supplier-sync' ),
			default       => __( 'Out of stock', 'woo-supplier-sync' ),
		};
	}
}
