<?php
/**
 * Recurring runs per supplier through Action Scheduler cron actions.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Sync;

use SupplierSync\Domain\Supplier;
use SupplierSync\Infrastructure\Persistence\SupplierRepository;

final class Scheduler {

	public const HOOK = 'supplier_sync_scheduled_run';

	public const SCHEDULES = [
		'manual'        => null,
		'hourly'        => '0 * * * *',
		'every_6_hours' => '15 */6 * * *',
		'twice_daily'   => '30 6,18 * * *',
		'daily'         => '0 4 * * *',
	];

	public function __construct(
		private readonly SupplierRepository $suppliers,
		private readonly RunService $runs,
	) {}

	public function sync( Supplier $supplier ): void {
		as_unschedule_all_actions( self::HOOK, [ $supplier->id ], 'supplier-sync' );

		$cron = self::SCHEDULES[ $supplier->schedule ] ?? null;
		if ( null === $cron || ! $supplier->enabled ) {
			return;
		}
		as_schedule_cron_action( time(), $cron, self::HOOK, [ $supplier->id ], 'supplier-sync' );
	}

	public function unschedule( int $supplier_id ): void {
		as_unschedule_all_actions( self::HOOK, [ $supplier_id ], 'supplier-sync' );
	}

	public function next_run( Supplier $supplier ): ?int {
		$next = as_next_scheduled_action( self::HOOK, [ $supplier->id ], 'supplier-sync' );
		return is_int( $next ) ? $next : null;
	}

	public function handle( int $supplier_id ): void {
		$supplier = $this->suppliers->find( $supplier_id );
		if ( null === $supplier || ! $supplier->enabled ) {
			return;
		}
		$this->runs->start( $supplier, false, 'schedule' );
	}
}
