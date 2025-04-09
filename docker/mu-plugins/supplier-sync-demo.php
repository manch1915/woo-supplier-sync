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
		if ( 'demo' !== getenv( 'DEMO_LOGIN_HINT' ) && 'local' !== wp_get_environment_type() ) {
			return $message;
		}
		return $message . '<p class="message">Demo login: <strong>demo</strong> / <strong>demo</strong></p>';
	}
);
