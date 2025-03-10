<?php
/**
 * Plugin entry point: wires services to WordPress hooks.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync;

final class Plugin {

	public static function boot(): void {
		if ( ! class_exists( \WooCommerce::class ) ) {
			return;
		}

		load_plugin_textdomain( 'woo-supplier-sync', false, dirname( plugin_basename( SUPPLIER_SYNC_FILE ) ) . '/languages' );
	}

	public static function activate(): void {
	}

	public static function deactivate(): void {
	}
}
