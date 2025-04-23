<?php
/**
 * Plugin Name:       Supplier Sync for WooCommerce
 * Plugin URI:        https://github.com/manch1915/woo-supplier-sync
 * Description:       Keeps the catalog in sync with supplier feeds: YML, CSV, XLSX, JSON API and supplier websites. Markup rules, stock from several suppliers, WebP images, dry runs and a change log.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Requires Plugins:  woocommerce
 * Author:            Levon Oganesian
 * Author URI:        https://narcisbin.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-supplier-sync
 * Domain Path:       /languages
 * WC requires at least: 9.2
 * WC tested up to:   11.2
 *
 * @package SupplierSync
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'SUPPLIER_SYNC_VERSION', '1.0.0' );
define( 'SUPPLIER_SYNC_FILE', __FILE__ );
define( 'SUPPLIER_SYNC_DIR', plugin_dir_path( __FILE__ ) );

// No runtime dependencies, so a tiny PSR-4 loader is enough. vendor/ holds dev tools only.
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, 'SupplierSync\\' ) ) {
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, 13 ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', __FILE__, true );
		}
	}
);

register_activation_hook( __FILE__, [ \SupplierSync\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \SupplierSync\Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ \SupplierSync\Plugin::class, 'boot' ], 20 );
