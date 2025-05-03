<?php
/**
 * Storefront: "Compare" toggles on product cards and a comparison tray.
 *
 * Block themes get the toggle through Block Hooks right after the Add to cart button,
 * so no template has to be edited. Classic themes get it through WooCommerce actions.
 * Filtering uses the core Product Filters blocks on top of the global attributes the
 * importer creates.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Storefront;

use WP_REST_Request;
use WP_REST_Response;

final class Storefront {

	public const STORE = 'supplier-sync/compare';
	public const LIMIT = 4;

	private bool $bar_needed = false;

	public function register(): void {
		add_action( 'init', [ $this, 'blocks' ] );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'woocommerce_after_shop_loop_item', [ $this, 'classic_toggle' ], 20 );
		add_action( 'woocommerce_single_product_summary', [ $this, 'classic_toggle' ], 35 );
		add_action( 'wp_footer', [ $this, 'footer_bar' ], 5 );
		add_filter( 'render_block_supplier-sync/compare-button', [ $this, 'mark_bar_needed' ] );
	}

	public function blocks(): void {
		foreach ( [ 'compare-button', 'compare-bar' ] as $block ) {
			$dir = SUPPLIER_SYNC_DIR . 'assets/build/blocks/' . $block;
			if ( is_readable( $dir . '/block.json' ) ) {
				register_block_type( $dir );
			}
		}
	}

	/**
	 * Remembers that the page has toggles, so the tray is printed in the footer.
	 */
	public function mark_bar_needed( string $content ): string {
		if ( '' !== $content ) {
			$this->bar_needed = true;
		}
		return $content;
	}

	public function classic_toggle(): void {
		if ( wp_is_block_theme() ) {
			return;
		}
		echo render_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block markup is escaped in render.php.
			[
				'blockName' => 'supplier-sync/compare-button',
				'attrs'     => [],
			]
		);
	}

	public function footer_bar(): void {
		if ( ! $this->bar_needed || is_admin() ) {
			return;
		}
		echo render_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block markup is escaped in render.php.
			[
				'blockName' => 'supplier-sync/compare-bar',
				'attrs'     => [],
			]
		);
	}

	public function routes(): void {
		register_rest_route(
			'supplier-sync/v1',
			'/compare',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'compare' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'ids' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Public data for up to four published products: what a shopper already sees on the product pages.
	 */
	public function compare( WP_REST_Request $request ): WP_REST_Response {
		$ids      = array_slice( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $request['ids'] ) ) ) ), 0, self::LIMIT );
		$products = [];

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() || ! $product->is_visible() || post_password_required( $product->get_id() ) ) {
				continue;
			}
			$products[] = ProductCard::from( $product );
		}

		$response = new WP_REST_Response( $products );
		$response->header( 'Cache-Control', 'public, max-age=60' );
		return $response;
	}
}
