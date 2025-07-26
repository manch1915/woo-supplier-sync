<?php
/**
 * Demo environment tweaks. Not part of the plugin.
 *
 * @package SupplierSyncDemo
 */

declare(strict_types=1);

// The made-up suppliers live in the "feeds" container on the private Docker network.
// wp_safe_remote_get() refuses private addresses, which is right for production.
add_filter(
	'http_request_host_is_external',
	static fn ( $external, $host ) => $external || 'feeds' === $host,
	10,
	2
);

// The demo account can look around and run syncs but not change feed sources or users.
add_action(
	'admin_menu',
	static function (): void {
		if ( current_user_can( 'manage_options' ) || ! current_user_can( 'supplier_sync_view' ) ) {
			return;
		}
		foreach ( [ 'index.php', 'edit.php', 'upload.php', 'edit-comments.php', 'tools.php', 'profile.php' ] as $page ) {
			remove_menu_page( $page );
		}
	},
	999
);

add_action(
	'admin_init',
	static function (): void {
		global $pagenow;
		if ( current_user_can( 'manage_options' ) || ! current_user_can( 'supplier_sync_view' ) || wp_doing_ajax() ) {
			return;
		}
		if ( 'index.php' === $pagenow ) {
			wp_safe_redirect( admin_url( 'admin.php?page=supplier-sync' ) );
			exit;
		}
	}
);

add_filter(
	'login_redirect',
	static function ( $redirect, $requested, $user ) {
		if ( $user instanceof WP_User && ! $user->has_cap( 'manage_options' ) && $user->has_cap( 'supplier_sync_view' ) ) {
			return admin_url( 'admin.php?page=supplier-sync' );
		}
		return $redirect;
	},
	10,
	3
);

// Show the demo credentials on the login screen.
add_filter(
	'login_message',
	static function ( $message ) {
		$hint = (string) getenv( 'DEMO_LOGIN_HINT' );
		if ( '' === $hint ) {
			return $message;
		}
		return $message . '<p class="message">' . esc_html( sprintf( 'Demo login: %s', $hint ) ) . '</p>';
	}
);

// The catalog template puts the core price slider into a 260px sidebar.
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_register_style( 'supplier-sync-demo', false, [], '1' );
		wp_enqueue_style( 'supplier-sync-demo' );
		wp_add_inline_style(
			'supplier-sync-demo',
			'.wss-demo-filters{min-width:0}.wss-demo-filters .wc-block-product-filter-price-slider__content{grid-template-columns:minmax(0,1fr) 0 minmax(0,1fr)}.wss-demo-filters .wc-block-product-filter-price-slider__content input:not([type=range]){width:100%;min-width:0;box-sizing:border-box}@media (max-width:781px){.wss-demo-filters{flex-basis:100%!important}}'
		);
	}
);

// Front-end tuning of the demo store (theme-level choices, not part of the plugin).
// The first product photos are the LCP element: load them eagerly and first.
add_filter(
	'render_block_woocommerce/product-image',
	static function ( string $html ): string {
		static $seen = 0;
		if ( is_admin() || ++$seen > 2 ) {
			return $html;
		}
		$html = str_replace( ' loading="lazy"', '', $html );
		return (string) preg_replace( '/<img /', '<img fetchpriority="high" ', $html, 1 );
	}
);

// The block theme styles the catalog itself; the classic WooCommerce sheets only block rendering.
add_filter(
	'woocommerce_enqueue_styles',
	static fn ( $styles ) => wp_is_block_theme() ? [] : $styles
);

add_action(
	'wp_head',
	static function (): void {
		if ( is_front_page() || is_shop() ) {
			echo '<meta name="description" content="' . esc_attr__( 'Northwind Supply is a demo store. Supplier Sync for WooCommerce fills and prices its catalog from three supplier feeds.', 'woo-supplier-sync' ) . '">' . "\n";
		}
	},
	1
);

// "Read more" on sold-out items says nothing about the product state.
add_filter(
	'woocommerce_product_add_to_cart_text',
	static fn ( string $text, $product ) => $product instanceof WC_Product && ! $product->is_in_stock() ? __( 'Out of stock', 'woocommerce' ) : $text,
	10,
	2
);
add_filter(
	'woocommerce_product_add_to_cart_description',
	static fn ( string $label, $product ) => $product instanceof WC_Product && ! $product->is_in_stock()
		/* translators: %s: product name */
		? sprintf( __( 'Out of stock: %s', 'woo-supplier-sync' ), $product->get_name() )
		: $label,
	10,
	2
);
