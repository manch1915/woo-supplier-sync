<?php
/**
 * Runs and their per-product log.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure\Persistence;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables.

use SupplierSync\Infrastructure\Schema;
use SupplierSync\Sync\Run;

final class RunRepository {

	private const MAX_CHANGES_JSON = 20000;

	public function __construct( private readonly \wpdb $db ) {}

	public function create( int $supplier_id, bool $dry_run, string $source, int $user_id ): Run {
		$this->db->insert(
			Schema::table( 'runs' ),
			[
				'supplier_id' => $supplier_id,
				'dry_run'     => $dry_run ? 1 : 0,
				'source'      => $source,
				'status'      => Run::QUEUED,
				'phase'       => Run::PHASE_FETCH,
				'state'       => '{}',
				'stats'       => '{}',
				'user_id'     => $user_id,
				'created_at'  => current_time( 'mysql', true ),
			]
		);
		$run = $this->find( (int) $this->db->insert_id );
		assert( null !== $run );
		return $run;
	}

	public function find( int $id ): ?Run {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'runs' ), $id ), ARRAY_A );
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function save( Run $run ): void {
		$this->db->update(
			Schema::table( 'runs' ),
			[
				'status'      => $run->status,
				'phase'       => $run->phase,
				'state'       => (string) wp_json_encode( $run->state ),
				'stats'       => (string) wp_json_encode( $run->stats ),
				'error'       => $run->error,
				'started_at'  => $run->started_at,
				'finished_at' => $run->finished_at,
			],
			[ 'id' => $run->id ]
		);
	}

	/**
	 * @param int      $limit       Page size.
	 * @param int      $offset      Page offset.
	 * @param int|null $supplier_id Filter.
	 * @return list<Run>
	 */
	public function latest( int $limit = 20, int $offset = 0, ?int $supplier_id = null ): array {
		$sql = null !== $supplier_id
			? $this->db->prepare( 'SELECT * FROM %i WHERE supplier_id = %d ORDER BY id DESC LIMIT %d OFFSET %d', Schema::table( 'runs' ), $supplier_id, $limit, $offset )
			: $this->db->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', Schema::table( 'runs' ), $limit, $offset );

		return array_map( [ $this, 'hydrate' ], $this->db->get_results( $sql, ARRAY_A ) ?? [] );
	}

	public function count( ?int $supplier_id = null ): int {
		$sql = null !== $supplier_id
			? $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE supplier_id = %d', Schema::table( 'runs' ), $supplier_id )
			: $this->db->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'runs' ) );
		return (int) $this->db->get_var( $sql );
	}

	public function running(): ?Run {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id ASC LIMIT 1', Schema::table( 'runs' ), Run::RUNNING ), ARRAY_A );
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function next_queued(): ?Run {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id ASC LIMIT 1', Schema::table( 'runs' ), Run::QUEUED ), ARRAY_A );
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function active_for_supplier( int $supplier_id ): ?Run {
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM %i WHERE supplier_id = %d AND status IN (%s, %s) ORDER BY id DESC LIMIT 1',
				Schema::table( 'runs' ),
				$supplier_id,
				Run::QUEUED,
				Run::RUNNING
			),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * @param int                                       $run_id     Run.
	 * @param string                                    $action     created | updated | unpublished | error | skipped | unchanged.
	 * @param string                                    $group_key  Product identity.
	 * @param int                                       $product_id Product, 0 if none.
	 * @param string                                    $title      Product title or row reference.
	 * @param string                                    $message    Free text.
	 * @param array<string, array{0: mixed, 1: mixed}> $changes    Field changes.
	 */
	public function log( int $run_id, string $action, string $group_key, int $product_id, string $title, string $message = '', array $changes = [] ): void {
		$json = [] !== $changes ? (string) wp_json_encode( $changes ) : null;
		if ( null !== $json && strlen( $json ) > self::MAX_CHANGES_JSON ) {
			$json = (string) wp_json_encode( array_slice( $changes, 0, 40, true ) + [ '…' => [ null, count( $changes ) . ' changes' ] ] );
		}

		$this->db->insert(
			Schema::table( 'run_items' ),
			[
				'run_id'     => $run_id,
				'group_key'  => substr( $group_key, 0, 160 ),
				'product_id' => $product_id,
				'action'     => $action,
				'title'      => mb_substr( $title, 0, 255 ),
				'message'    => $message,
				'changes'    => $json,
				'created_at' => current_time( 'mysql', true ),
			]
		);
	}

	/**
	 * @param int         $run_id Run.
	 * @param string|null $action Filter.
	 * @param string      $search Title substring.
	 * @param int         $limit  Page size.
	 * @param int         $offset Offset.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function items( int $run_id, ?string $action, string $search, int $limit, int $offset ): array {
		$table = Schema::table( 'run_items' );
		$where = $this->db->prepare( 'run_id = %d', $run_id );
		if ( null !== $action && '' !== $action ) {
			$where .= $this->db->prepare( ' AND action = %s', $action );
		}
		if ( '' !== $search ) {
			$where .= $this->db->prepare( ' AND title LIKE %s', '%' . $this->db->esc_like( $search ) . '%' );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepare() above.
		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $table ) );
		$rows  = $this->db->get_results( $this->db->prepare( "SELECT * FROM %i WHERE $where ORDER BY id ASC LIMIT %d OFFSET %d", $table, $limit, $offset ), ARRAY_A );
		// phpcs:enable

		$items = array_map(
			static function ( array $row ): array {
				$changes = null !== $row['changes'] ? json_decode( (string) $row['changes'], true ) : null;
				return [
					'id'         => (int) $row['id'],
					'action'     => (string) $row['action'],
					'group_key'  => (string) $row['group_key'],
					'product_id' => (int) $row['product_id'],
					'title'      => (string) $row['title'],
					'message'    => (string) $row['message'],
					'changes'    => is_array( $changes ) ? $changes : [],
				];
			},
			$rows ?? []
		);

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Drops logs of old runs, keeps the latest ones per supplier.
	 */
	public function prune( int $keep_days ): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $keep_days * DAY_IN_SECONDS );
		$this->db->query(
			$this->db->prepare(
				'DELETE i FROM %i i INNER JOIN %i r ON r.id = i.run_id WHERE r.created_at < %s',
				Schema::table( 'run_items' ),
				Schema::table( 'runs' ),
				$cutoff
			)
		);
		$this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE created_at < %s', Schema::table( 'runs' ), $cutoff ) );
	}

	/**
	 * @param array<string, mixed> $row DB row.
	 */
	private function hydrate( array $row ): Run {
		$state = json_decode( (string) $row['state'], true );
		$stats = json_decode( (string) $row['stats'], true );

		return new Run(
			id: (int) $row['id'],
			supplier_id: (int) $row['supplier_id'],
			dry_run: (bool) $row['dry_run'],
			source: (string) $row['source'],
			status: (string) $row['status'],
			phase: (string) $row['phase'],
			state: is_array( $state ) ? $state : [],
			stats: is_array( $stats ) ? array_map( 'intval', $stats ) : [],
			error: null !== $row['error'] ? (string) $row['error'] : null,
			user_id: (int) $row['user_id'],
			created_at: (string) $row['created_at'],
			started_at: null !== $row['started_at'] ? (string) $row['started_at'] : null,
			finished_at: null !== $row['finished_at'] ? (string) $row['finished_at'] : null,
		);
	}
}
