<?php
/**
 * Downloads supplier images into the media library, once.
 *
 * Deduplication works on two levels: the same URL is never downloaded twice, and two URLs
 * with identical bytes share one attachment. Images are resized and stored as WebP.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure\Woo;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table.

use SupplierSync\Infrastructure\Schema;
use SupplierSync\Infrastructure\Settings;

final class ImageImporter {

	public const HOOK = 'supplier_sync_import_images';

	/**
	 * @var array<string, int>
	 */
	private array $stats = [
		'downloaded' => 0,
		'reused'     => 0,
		'failed'     => 0,
	];

	public function __construct(
		private readonly \wpdb $db,
		private readonly Settings $settings,
	) {}

	public static function schedule( int $product_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, [ $product_id ], 'supplier-sync', true );
		}
	}

	public function import_for_product( int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$this->load_wp_admin_media();

		$urls = $product->get_meta( ProductSnapshot::META_IMAGES );
		$urls = is_array( $urls ) ? array_values( array_filter( array_map( 'strval', $urls ) ) ) : [];
		$done = $product->get_meta( ProductSnapshot::META_IMAGES_DONE );

		if ( $urls !== $done ) {
			$ids = [];
			foreach ( $urls as $url ) {
				$id = $this->attachment_for( $url, $product_id, $product->get_name() );
				if ( null !== $id ) {
					$ids[] = $id;
				}
			}
			$ids = array_values( array_unique( $ids ) );

			$product->set_image_id( $ids[0] ?? 0 );
			$product->set_gallery_image_ids( array_slice( $ids, 1 ) );
			$product->update_meta_data( ProductSnapshot::META_IMAGES_DONE, $urls );
			$product->save();
		}

		if ( $product instanceof \WC_Product_Variable ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				$url       = $variation instanceof \WC_Product ? (string) $variation->get_meta( ProductSnapshot::META_IMAGE ) : '';
				if ( '' === $url || ! $variation instanceof \WC_Product ) {
					continue;
				}
				$id = $this->attachment_for( $url, $product_id, $variation->get_name() );
				if ( null !== $id && $variation->get_image_id() !== $id ) {
					$variation->set_image_id( $id );
					$variation->save();
				}
			}
		}
	}

	/**
	 * @return array<string, int>
	 */
	public function stats(): array {
		return $this->stats;
	}

	private function attachment_for( string $url, int $parent_id, string $title ): ?int {
		$url_hash = sha1( $url );
		$known    = $this->db->get_var( $this->db->prepare( 'SELECT attachment_id FROM %i WHERE url_hash = %s', Schema::table( 'images' ), $url_hash ) );
		if ( null !== $known && wp_attachment_is_image( (int) $known ) ) {
			++$this->stats['reused'];
			return (int) $known;
		}

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			++$this->stats['failed'];
			return null;
		}

		$content_hash = (string) sha1_file( $tmp );
		$same_bytes   = $this->db->get_var( $this->db->prepare( 'SELECT attachment_id FROM %i WHERE content_hash = %s LIMIT 1', Schema::table( 'images' ), $content_hash ) );
		if ( null !== $same_bytes && wp_attachment_is_image( (int) $same_bytes ) ) {
			wp_delete_file( $tmp );
			$this->remember( $url_hash, (int) $same_bytes, $content_hash );
			++$this->stats['reused'];
			return (int) $same_bytes;
		}

		$file = $this->prepare_file( $tmp, $url );
		$id   = media_handle_sideload( $file, $parent_id, $title );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $file['tmp_name'] );
			++$this->stats['failed'];
			return null;
		}

		update_post_meta( $id, '_supplier_sync_source_url', esc_url_raw( $url ) );
		$this->remember( $url_hash, $id, $content_hash );
		++$this->stats['downloaded'];
		return $id;
	}

	/**
	 * Resizes and converts to WebP when the server can, otherwise keeps the original.
	 *
	 * @return array{name: string, tmp_name: string}
	 */
	private function prepare_file( string $tmp, string $url ): array {
		$name     = sanitize_file_name( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME ) );
		$name     = '' !== $name ? $name : 'supplier-image';
		$settings = (array) $this->settings->get( 'images' );
		$editor   = wp_get_image_editor( $tmp );

		if ( is_wp_error( $editor ) ) {
			return [
				'name'     => $name . '.' . $this->extension( $tmp ),
				'tmp_name' => $tmp,
			];
		}

		$max = (int) ( $settings['max_width'] ?? 1600 );
		if ( $max > 0 ) {
			$editor->resize( $max, $max );
		}

		$webp = ! empty( $settings['webp'] ) && wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] );
		$editor->set_quality( (int) ( $settings['quality'] ?? 82 ) );
		$saved = $editor->save( $tmp . ( $webp ? '.webp' : '.' . $this->extension( $tmp ) ), $webp ? 'image/webp' : null );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return [
				'name'     => $name . '.' . $this->extension( $tmp ),
				'tmp_name' => $tmp,
			];
		}

		wp_delete_file( $tmp );
		return [
			'name'     => $name . '.' . ( $webp ? 'webp' : pathinfo( (string) $saved['path'], PATHINFO_EXTENSION ) ),
			'tmp_name' => (string) $saved['path'],
		];
	}

	private function extension( string $path ): string {
		$type = wp_get_image_mime( $path );
		return match ( $type ) {
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
			default      => 'jpg',
		};
	}

	private function remember( string $url_hash, int $attachment_id, string $content_hash ): void {
		$this->db->replace(
			Schema::table( 'images' ),
			[
				'url_hash'      => $url_hash,
				'attachment_id' => $attachment_id,
				'content_hash'  => $content_hash,
			]
		);
	}

	private function load_wp_admin_media(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}
