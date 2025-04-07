<?php
/**
 * Plugin capabilities. Split so a content manager can run syncs without touching
 * feed URLs, API keys or pricing.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure;

final class Capabilities {

	public const VIEW   = 'supplier_sync_view';
	public const RUN    = 'supplier_sync_run';
	public const MANAGE = 'supplier_sync_manage';

	public static function grant(): void {
		foreach ( [ 'administrator', 'shop_manager' ] as $role_name ) {
			$role = get_role( $role_name );
			if ( null === $role ) {
				continue;
			}
			foreach ( [ self::VIEW, self::RUN, self::MANAGE ] as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	public static function revoke(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( [ self::VIEW, self::RUN, self::MANAGE ] as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
