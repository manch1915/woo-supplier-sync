<?php
/**
 * Supplier profiles.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure\Persistence;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table, results are not cached on purpose.

use SupplierSync\Domain\Supplier;
use SupplierSync\Infrastructure\Schema;

final class SupplierRepository {

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * @return list<Supplier>
	 */
	public function all(): array {
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i ORDER BY name ASC', Schema::table( 'suppliers' ) ), ARRAY_A );
		return array_map( [ $this, 'hydrate' ], $rows ?? [] );
	}

	public function find( int $id ): ?Supplier {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'suppliers' ), $id ), ARRAY_A );
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function save( Supplier $supplier ): Supplier {
		$now  = current_time( 'mysql', true );
		$data = [
			'name'       => $supplier->name,
			'type'       => $supplier->type,
			'config'     => (string) wp_json_encode( $supplier->config ),
			'schedule'   => $supplier->schedule,
			'enabled'    => $supplier->enabled ? 1 : 0,
			'updated_at' => $now,
		];

		if ( $supplier->id > 0 ) {
			$this->db->update( Schema::table( 'suppliers' ), $data, [ 'id' => $supplier->id ] );
			$id = $supplier->id;
		} else {
			$this->db->insert( Schema::table( 'suppliers' ), $data + [ 'created_at' => $now ] );
			$id = (int) $this->db->insert_id;
		}

		return $this->find( $id ) ?? $supplier;
	}

	public function delete( int $id ): void {
		$this->db->delete( Schema::table( 'suppliers' ), [ 'id' => $id ] );
	}

	public function mark_run( int $id, int $run_id, ?string $pricing_hash = null ): void {
		$data = [ 'last_run_id' => $run_id ];
		if ( null !== $pricing_hash ) {
			$data['pricing_hash'] = $pricing_hash;
		}
		$this->db->update( Schema::table( 'suppliers' ), $data, [ 'id' => $id ] );
	}

	/**
	 * @param array<string, mixed> $row DB row.
	 */
	private function hydrate( array $row ): Supplier {
		$config = json_decode( (string) $row['config'], true );

		return new Supplier(
			id: (int) $row['id'],
			name: (string) $row['name'],
			type: (string) $row['type'],
			config: is_array( $config ) ? $config : [],
			schedule: (string) $row['schedule'],
			enabled: (bool) $row['enabled'],
			pricing_hash: (string) $row['pricing_hash'],
			last_run_id: null !== $row['last_run_id'] ? (int) $row['last_run_id'] : null,
		);
	}
}
