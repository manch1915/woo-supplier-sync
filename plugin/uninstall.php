<?php
/**
 * Removes the plugin's own data. Products, categories, attributes and photos it created
 * stay in the store: they belong to the shop now.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Infrastructure/Schema.php';
require_once __DIR__ . '/src/Infrastructure/Capabilities.php';

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', [], 'supplier-sync' );
}

SupplierSync\Infrastructure\Schema::drop();
SupplierSync\Infrastructure\Capabilities::revoke();
delete_option( 'supplier_sync_settings' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of run locks.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'supplier_sync_lock_' ) . '%' ) );

$supplier_sync_uploads = wp_upload_dir( null, false );
$supplier_sync_dir     = trailingslashit( $supplier_sync_uploads['basedir'] ) . 'supplier-sync';
if ( is_dir( $supplier_sync_dir ) ) {
	foreach ( (array) glob( $supplier_sync_dir . '/{,.}*', GLOB_BRACE ) as $supplier_sync_file ) {
		if ( is_string( $supplier_sync_file ) && is_file( $supplier_sync_file ) ) {
			wp_delete_file( $supplier_sync_file );
		}
	}
	rmdir( $supplier_sync_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
