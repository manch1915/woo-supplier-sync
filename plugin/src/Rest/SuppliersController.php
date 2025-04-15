<?php
/**
 * Supplier profiles over REST.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Rest;

use SupplierSync\Container;
use SupplierSync\Domain\Supplier;
use SupplierSync\Feed\FeedException;
use SupplierSync\Feed\ReaderFactory;
use SupplierSync\Infrastructure\Capabilities;
use SupplierSync\Infrastructure\Persistence\OfferRepository;
use SupplierSync\Infrastructure\Persistence\RunRepository;
use SupplierSync\Infrastructure\Persistence\SupplierRepository;
use SupplierSync\Sync\FeedPreview;
use SupplierSync\Sync\Scheduler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SuppliersController {

	private SupplierRepository $suppliers;
	private SupplierConfigSanitizer $sanitizer;

	public function __construct( private readonly Container $container ) {
		$this->suppliers = $container->get( SupplierRepository::class );
		$this->sanitizer = new SupplierConfigSanitizer();
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		return [
			'name'     => [
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 190,
			],
			'type'     => [
				'type' => 'string',
				'enum' => ReaderFactory::TYPES,
			],
			'schedule' => [
				'type' => 'string',
				'enum' => array_keys( Scheduler::SCHEDULES ),
			],
			'enabled'  => [ 'type' => 'boolean' ],
			'config'   => [ 'type' => 'object' ],
		];
	}

	public function index(): WP_REST_Response {
		return new WP_REST_Response( array_map( [ $this, 'present' ], $this->suppliers->all() ) );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$supplier = $this->suppliers->find( (int) $request['id'] );
		return null !== $supplier ? new WP_REST_Response( $this->present( $supplier ) ) : $this->not_found();
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( empty( $request['name'] ) || empty( $request['type'] ) ) {
			return new WP_Error( 'supplier_sync_invalid', __( 'Name and feed type are required.', 'woo-supplier-sync' ), [ 'status' => 400 ] );
		}
		$supplier = $this->suppliers->save( $this->from_request( $request, null ) );
		$this->container->get( Scheduler::class )->sync( $supplier );

		return new WP_REST_Response( $this->present( $supplier ), 201 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$existing = $this->suppliers->find( (int) $request['id'] );
		if ( null === $existing ) {
			return $this->not_found();
		}
		$supplier = $this->suppliers->save( $this->from_request( $request, $existing ) );
		$this->container->get( Scheduler::class )->sync( $supplier );

		return new WP_REST_Response( $this->present( $supplier ) );
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$supplier = $this->suppliers->find( (int) $request['id'] );
		if ( null === $supplier ) {
			return $this->not_found();
		}
		if ( null !== $this->container->get( RunRepository::class )->active_for_supplier( $supplier->id ) ) {
			return new WP_Error( 'supplier_sync_busy', __( 'Wait for the current run to finish or cancel it first.', 'woo-supplier-sync' ), [ 'status' => 409 ] );
		}

		// Products stay in the store; their stock from this supplier simply stops updating.
		$this->container->get( Scheduler::class )->unschedule( $supplier->id );
		$this->container->get( OfferRepository::class )->delete_for_supplier( $supplier->id );
		$this->suppliers->delete( $supplier->id );

		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	/**
	 * Fetches and parses the first rows with the mapping from the request, saved or not.
	 */
	public function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id       = absint( $request['id'] ?? 0 );
		$existing = $id > 0 ? $this->suppliers->find( $id ) : null;
		$supplier = $this->from_request( $request, $existing );

		try {
			return new WP_REST_Response( $this->container->get( FeedPreview::class )->preview( $supplier ) );
		} catch ( FeedException | \InvalidArgumentException $e ) {
			return new WP_Error( 'supplier_sync_feed', $e->getMessage(), [ 'status' => 422 ] );
		}
	}

	private function from_request( WP_REST_Request $request, ?Supplier $existing ): Supplier {
		$config = $request['config'] ?? null;
		$config = is_array( $config ) ? $config : ( $existing->config ?? [] );

		return new Supplier(
			id: $existing->id ?? 0,
			name: sanitize_text_field( (string) ( $request['name'] ?? $existing->name ?? '' ) ),
			type: (string) ( $request['type'] ?? $existing->type ?? 'csv' ),
			config: $this->sanitizer->sanitize( $config, $existing ),
			schedule: (string) ( $request['schedule'] ?? $existing->schedule ?? 'manual' ),
			enabled: (bool) ( $request['enabled'] ?? $existing->enabled ?? true ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present( Supplier $supplier ): array {
		$runs   = $this->container->get( RunRepository::class );
		$last   = null !== $supplier->last_run_id ? $runs->find( $supplier->last_run_id ) : null;
		$active = $runs->active_for_supplier( $supplier->id );
		$next   = $this->container->get( Scheduler::class )->next_run( $supplier );
		$data   = $supplier->to_array();

		// Header values (API keys) never leave the server; viewers see the mapping read-only.
		$data['config']     = $this->sanitizer->redact( $supplier->config );
		$data['can_edit']   = current_user_can( Capabilities::MANAGE );
		$data['offers']     = $this->container->get( OfferRepository::class )->count_for_supplier( $supplier->id );
		$data['last_run']   = $last?->to_array();
		$data['active_run'] = $active?->to_array();
		$data['next_run']   = null !== $next ? gmdate( 'c', $next ) : null;

		return $data;
	}

	private function not_found(): WP_Error {
		return new WP_Error( 'supplier_sync_not_found', __( 'Supplier not found.', 'woo-supplier-sync' ), [ 'status' => 404 ] );
	}
}
