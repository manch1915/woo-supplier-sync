<?php
/**
 * Private folder for downloaded feeds. Feeds contain purchase prices, so the folder
 * is closed to the web.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure\Http;

final class FeedStorage {

	public function dir(): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'supplier-sync';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny guard files.
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			// phpcs:enable
		}

		return $dir;
	}

	public function path_for_run( int $run_id, string $extension ): string {
		return $this->dir() . '/run-' . $run_id . '-' . wp_generate_password( 12, false ) . '.' . $extension;
	}

	public function delete( string $path ): void {
		if ( '' !== $path && str_starts_with( wp_normalize_path( $path ), wp_normalize_path( $this->dir() ) ) && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
