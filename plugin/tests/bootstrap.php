<?php
/**
 * PHPUnit bootstrap. Unit tests run on plain PHP, integration tests load the WordPress test suite.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$wss_is_integration = in_array( '--testsuite=integration', $_SERVER['argv'] ?? [], true )
	|| false !== array_search( 'integration', $_SERVER['argv'] ?? [], true );

if ( ! $wss_is_integration ) {
	return;
}

$wss_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

require_once $wss_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$plugins_dir = getenv( 'WP_PLUGINS_DIR' ) ? getenv( 'WP_PLUGINS_DIR' ) : '/var/www/html/wp-content/plugins';
		require_once $plugins_dir . '/woocommerce/woocommerce.php';
		require_once dirname( __DIR__ ) . '/woo-supplier-sync.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		\WC_Install::install();
		\SupplierSync\Infrastructure\Schema::install();
	}
);

// Action Scheduler creates its tables on first use. If that happens inside a test, the DDL
// commits the test transaction and later tests see leftovers, so create them up front.
tests_add_filter(
	'init',
	static function (): void {
		as_enqueue_async_action( 'supplier_sync_tests_warmup' );
		as_unschedule_all_actions( 'supplier_sync_tests_warmup' );
	},
	99
);

require $wss_tests_dir . '/includes/bootstrap.php';
