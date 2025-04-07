<?php
/**
 * Executes runs as a chain of short Action Scheduler jobs.
 *
 * Each step works within a time budget and saves its cursor, then queues the next step.
 * That is how a 50k-offer feed gets through shared hosting limits without timeouts.
 *
 * fetch  download the feed or crawl the site into a local file
 * parse  stream the file into the staging table
 * apply  merge offers per product, price them, diff, write (or only log in a dry run)
 * sweep  products whose offers vanished from the feed
 * finish counters, notification, cleanup, next queued run
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Sync;

use SupplierSync\Domain\Catalog\AttributeMap;
use SupplierSync\Domain\Catalog\ChangeSet;
use SupplierSync\Domain\Catalog\Differ;
use SupplierSync\Domain\Catalog\DraftBuilder;
use SupplierSync\Domain\Catalog\DraftException;
use SupplierSync\Domain\Catalog\OfferAggregator;
use SupplierSync\Domain\Offer;
use SupplierSync\Domain\Supplier;
use SupplierSync\Feed\FeedException;
use SupplierSync\Feed\ReaderFactory;
use SupplierSync\Feed\RowError;
use SupplierSync\Infrastructure\Http\FeedDownloader;
use SupplierSync\Infrastructure\Http\FeedStorage;
use SupplierSync\Infrastructure\Http\SiteCrawler;
use SupplierSync\Infrastructure\Persistence\OfferRepository;
use SupplierSync\Infrastructure\Persistence\ProductMapRepository;
use SupplierSync\Infrastructure\Persistence\RunRepository;
use SupplierSync\Infrastructure\Persistence\SupplierRepository;
use SupplierSync\Infrastructure\Settings;
use SupplierSync\Infrastructure\Woo\CategoryResolver;
use SupplierSync\Infrastructure\Woo\ImageImporter;
use SupplierSync\Infrastructure\Woo\ProductSnapshot;
use SupplierSync\Infrastructure\Woo\ProductWriter;
use SupplierSync\Notify\Notifier;

final class SyncRunner {

	public const HOOK = 'supplier_sync_run_step';

	private const PARSE_BATCH = 250;
	private const APPLY_BATCH = 25;
	private const SWEEP_BATCH = 100;

	private float $deadline = 0.0;

	public function __construct(
		private readonly RunRepository $runs,
		private readonly SupplierRepository $suppliers,
		private readonly OfferRepository $offers,
		private readonly ProductMapRepository $product_map,
		private readonly Settings $settings,
		private readonly ReaderFactory $readers,
		private readonly FeedDownloader $downloader,
		private readonly FeedStorage $storage,
		private readonly SiteCrawler $crawler,
		private readonly CategoryResolver $categories,
		private readonly ProductSnapshot $snapshots,
		private readonly ProductWriter $writer,
		private readonly Differ $differ,
		private readonly Notifier $notifier,
	) {}

	/**
	 * Queues the next step for a run.
	 */
	public static function schedule( int $run_id ): void {
		as_enqueue_async_action( self::HOOK, [ $run_id ], 'supplier-sync', true );
	}

	/**
	 * Runs one slice of work. Returns true when the run needs another step.
	 *
	 * @param int        $run_id Run.
	 * @param float|null $budget Seconds to work, defaults to the settings value.
	 */
	public function step( int $run_id, ?float $budget = null ): bool {
		$run = $this->runs->find( $run_id );
		if ( null === $run || ! $run->is_active() ) {
			return false;
		}
		$supplier = $this->suppliers->find( $run->supplier_id );
		if ( null === $supplier ) {
			$this->fail( $run, __( 'The supplier profile was deleted.', 'woo-supplier-sync' ) );
			return false;
		}

		$this->deadline = microtime( true ) + ( $budget ?? (float) $this->settings->time_budget() );

		if ( Run::QUEUED === $run->status ) {
			$running = $this->runs->running();
			if ( null !== $running && $running->id !== $run->id ) {
				// It will be picked up by start_next() when the current run ends.
				return false;
			}
			$run->status     = Run::RUNNING;
			$run->started_at = current_time( 'mysql', true );
			$run->state     += [
				'full'         => $run->dry_run || $supplier->pricing_hash !== $this->settings->pricing_hash() || ! empty( $run->state['force'] ),
				'pricing_hash' => $this->settings->pricing_hash(),
			];
		}

		wp_defer_term_counting( true );
		try {
			match ( $run->phase ) {
				Run::PHASE_FETCH  => $this->fetch( $run, $supplier ),
				Run::PHASE_PARSE  => $this->parse( $run, $supplier ),
				Run::PHASE_APPLY  => $this->apply( $run, $supplier ),
				Run::PHASE_SWEEP  => $this->sweep( $run, $supplier ),
				default           => $this->finish( $run, $supplier ),
			};
		} catch ( FeedException $e ) {
			$this->fail( $run, $e->getMessage() );
			return false;
		} catch ( \Throwable $e ) {
			$this->fail( $run, sprintf( '%s (%s:%d)', $e->getMessage(), basename( $e->getFile() ), $e->getLine() ) );
			return false;
		} finally {
			wp_defer_term_counting( false );
		}

		$this->runs->save( $run );
		return $run->is_active();
	}

	/**
	 * Action Scheduler callback.
	 */
	public function handle( int $run_id ): void {
		if ( $this->step( $run_id ) ) {
			self::schedule( $run_id );
		}
	}

	private function fetch( Run $run, Supplier $supplier ): void {
		if ( 'site' === $supplier->type ) {
			$file = (string) ( $run->state['file'] ?? '' );
			if ( '' === $file ) {
				$file               = $this->storage->path_for_run( $run->id, 'jsonl' );
				$run->state['file'] = $file;
				touch( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- private temp file in our own folder.
			}
			$crawl               = $this->crawler->step( $supplier, (array) ( $run->state['crawl'] ?? [] ), $file, $this->deadline );
			$done                = ! empty( $crawl['done'] );
			$run->state['pages'] = (int) $crawl['pages'];
			// The visited set is only needed while crawling.
			$run->state['crawl'] = $done ? [] : $crawl;
			if ( ! $done ) {
				return;
			}
		} else {
			$run->state['file'] = $this->downloader->download( $supplier, $run->id );
		}

		$run->phase           = Run::PHASE_PARSE;
		$run->state['offset'] = 0;
	}

	private function parse( Run $run, Supplier $supplier ): void {
		$reader    = $this->readers->create( $supplier->type, $supplier->id, $supplier->config );
		$mapper    = new AttributeMap( $supplier->attribute_map(), 'skip' !== ( $supplier->config['unmapped_attributes'] ?? 'keep' ) );
		$offset    = (int) ( $run->state['offset'] ?? 0 );
		$batch     = [];
		$position  = $offset;
		$exhausted = true;

		foreach ( $reader->read( (string) $run->state['file'], $offset ) as $position => $item ) {
			if ( $item instanceof RowError ) {
				$run->bump( 'errors' );
				$this->runs->log( $run->id, 'error', '', 0, $item->reference, $item->message );
			} else {
				$batch[] = $mapper->apply( $item );
				$run->bump( 'parsed' );
			}

			if ( count( $batch ) >= self::PARSE_BATCH ) {
				$this->stage( $run, $batch );
				$batch = [];
				if ( microtime( true ) >= $this->deadline ) {
					$exhausted = false;
					break;
				}
			}
		}
		$this->stage( $run, $batch );

		if ( ! $exhausted ) {
			$run->state['offset'] = $position + 1;
			return;
		}

		$run->state['total_groups'] = $this->count_groups( $run );
		$run->state['done_groups']  = 0;
		$run->state['cursor']       = '';
		$run->phase                 = Run::PHASE_APPLY;
	}

	/**
	 * @param Run         $run   Run.
	 * @param list<Offer> $batch Parsed offers.
	 */
	private function stage( Run $run, array $batch ): void {
		if ( [] === $batch ) {
			return;
		}
		$known = $this->offers->known_groups( array_map( static fn ( Offer $o ): string => $o->unit_key(), $batch ) );
		$items = array_map(
			static fn ( Offer $o ): array => [
				'offer'     => $o,
				'group_key' => $known[ $o->unit_key() ] ?? $o->default_group_key(),
			],
			$batch
		);
		$this->offers->stage( $run->id, $items );
	}

	private function count_groups( Run $run ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT group_key) FROM %i WHERE run_id = %d', \SupplierSync\Infrastructure\Schema::table( 'staging' ), $run->id ) );
	}

	private function apply( Run $run, Supplier $supplier ): void {
		while ( microtime( true ) < $this->deadline ) {
			$groups = $this->offers->staged_groups( $run->id, (string) $run->state['cursor'], self::APPLY_BATCH );
			if ( [] === $groups ) {
				$run->phase           = Run::PHASE_SWEEP;
				$run->state['cursor'] = 0;
				return;
			}

			foreach ( $groups as $group_key ) {
				$staged = $this->offers->staged_in_group( $run->id, $group_key );
				$this->apply_group( $run, $supplier, $group_key, $staged, $this->offers->in_group( $group_key, $supplier->id ) );
				$run->state['cursor']      = $group_key;
				$run->state['done_groups'] = (int) ( $run->state['done_groups'] ?? 0 ) + 1;
			}
		}
	}

	/**
	 * @param Run                                       $run       Run.
	 * @param Supplier                                  $supplier  Supplier of the run.
	 * @param string                                    $group_key Product identity.
	 * @param list<array{offer: Offer, hash: string}> $own       This supplier's offers from the feed.
	 * @param list<array{offer: Offer, hash: string}> $others    Stored offers of other suppliers.
	 */
	private function apply_group( Run $run, Supplier $supplier, string $group_key, array $own, array $others ): void {
		$product_id = $this->product_map->product_id( $group_key );

		// Delta: same offers as last time and pricing untouched, nothing to do.
		if ( empty( $run->state['full'] ) && null !== $product_id && [] !== $own ) {
			$stored = $this->offers->hashes( $supplier->id, $group_key );
			$same   = count( $stored ) === count( $own );
			foreach ( $own as $item ) {
				$same = $same && ( $stored[ $item['offer']->unit_key() ] ?? '' ) === $item['hash'];
			}
			if ( $same ) {
				$run->bump( 'unchanged' );
				$this->offers->upsert( $supplier->id, $run->id, $group_key, $own );
				return;
			}
		}

		$all   = array_merge( array_column( $own, 'offer' ), array_column( $others, 'offer' ) );
		$title = [] !== $all ? $all[0]->name : $group_key;

		try {
			$result = $this->build_and_write( $run, $supplier, $group_key, $all, $product_id );
		} catch ( DraftException | \WC_Data_Exception | \RuntimeException $e ) {
			$run->bump( 'errors' );
			$this->runs->log( $run->id, 'error', $group_key, (int) $product_id, $title, $e->getMessage() );
			return;
		}

		if ( null === $result ) {
			$run->bump( 'skipped' );
			return;
		}

		[ $changes, $id, $name ] = $result;
		if ( ! $run->dry_run ) {
			$this->offers->upsert( $supplier->id, $run->id, $group_key, $own );
		}

		$run->bump( $changes->action );
		if ( ! $changes->is_noop() ) {
			$this->runs->log( $run->id, $changes->action, $group_key, $id, $name, '', $changes->changes );
		}
	}

	/**
	 * @param Run         $run        Run.
	 * @param Supplier    $supplier   Supplier of the run.
	 * @param string      $group_key  Product identity.
	 * @param list<Offer> $offers     All offers of the group.
	 * @param int|null    $product_id Mapped product.
	 * @return array{0: ChangeSet, 1: int, 2: string}|null Null when the category is excluded.
	 */
	private function build_and_write( Run $run, Supplier $supplier, string $group_key, array $offers, ?int $product_id ): ?array {
		if ( [] === $offers ) {
			return null;
		}

		usort( $offers, static fn ( Offer $a, Offer $b ): int => [ $a->supplier_id, $a->external_id ] <=> [ $b->supplier_id, $b->external_id ] );
		$anchor          = $offers[0];
		$anchor_supplier = $anchor->supplier_id === $supplier->id ? $supplier : ( $this->suppliers->find( $anchor->supplier_id ) ?? $supplier );
		$category        = $this->categories->resolve( $anchor_supplier, $anchor, ! $run->dry_run );
		if ( $category['skip'] ) {
			return null;
		}

		$aggregator = new OfferAggregator( $this->settings->calculator(), $this->settings->stock_policy() );
		$by_unit    = [];
		foreach ( $offers as $offer ) {
			$by_unit[ $offer->unit_key() ][] = $offer;
		}
		$units = [];
		foreach ( $by_unit as $unit_offers ) {
			$units[] = $aggregator->aggregate( $unit_offers, $category['lineage'] );
		}

		$draft   = ( new DraftBuilder( $this->settings->stock_policy() ) )->build( $group_key, $units, $category['ids'] );
		$current = null !== $product_id ? $this->snapshots->read( $product_id ) : null;
		$changes = $this->differ->diff( $current, $draft, null !== $current ? $supplier->locked_fields() : [] );

		if ( $run->dry_run || $changes->is_noop() ) {
			return [ $changes, (int) $product_id, $draft->name ];
		}

		$id = $this->writer->write( $draft, $changes, null !== $current ? $product_id : null );
		$this->product_map->save( $group_key, $id );
		if ( $changes->touches( 'images' ) || $changes->touches( 'variations' ) || ChangeSet::CREATE === $changes->action ) {
			ImageImporter::schedule( $id );
		}

		return [ $changes, $id, $draft->name ];
	}

	private function sweep( Run $run, Supplier $supplier ): void {
		if ( ! isset( $run->state['sweep_checked'] ) ) {
			$run->state['sweep_checked'] = true;
			$missing                     = $this->offers->count_missing_from_run( $supplier->id, $run->id );
			$known                       = $this->offers->count_for_supplier( $supplier->id );
			$percent                     = $known > 0 ? (int) round( $missing * 100 / $known ) : 0;

			if ( $missing > 0 && $percent > $supplier->max_removal_percent() ) {
				$run->state['sweep_blocked'] = true;
				$this->runs->log(
					$run->id,
					'error',
					'',
					0,
					__( 'Removal safety brake', 'woo-supplier-sync' ),
					sprintf(
						/* translators: 1: number of offers, 2: percent, 3: limit. */
						__( '%1$d offers (%2$d%%) disappeared from the feed, more than the %3$d%% limit. Nothing was taken off sale. Check the feed or raise the limit in the supplier profile.', 'woo-supplier-sync' ),
						$missing,
						$percent,
						$supplier->max_removal_percent()
					)
				);
				$run->bump( 'errors' );
			}
		}

		if ( ! empty( $run->state['sweep_blocked'] ) ) {
			$run->phase = Run::PHASE_FINISH;
			return;
		}

		while ( microtime( true ) < $this->deadline ) {
			$missing = $this->offers->missing_from_run( $supplier->id, $run->id, (int) $run->state['cursor'], self::SWEEP_BATCH );
			if ( [] === $missing ) {
				$run->phase = Run::PHASE_FINISH;
				return;
			}

			$groups = [];
			foreach ( $missing as $row ) {
				$groups[ $row['group_key'] ][] = $row['unit_key'];
			}

			foreach ( $groups as $group_key => $gone_units ) {
				$this->sweep_group( $run, $supplier, (string) $group_key, $gone_units );
			}

			if ( ! $run->dry_run ) {
				$this->offers->delete_ids( array_column( $missing, 'id' ) );
			}
			$run->state['cursor'] = (int) end( $missing )['id'];
		}
	}

	/**
	 * @param Run          $run        Run.
	 * @param Supplier     $supplier   Supplier of the run.
	 * @param string       $group_key  Product.
	 * @param list<string> $gone_units Units this supplier no longer offers.
	 */
	private function sweep_group( Run $run, Supplier $supplier, string $group_key, array $gone_units ): void {
		$product_id = $this->product_map->product_id( $group_key );
		if ( null === $product_id ) {
			return;
		}

		// What remains: other suppliers plus this supplier's offers still in the feed.
		$remaining = array_column( $this->offers->in_group( $group_key, $supplier->id ), 'offer' );
		foreach ( $this->offers->staged_in_group( $run->id, $group_key ) as $item ) {
			$remaining[] = $item['offer'];
		}
		$remaining = array_values( array_filter( $remaining, static fn ( Offer $o ): bool => ! ( $o->supplier_id === $supplier->id && in_array( $o->unit_key(), $gone_units, true ) ) ) );

		$snapshot = $this->snapshots->read( $product_id );
		$title    = (string) ( $snapshot['name'] ?? $group_key );

		if ( [] === $remaining ) {
			if ( null !== $snapshot && 'publish' === $snapshot['status'] ) {
				if ( ! $run->dry_run ) {
					$this->writer->unpublish( $product_id );
				}
				$run->bump( 'unpublished' );
				$this->runs->log(
					$run->id,
					ChangeSet::UNPUBLISH,
					$group_key,
					$product_id,
					$title,
					__( 'No supplier offers this product any more.', 'woo-supplier-sync' ),
					[ 'status' => [ 'publish', 'draft' ] ]
				);
			}
			return;
		}

		try {
			$result = $this->build_and_write( $run, $supplier, $group_key, $remaining, $product_id );
		} catch ( DraftException | \WC_Data_Exception | \RuntimeException $e ) {
			$run->bump( 'errors' );
			$this->runs->log( $run->id, 'error', $group_key, $product_id, $title, $e->getMessage() );
			return;
		}
		if ( null !== $result && ! $result[0]->is_noop() ) {
			$run->bump( $result[0]->action );
			$this->runs->log( $run->id, $result[0]->action, $group_key, $result[1], $result[2], __( 'A supplier dropped part of this product.', 'woo-supplier-sync' ), $result[0]->changes );
		}
	}

	private function finish( Run $run, Supplier $supplier ): void {
		$this->offers->clear_staging( $run->id );
		$this->storage->delete( (string) ( $run->state['file'] ?? '' ) );

		$run->status      = Run::COMPLETED;
		$run->phase       = Run::PHASE_FINISH;
		$run->finished_at = current_time( 'mysql', true );
		$run->state       = array_intersect_key( $run->state, array_flip( [ 'full', 'pages', 'total_groups', 'sweep_blocked' ] ) );

		$this->suppliers->mark_run( $supplier->id, $run->id, $run->dry_run ? null : (string) $this->settings->pricing_hash() );
		$this->runs->save( $run );
		$this->notifier->run_finished( $run, $supplier );
		$this->start_next();
	}

	private function fail( Run $run, string $message ): void {
		$this->offers->clear_staging( $run->id );
		$this->storage->delete( (string) ( $run->state['file'] ?? '' ) );

		$run->status      = Run::FAILED;
		$run->error       = $message;
		$run->finished_at = current_time( 'mysql', true );
		$run->state       = [];
		$this->runs->save( $run );

		$supplier = $this->suppliers->find( $run->supplier_id );
		if ( null !== $supplier ) {
			$this->suppliers->mark_run( $supplier->id, $run->id );
			$this->notifier->run_finished( $run, $supplier );
		}
		$this->start_next();
	}

	/**
	 * Runs go one at a time: two suppliers may touch the same merged product.
	 */
	public function start_next(): void {
		if ( null !== $this->runs->running() ) {
			return;
		}
		$next = $this->runs->next_queued();
		if ( null !== $next ) {
			self::schedule( $next->id );
		}
	}
}
