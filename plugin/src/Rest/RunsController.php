<?php
/**
 * Runs and their logs over REST.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Rest;

use SupplierSync\Container;
use SupplierSync\Infrastructure\Persistence\RunRepository;
use SupplierSync\Infrastructure\Persistence\SupplierRepository;
use SupplierSync\Sync\Run;
use SupplierSync\Sync\RunService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RunsController {

	private const PER_PAGE = 20;

	private RunRepository $runs;

	public function __construct( private readonly Container $container ) {
		$this->runs = $container->get( RunRepository::class );
	}

	public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$supplier = $this->container->get( SupplierRepository::class )->find( (int) $request['id'] );
		if ( null === $supplier ) {
			return new WP_Error( 'supplier_sync_not_found', __( 'Supplier not found.', 'woo-supplier-sync' ), [ 'status' => 404 ] );
		}

		$run = $this->container->get( RunService::class )->start( $supplier, (bool) $request['dry_run'], 'manual', (bool) $request['force'] );
		return new WP_REST_Response( $this->present( $run ), 202 );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$supplier = ! empty( $request['supplier'] ) ? (int) $request['supplier'] : null;
		$page     = max( 1, (int) $request['page'] );
		$runs     = $this->runs->latest( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE, $supplier );
		$total    = $this->runs->count( $supplier );

		$response = new WP_REST_Response( array_map( [ $this, 'present' ], $runs ) );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / self::PER_PAGE ) ) );
		return $response;
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$run = $this->runs->find( (int) $request['id'] );
		return null !== $run ? new WP_REST_Response( $this->present( $run ) ) : $this->not_found();
	}

	public function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$run = $this->container->get( RunService::class )->cancel( (int) $request['id'] );
		return null !== $run ? new WP_REST_Response( $this->present( $run ) ) : $this->not_found();
	}

	public function items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$run = $this->runs->find( (int) $request['id'] );
		if ( null === $run ) {
			return $this->not_found();
		}

		$page   = max( 1, (int) $request['page'] );
		$result = $this->runs->items( $run->id, (string) $request['action'], sanitize_text_field( (string) $request['search'] ), 50, ( $page - 1 ) * 50 );
		$items  = array_map(
			static function ( array $item ): array {
				$item['edit_url'] = $item['product_id'] > 0 ? get_edit_post_link( $item['product_id'], 'raw' ) : null;
				$item['view_url'] = $item['product_id'] > 0 ? get_permalink( $item['product_id'] ) : null;
				return $item;
			},
			$result['items']
		);

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / 50 ) ) );
		return $response;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present( Run $run ): array {
		$supplier = $this->container->get( SupplierRepository::class )->find( $run->supplier_id );
		$user     = $run->user_id > 0 ? get_userdata( $run->user_id ) : false;

		return $run->to_array() + [
			'supplier_name' => $supplier->name ?? __( 'Deleted supplier', 'woo-supplier-sync' ),
			'supplier_type' => $supplier?->type,
			'user_name'     => false !== $user ? $user->display_name : null,
		];
	}

	private function not_found(): WP_Error {
		return new WP_Error( 'supplier_sync_not_found', __( 'Run not found.', 'woo-supplier-sync' ), [ 'status' => 404 ] );
	}
}
