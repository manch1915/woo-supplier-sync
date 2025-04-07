<?php
/**
 * One synchronization run of a supplier feed.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Sync;

final class Run {

	public const QUEUED    = 'queued';
	public const RUNNING   = 'running';
	public const COMPLETED = 'completed';
	public const FAILED    = 'failed';
	public const CANCELLED = 'cancelled';

	public const PHASE_FETCH  = 'fetch';
	public const PHASE_PARSE  = 'parse';
	public const PHASE_APPLY  = 'apply';
	public const PHASE_SWEEP  = 'sweep';
	public const PHASE_FINISH = 'finish';

	public const COUNTERS = [ 'parsed', 'created', 'updated', 'unchanged', 'unpublished', 'errors', 'skipped' ];

	/**
	 * @param int                  $id          Row ID.
	 * @param int                  $supplier_id Supplier.
	 * @param bool                 $dry_run     Only report what would change.
	 * @param string               $source      manual | schedule | cli.
	 * @param string               $status      One of the status constants.
	 * @param string               $phase       One of the phase constants.
	 * @param array<string, mixed> $state       Phase cursor: offsets, crawler queue, file path.
	 * @param array<string, int>   $stats       Counters.
	 * @param string|null          $error       Fatal error text.
	 * @param int                  $user_id     Who started it.
	 * @param string               $created_at  UTC datetime.
	 * @param string|null          $started_at  UTC datetime.
	 * @param string|null          $finished_at UTC datetime.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $supplier_id,
		public readonly bool $dry_run,
		public readonly string $source,
		public string $status,
		public string $phase,
		public array $state,
		public array $stats,
		public ?string $error,
		public readonly int $user_id,
		public readonly string $created_at,
		public ?string $started_at,
		public ?string $finished_at,
	) {}

	public function is_active(): bool {
		return in_array( $this->status, [ self::QUEUED, self::RUNNING ], true );
	}

	public function bump( string $counter, int $by = 1 ): void {
		$this->stats[ $counter ] = ( $this->stats[ $counter ] ?? 0 ) + $by;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$stats = array_fill_keys( self::COUNTERS, 0 );
		foreach ( $this->stats as $key => $value ) {
			$stats[ $key ] = (int) $value;
		}

		return [
			'id'          => $this->id,
			'supplier_id' => $this->supplier_id,
			'dry_run'     => $this->dry_run,
			'source'      => $this->source,
			'status'      => $this->status,
			'phase'       => $this->phase,
			'progress'    => $this->progress(),
			'stats'       => $stats,
			'error'       => $this->error,
			'user_id'     => $this->user_id,
			'created_at'  => $this->iso( $this->created_at ),
			'started_at'  => $this->iso( $this->started_at ),
			'finished_at' => $this->iso( $this->finished_at ),
		];
	}

	/**
	 * Rough progress for the UI: phases weigh differently, apply dominates.
	 */
	public function progress(): float {
		if ( ! $this->is_active() ) {
			return 1.0;
		}

		$total = max( 1, (int) ( $this->state['total_groups'] ?? 0 ) );
		$done  = min( $total, (int) ( $this->state['done_groups'] ?? 0 ) );

		return match ( $this->phase ) {
			self::PHASE_FETCH  => 0.02,
			self::PHASE_PARSE  => 0.1,
			self::PHASE_APPLY  => 0.15 + 0.75 * ( $done / $total ),
			self::PHASE_SWEEP  => 0.92,
			default            => 0.98,
		};
	}

	private function iso( ?string $datetime ): ?string {
		return null !== $datetime ? gmdate( 'c', (int) strtotime( $datetime . ' UTC' ) ) : null;
	}
}
