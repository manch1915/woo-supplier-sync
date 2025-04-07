<?php
/**
 * Custom tables. Offers, runs and logs grow to hundreds of thousands of rows,
 * which postmeta handles badly.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure;

final class Schema {

	public const VERSION = '1.0.0';
	public const OPTION  = 'supplier_sync_db_version';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'supplier_sync_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table( 'suppliers' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				type varchar(16) NOT NULL,
				config longtext NOT NULL,
				schedule varchar(32) NOT NULL DEFAULT 'manual',
				enabled tinyint(1) NOT NULL DEFAULT 1,
				pricing_hash char(32) NOT NULL DEFAULT '',
				last_run_id bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'offers' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				supplier_id bigint(20) unsigned NOT NULL,
				unit_key varchar(160) NOT NULL,
				group_key varchar(160) NOT NULL,
				hash char(32) NOT NULL,
				payload longtext NOT NULL,
				seen_run_id bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY supplier_unit (supplier_id,unit_key),
				KEY unit_key (unit_key),
				KEY group_key (group_key)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'staging' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				unit_key varchar(160) NOT NULL,
				group_key varchar(160) NOT NULL,
				hash char(32) NOT NULL,
				payload longtext NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_unit (run_id,unit_key),
				KEY run_group (run_id,group_key)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'runs' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				supplier_id bigint(20) unsigned NOT NULL,
				dry_run tinyint(1) NOT NULL DEFAULT 0,
				source varchar(16) NOT NULL DEFAULT 'manual',
				status varchar(16) NOT NULL DEFAULT 'queued',
				phase varchar(16) NOT NULL DEFAULT 'fetch',
				state longtext NULL,
				stats longtext NULL,
				error text NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				started_at datetime DEFAULT NULL,
				finished_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY supplier_id (supplier_id)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'run_items' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				group_key varchar(160) NOT NULL DEFAULT '',
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(16) NOT NULL,
				title varchar(255) NOT NULL DEFAULT '',
				message text NULL,
				changes longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY run_action (run_id,action)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'products' ) . " (
				group_key varchar(160) NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (group_key),
				KEY product_id (product_id)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'images' ) . " (
				url_hash char(40) NOT NULL,
				attachment_id bigint(20) unsigned NOT NULL,
				content_hash char(40) NOT NULL DEFAULT '',
				PRIMARY KEY  (url_hash),
				KEY content_hash (content_hash)
			) $charset;"
		);

		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function drop(): void {
		global $wpdb;
		foreach ( [ 'suppliers', 'offers', 'staging', 'runs', 'run_items', 'products', 'images' ] as $name ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		delete_option( self::OPTION );
	}
}
