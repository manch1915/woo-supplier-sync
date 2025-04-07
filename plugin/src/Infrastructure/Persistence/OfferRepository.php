<?php
/**
 * Current supplier offers (the last state applied to the store) and the per-run staging area.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure\Persistence;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables, batch queries.

use SupplierSync\Domain\Offer;
use SupplierSync\Infrastructure\Schema;

final class OfferRepository {

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Group keys already assigned to these units, so a second supplier joins the existing product.
	 *
	 * @param list<string> $unit_keys Unit keys.
	 * @return array<string, string> Unit key => group key.
	 */
	public function known_groups( array $unit_keys ): array {
		if ( [] === $unit_keys ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $unit_keys ), '%s' ) );
		$rows         = $this->db->get_results(
			$this->db->prepare(
				"SELECT unit_key, group_key FROM %i WHERE unit_key IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Schema::table( 'offers' ),
				...$unit_keys
			),
			ARRAY_A
		);

		$map = [];
		foreach ( $rows ?? [] as $row ) {
			$map[ (string) $row['unit_key'] ] = (string) $row['group_key'];
		}
		return $map;
	}

	/**
	 * @param int                                         $run_id Run.
	 * @param list<array{offer: Offer, group_key: string}> $items  Offers with resolved group keys.
	 */
	public function stage( int $run_id, array $items ): void {
		if ( [] === $items ) {
			return;
		}

		$values = [];
		$args   = [ Schema::table( 'staging' ) ];
		foreach ( $items as $item ) {
			$values[] = '(%d, %s, %s, %s, %s)';
			array_push( $args, $run_id, $item['offer']->unit_key(), $item['group_key'], $item['offer']->hash(), (string) wp_json_encode( $item['offer']->to_array() ) );
		}

		// A feed may list the same unit twice; the last row wins.
		$this->db->query(
			$this->db->prepare(
				'INSERT INTO %i (run_id, unit_key, group_key, hash, payload) VALUES ' . implode( ',', $values ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. ' ON DUPLICATE KEY UPDATE group_key = VALUES(group_key), hash = VALUES(hash), payload = VALUES(payload)',
				...$args
			)
		);
	}

	public function staged_count( int $run_id ): int {
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %d', Schema::table( 'staging' ), $run_id ) );
	}

	/**
	 * @param int    $run_id Run.
	 * @param string $after  Cursor: last processed group key.
	 * @param int    $limit  Batch size.
	 * @return list<string>
	 */
	public function staged_groups( int $run_id, string $after, int $limit ): array {
		return array_map(
			'strval',
			$this->db->get_col(
				$this->db->prepare(
					'SELECT DISTINCT group_key FROM %i WHERE run_id = %d AND group_key > %s ORDER BY group_key ASC LIMIT %d',
					Schema::table( 'staging' ),
					$run_id,
					$after,
					$limit
				)
			)
		);
	}

	/**
	 * @return list<array{offer: Offer, hash: string}>
	 */
	public function staged_in_group( int $run_id, string $group_key ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT hash, payload FROM %i WHERE run_id = %d AND group_key = %s', Schema::table( 'staging' ), $run_id, $group_key ),
			ARRAY_A
		);
		return $this->decode( $rows ?? [] );
	}

	/**
	 * Stored offers of a group, optionally without one supplier.
	 *
	 * @return list<array{offer: Offer, hash: string}>
	 */
	public function in_group( string $group_key, int $except_supplier = 0 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT hash, payload FROM %i WHERE group_key = %s AND supplier_id <> %d',
				Schema::table( 'offers' ),
				$group_key,
				$except_supplier
			),
			ARRAY_A
		);
		return $this->decode( $rows ?? [] );
	}

	/**
	 * @return array<string, string> Unit key => hash, for one supplier in one group.
	 */
	public function hashes( int $supplier_id, string $group_key ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT unit_key, hash FROM %i WHERE supplier_id = %d AND group_key = %s', Schema::table( 'offers' ), $supplier_id, $group_key ),
			ARRAY_A
		);
		return array_column( $rows ?? [], 'hash', 'unit_key' );
	}

	/**
	 * @param int                                       $supplier_id Supplier.
	 * @param int                                       $run_id      Run that saw the offers.
	 * @param string                                    $group_key   Group.
	 * @param list<array{offer: Offer, hash: string}> $offers      Offers to store.
	 */
	public function upsert( int $supplier_id, int $run_id, string $group_key, array $offers ): void {
		$now = current_time( 'mysql', true );
		foreach ( $offers as $item ) {
			$this->db->query(
				$this->db->prepare(
					'INSERT INTO %i (supplier_id, unit_key, group_key, hash, payload, seen_run_id, updated_at) VALUES (%d, %s, %s, %s, %s, %d, %s)
					ON DUPLICATE KEY UPDATE group_key = VALUES(group_key), hash = VALUES(hash), payload = VALUES(payload), seen_run_id = VALUES(seen_run_id), updated_at = VALUES(updated_at)',
					Schema::table( 'offers' ),
					$supplier_id,
					$item['offer']->unit_key(),
					$group_key,
					$item['hash'],
					(string) wp_json_encode( $item['offer']->to_array() ),
					$run_id,
					$now
				)
			);
		}
	}

	public function count_for_supplier( int $supplier_id ): int {
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE supplier_id = %d', Schema::table( 'offers' ), $supplier_id ) );
	}

	/**
	 * Offers of a supplier that the current run did not see in the feed.
	 *
	 * @return list<array{id: int, unit_key: string, group_key: string}>
	 */
	public function missing_from_run( int $supplier_id, int $run_id, int $after_id, int $limit ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT o.id, o.unit_key, o.group_key FROM %i o
				LEFT JOIN %i s ON s.run_id = %d AND s.unit_key = o.unit_key
				WHERE o.supplier_id = %d AND s.id IS NULL AND o.id > %d
				ORDER BY o.id ASC LIMIT %d',
				Schema::table( 'offers' ),
				Schema::table( 'staging' ),
				$run_id,
				$supplier_id,
				$after_id,
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ): array => [
				'id'        => (int) $row['id'],
				'unit_key'  => (string) $row['unit_key'],
				'group_key' => (string) $row['group_key'],
			],
			$rows ?? []
		);
	}

	public function count_missing_from_run( int $supplier_id, int $run_id ): int {
		return (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT COUNT(*) FROM %i o LEFT JOIN %i s ON s.run_id = %d AND s.unit_key = o.unit_key WHERE o.supplier_id = %d AND s.id IS NULL',
				Schema::table( 'offers' ),
				Schema::table( 'staging' ),
				$run_id,
				$supplier_id
			)
		);
	}

	/**
	 * @param list<int> $ids Offer row IDs.
	 */
	public function delete_ids( array $ids ): void {
		if ( [] === $ids ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$this->db->query(
			$this->db->prepare( "DELETE FROM %i WHERE id IN ($placeholders)", Schema::table( 'offers' ), ...$ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public function delete_for_supplier( int $supplier_id ): void {
		$this->db->delete( Schema::table( 'offers' ), [ 'supplier_id' => $supplier_id ] );
	}

	public function clear_staging( int $run_id ): void {
		$this->db->delete( Schema::table( 'staging' ), [ 'run_id' => $run_id ] );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Rows with hash and payload.
	 * @return list<array{offer: Offer, hash: string}>
	 */
	private function decode( array $rows ): array {
		$items = [];
		foreach ( $rows as $row ) {
			$data = json_decode( (string) $row['payload'], true );
			if ( is_array( $data ) ) {
				$items[] = [
					'offer' => Offer::from_array( $data ),
					'hash'  => (string) $row['hash'],
				];
			}
		}
		return $items;
	}
}
