<?php
/**
 * Demo only: points a supplier profile at another feed URL.
 * Usage: wp eval-file /demo/set-feed-url.php "Nordline" "http://feeds/nordline/catalog.yml"
 *
 * @package SupplierSyncDemo
 */

declare(strict_types=1);

use SupplierSync\Domain\Supplier;
use SupplierSync\Infrastructure\Persistence\SupplierRepository;
use SupplierSync\Plugin;

[ $supplier_sync_prefix, $supplier_sync_url ] = $args; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WP-CLI passes $args.

$supplier_sync_repo = Plugin::container()->get( SupplierRepository::class );
foreach ( $supplier_sync_repo->all() as $supplier_sync_supplier ) {
	if ( str_starts_with( $supplier_sync_supplier->name, $supplier_sync_prefix ) ) {
		$supplier_sync_config                  = $supplier_sync_supplier->config;
		$supplier_sync_config['source']['url'] = $supplier_sync_url;
		$supplier_sync_repo->save(
			new Supplier( $supplier_sync_supplier->id, $supplier_sync_supplier->name, $supplier_sync_supplier->type, $supplier_sync_config, $supplier_sync_supplier->schedule, $supplier_sync_supplier->enabled )
		);
		WP_CLI::log( "{$supplier_sync_supplier->name}: $supplier_sync_url" );
	}
}
