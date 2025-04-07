<?php
/**
 * Crawls a supplier website: listing pages -> product pages -> JSON Lines records.
 *
 * Works in slices within a time budget and keeps its queue in the run state, so a crawl
 * of a few thousand pages survives PHP time limits. Stays on the start host, honours
 * robots.txt and waits between requests.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure\Http;

use SupplierSync\Domain\Supplier;
use SupplierSync\Feed\FeedException;
use SupplierSync\Feed\Scraper\PageParser;

final class SiteCrawler {

	/**
	 * @param Supplier             $supplier Profile with scraper settings.
	 * @param array<string, mixed> $state    Crawl state from the previous slice, empty on start.
	 * @param string               $file     JSON Lines output.
	 * @param float                $deadline Unix time to stop at.
	 * @return array<string, mixed> New state; "done" => true when finished.
	 *
	 * @throws FeedException When the start page cannot be fetched.
	 */
	public function step( Supplier $supplier, array $state, string $file, float $deadline ): array {
		$config = (array) ( $supplier->config['scraper'] ?? [] );
		$parser = new PageParser( array_map( 'strval', (array) ( $config['selectors'] ?? [] ) ) );

		if ( [] === $state ) {
			$start = array_values( array_filter( array_map( 'strval', (array) ( $config['start_urls'] ?? [ $supplier->source_url() ] ) ) ) );
			if ( [] === $start ) {
				throw new FeedException( __( 'The scraper has no start URL.', 'woo-supplier-sync' ) );
			}
			$state = [
				'listings'      => $start,
				'products'      => [],
				'seen'          => [],
				'pages'         => 0,
				'scraped'       => 0,
				'failed'        => 0,
				'host'          => (string) wp_parse_url( $start[0], PHP_URL_HOST ),
				'disallow'      => $this->robots_rules( $start[0] ),
				'done'          => false,
				'last_request'  => 0.0,
				'start_failure' => true,
			];
		}

		$max_pages    = (int) ( $config['max_pages'] ?? 50 );
		$max_products = (int) ( $config['max_products'] ?? 5000 );
		$delay        = max( 0, (int) ( $config['delay_ms'] ?? 300 ) ) / 1000;

		while ( microtime( true ) < $deadline ) {
			if ( [] !== $state['products'] && $state['scraped'] < $max_products ) {
				$url  = (string) array_shift( $state['products'] );
				$html = $this->get( $url, $state, $delay );
				if ( null === $html ) {
					++$state['failed'];
					continue;
				}
				$record = $parser->product( $html, $url );
				file_put_contents( $file, wp_json_encode( $record ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				++$state['scraped'];
				continue;
			}

			if ( [] !== $state['listings'] && $state['pages'] < $max_pages ) {
				$url  = (string) array_shift( $state['listings'] );
				$html = $this->get( $url, $state, $delay );
				++$state['pages'];
				if ( null === $html ) {
					if ( $state['start_failure'] ) {
						throw new FeedException( sprintf( /* translators: %s: URL. */ __( 'Cannot open the start page %s.', 'woo-supplier-sync' ), $url ) );
					}
					continue;
				}
				$state['start_failure'] = false;

				$listing = $parser->listing( $html, $url );
				foreach ( $listing['products'] as $product_url ) {
					if ( $this->allowed( $product_url, $state ) && ! isset( $state['seen'][ $product_url ] ) ) {
						$state['seen'][ $product_url ] = true;
						$state['products'][]           = $product_url;
					}
				}
				if ( null !== $listing['next'] && $this->allowed( $listing['next'], $state ) && ! isset( $state['seen'][ $listing['next'] ] ) ) {
					$state['seen'][ $listing['next'] ] = true;
					$state['listings'][]               = $listing['next'];
				}
				continue;
			}

			$state['done'] = true;
			break;
		}

		return $state;
	}

	/**
	 * @param string               $url   Page URL.
	 * @param array<string, mixed> $state Crawl state, last_request is updated.
	 * @param float                $delay Seconds between requests.
	 */
	private function get( string $url, array &$state, float $delay ): ?string {
		$wait = (float) $state['last_request'] + $delay - microtime( true );
		if ( $wait > 0 ) {
			usleep( (int) ( $wait * 1000000 ) );
		}
		$state['last_request'] = microtime( true );

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => 20,
				'limit_response_size' => 5 * MB_IN_BYTES,
				'user-agent'          => 'SupplierSync/' . SUPPLIER_SYNC_VERSION . ' (catalog sync; ' . home_url( '/' ) . ')',
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * @param string               $url   Candidate URL.
	 * @param array<string, mixed> $state Crawl state with host and robots rules.
	 */
	private function allowed( string $url, array $state ): bool {
		if ( (string) wp_parse_url( $url, PHP_URL_HOST ) !== $state['host'] ) {
			return false;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		foreach ( (array) $state['disallow'] as $rule ) {
			if ( '' !== $rule && str_starts_with( $path, (string) $rule ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Disallow rules for "User-agent: *". Enough for polite crawling of a supplier catalog.
	 *
	 * @return list<string>
	 */
	private function robots_rules( string $start_url ): array {
		$parts = wp_parse_url( $start_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return [];
		}
		$robots   = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/robots.txt';
		$response = wp_safe_remote_get( $robots, [ 'timeout' => 10 ] );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$rules   = [];
		$applies = false;
		$lines   = preg_split( '/\r?\n/', (string) wp_remote_retrieve_body( $response ) );
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) preg_replace( '/#.*/', '', (string) $line ) );
			if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
				$applies = '*' === trim( $m[1] );
			} elseif ( $applies && preg_match( '/^disallow:\s*(.*)$/i', $line, $m ) ) {
				$rules[] = trim( $m[1] );
			}
		}
		return array_values( array_filter( $rules ) );
	}
}
