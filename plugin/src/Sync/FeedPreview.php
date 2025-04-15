<?php
/**
 * First rows of a feed with the current (possibly unsaved) mapping. Lets a manager set up
 * columns and selectors and see the result before the first run.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Sync;

use SupplierSync\Domain\Catalog\AttributeMap;
use SupplierSync\Domain\Offer;
use SupplierSync\Domain\Supplier;
use SupplierSync\Feed\ReaderFactory;
use SupplierSync\Feed\RowError;
use SupplierSync\Feed\TabularSource;
use SupplierSync\Infrastructure\Http\FeedDownloader;
use SupplierSync\Infrastructure\Http\FeedStorage;
use SupplierSync\Infrastructure\Http\SiteCrawler;
use SupplierSync\Infrastructure\Settings;

final class FeedPreview {

	private const ROWS = 12;

	public function __construct(
		private readonly ReaderFactory $readers,
		private readonly FeedDownloader $downloader,
		private readonly FeedStorage $storage,
		private readonly SiteCrawler $crawler,
		private readonly Settings $settings,
	) {}

	/**
	 * @return array{columns: list<string>, rows: list<array<string, mixed>>, offers: list<array<string, mixed>>, errors: list<array{reference: string, message: string}>}
	 *
	 * @throws \SupplierSync\Feed\FeedException When the feed cannot be fetched or read.
	 */
	public function preview( Supplier $supplier ): array {
		$file = '';
		try {
			if ( 'site' === $supplier->type ) {
				$config                            = $supplier->config;
				$config['scraper']['max_products'] = 4;
				$config['scraper']['max_pages']    = 1;
				$config['scraper']['delay_ms']     = 100;
				$supplier                          = new Supplier( $supplier->id, $supplier->name, $supplier->type, $config );

				$file = $this->storage->path_for_run( 0, 'jsonl' );
				touch( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- private temp file.
				$this->crawler->step( $supplier, [], $file, microtime( true ) + 15 );
			} else {
				$file = $this->downloader->download( $supplier, 0 );
			}

			return $this->read( $supplier, $file );
		} finally {
			$this->storage->delete( $file );
		}
	}

	/**
	 * @return array{columns: list<string>, rows: list<array<string, mixed>>, offers: list<array<string, mixed>>, errors: list<array{reference: string, message: string}>}
	 */
	private function read( Supplier $supplier, string $file ): array {
		$reader = $this->readers->create( $supplier->type, $supplier->id, $supplier->config );

		$rows    = [];
		$columns = [];
		if ( $reader instanceof TabularSource ) {
			foreach ( $reader->rows( $file ) as $row ) {
				$rows[]  = array_map( static fn ( $v ) => is_array( $v ) ? implode( ' | ', array_map( 'strval', $v ) ) : $v, $row );
				$columns = array_values( array_unique( array_merge( $columns, array_map( 'strval', array_keys( $row ) ) ) ) );
				if ( count( $rows ) >= self::ROWS ) {
					break;
				}
			}
		}

		$map        = new AttributeMap( $supplier->attribute_map(), 'skip' !== ( $supplier->config['unmapped_attributes'] ?? 'keep' ) );
		$calculator = $this->settings->calculator();
		$offers     = [];
		$errors     = [];
		foreach ( $reader->read( $file ) as $item ) {
			if ( $item instanceof RowError ) {
				$errors[] = [
					'reference' => $item->reference,
					'message'   => $item->message,
				];
			} else {
				$offers[] = $this->describe( $map->apply( $item ), $calculator, $supplier );
			}
			if ( count( $offers ) + count( $errors ) >= self::ROWS ) {
				break;
			}
		}

		return [
			'columns' => $columns,
			'rows'    => $rows,
			'offers'  => $offers,
			'errors'  => $errors,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function describe( Offer $offer, \SupplierSync\Domain\Pricing\PriceCalculator $calculator, Supplier $supplier ): array {
		$data = $offer->to_array();
		unset( $data['description'], $data['supplier_id'] );
		$data['unit_key'] = $offer->unit_key();

		try {
			$result             = null !== $offer->price ? $calculator->calculate( $offer->price, $offer->currency, $supplier->id, [], $offer->old_price ) : null;
			$data['retail']     = $result?->final_price();
			$data['price_rule'] = $result?->rule_id;
		} catch ( \SupplierSync\Domain\Pricing\PricingException $e ) {
			$data['retail']      = null;
			$data['price_error'] = $e->getMessage();
		}
		return $data;
	}
}
