<?php
/**
 * Admin menu entry and the React app mount point.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Admin;

use SupplierSync\Infrastructure\Capabilities;

final class AdminPage {

	public const SLUG = 'supplier-sync';

	private string $hook = '';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( SUPPLIER_SYNC_FILE ), [ $this, 'action_links' ] );
		add_filter( 'woocommerce_prevent_admin_access', [ $this, 'allow_sync_operators' ] );
	}

	/**
	 * WooCommerce sends users without edit_posts to My Account. A role that only runs syncs
	 * (a warehouse operator, the demo account) still needs this one admin screen.
	 */
	public function allow_sync_operators( bool $prevent ): bool {
		return $prevent && ! current_user_can( Capabilities::VIEW );
	}

	public function menu(): void {
		$this->hook = (string) add_menu_page(
			__( 'Supplier Sync', 'woo-supplier-sync' ),
			__( 'Supplier Sync', 'woo-supplier-sync' ),
			Capabilities::VIEW,
			self::SLUG,
			[ $this, 'render' ],
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="none" stroke="black" stroke-width="1.8" stroke-linejoin="round" d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5v-9Z"/><path fill="none" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m8 12 3 3 5-6"/></svg>' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- inline menu icon.
			56
		);
	}

	public function render(): void {
		echo '<div class="wrap wss-wrap"><h1 class="screen-reader-text">' . esc_html__( 'Supplier Sync', 'woo-supplier-sync' ) . '</h1><div id="supplier-sync-root"></div></div>';
	}

	public function assets( string $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}

		$asset_file = SUPPLIER_SYNC_DIR . 'assets/build/admin/admin.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Supplier Sync assets are not built. Run npm run build in the plugin folder.', 'woo-supplier-sync' ) . '</p></div>';
				}
			);
			return;
		}

		$asset = require $asset_file;
		$url   = plugins_url( 'assets/build/admin/', SUPPLIER_SYNC_FILE );

		wp_enqueue_script( 'supplier-sync-admin', $url . 'admin.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'supplier-sync-admin', 'woo-supplier-sync', SUPPLIER_SYNC_DIR . 'languages' );
		// Dates and numbers follow the WordPress user locale, not the browser.
		wp_add_inline_script( 'supplier-sync-admin', 'window.supplierSyncLocale = ' . wp_json_encode( str_replace( '_', '-', get_user_locale() ) ) . ';', 'before' );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'supplier-sync-admin', $url . 'style-admin.css', [ 'wp-components' ], $asset['version'] );
	}

	/**
	 * @param array<int|string, string> $links Plugin row links.
	 * @return array<int|string, string>
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ), esc_html__( 'Open', 'woo-supplier-sync' ) )
		);
		return $links;
	}
}
