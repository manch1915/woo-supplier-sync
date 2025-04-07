<?php
/**
 * Starting, cancelling and synchronously driving runs.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Sync;

use SupplierSync\Domain\Supplier;
use SupplierSync\Infrastructure\Persistence\OfferRepository;
use SupplierSync\Infrastructure\Persistence\RunRepository;

final class RunService {

	public function __construct(
		private readonly RunRepository $runs,
		private readonly OfferRepository $offers,
		private readonly SyncRunner $runner,
	) {}

	/**
	 * Queues a run. If the supplier already has one in progress, that run is returned.
	 *
	 * @param Supplier $supplier Supplier.
	 * @param bool     $dry_run  Only report changes.
	 * @param string   $source   manual | schedule | cli.
	 * @param bool     $force    Ignore the delta check and rewrite every product.
	 */
	public function start( Supplier $supplier, bool $dry_run, string $source = 'manual', bool $force = false ): Run {
		$active = $this->runs->active_for_supplier( $supplier->id );
		if ( null !== $active ) {
			return $active;
		}

		$run = $this->runs->create( $supplier->id, $dry_run, $source, get_current_user_id() );
		if ( $force ) {
			$run->state['force'] = true;
			$this->runs->save( $run );
		}

		if ( 'cli' !== $source ) {
			$this->runner->start_next();
		}
		return $run;
	}

	public function cancel( int $run_id ): ?Run {
		$run = $this->runs->find( $run_id );
		if ( null === $run || ! $run->is_active() ) {
			return $run;
		}

		as_unschedule_all_actions( SyncRunner::HOOK, [ $run_id ], 'supplier-sync' );
		$this->offers->clear_staging( $run_id );

		$run->status      = Run::CANCELLED;
		$run->finished_at = current_time( 'mysql', true );
		$run->state       = [];
		$this->runs->save( $run );

		$this->runner->start_next();
		return $run;
	}

	/**
	 * Drives a run to the end in the current process (WP-CLI).
	 *
	 * @param int                $run_id      Run.
	 * @param callable(Run):void $on_progress Called after every step.
	 */
	public function run_now( int $run_id, callable $on_progress ): Run {
		do {
			$more = $this->runner->step( $run_id, 30.0 );
			$run  = $this->runs->find( $run_id );
			assert( null !== $run );
			$on_progress( $run );
		} while ( $more );

		return $run;
	}
}
