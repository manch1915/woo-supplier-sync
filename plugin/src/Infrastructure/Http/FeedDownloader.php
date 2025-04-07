<?php
/**
 * Streams a supplier feed to disk, so a 300 MB price list never sits in PHP memory.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure\Http;

use SupplierSync\Domain\Supplier;
use SupplierSync\Feed\FeedException;

final class FeedDownloader {

	private const MAX_BYTES = 536870912;

	private const EXTENSIONS = [
		'yml'  => 'xml',
		'xml'  => 'xml',
		'csv'  => 'csv',
		'xlsx' => 'xlsx',
		'json' => 'json',
	];

	public function __construct( private readonly FeedStorage $storage ) {}

	/**
	 * @return string Local file path.
	 *
	 * @throws FeedException When the source cannot be fetched.
	 */
	public function download( Supplier $supplier, int $run_id ): string {
		$path   = $this->storage->path_for_run( $run_id, self::EXTENSIONS[ $supplier->type ] ?? 'dat' );
		$source = (array) ( $supplier->config['source'] ?? [] );

		$attachment_id = (int) ( $source['attachment_id'] ?? 0 );
		if ( $attachment_id > 0 ) {
			$file = get_attached_file( $attachment_id );
			if ( false === $file || ! is_readable( $file ) || ! copy( $file, $path ) ) {
				throw new FeedException( __( 'The uploaded price list file is missing.', 'woo-supplier-sync' ) );
			}
			return $path;
		}

		$url = $supplier->source_url();
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			throw new FeedException( __( 'The feed URL is empty or not allowed.', 'woo-supplier-sync' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => 120,
				'stream'              => true,
				'filename'            => $path,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'SupplierSync/' . SUPPLIER_SYNC_VERSION . '; ' . home_url( '/' ),
				'headers'             => $this->headers( $source ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->storage->delete( $path );
			/* translators: %s: error message from the HTTP client. */
			throw new FeedException( sprintf( __( 'Download failed: %s', 'woo-supplier-sync' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->storage->delete( $path );
			/* translators: %d: HTTP status code. */
			throw new FeedException( sprintf( __( 'The supplier server answered with HTTP %d.', 'woo-supplier-sync' ), $code ) );
		}

		if ( ! file_exists( $path ) || 0 === filesize( $path ) ) {
			throw new FeedException( __( 'The feed is empty.', 'woo-supplier-sync' ) );
		}

		return $path;
	}

	/**
	 * @param array<string, mixed> $source Source config with optional headers (API keys).
	 * @return array<string, string>
	 */
	private function headers( array $source ): array {
		$headers = [ 'Accept-Encoding' => 'identity' ];
		foreach ( (array) ( $source['headers'] ?? [] ) as $name => $value ) {
			$name = preg_replace( '/[^A-Za-z0-9-]/', '', (string) $name );
			if ( '' !== $name ) {
				$headers[ $name ] = (string) $value;
			}
		}
		return $headers;
	}
}
