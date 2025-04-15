<?php
/**
 * REST routes for the admin app. Namespace supplier-sync/v1.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Rest;

use SupplierSync\Container;
use SupplierSync\Infrastructure\Capabilities;

final class RestRoutes {

	public const NAMESPACE = 'supplier-sync/v1';

	public function __construct( private readonly Container $container ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		$suppliers = new SuppliersController( $this->container );
		$runs      = new RunsController( $this->container );
		$settings  = new SettingsController( $this->container );

		$view   = self::can( Capabilities::VIEW );
		$run    = self::can( Capabilities::RUN );
		$manage = self::can( Capabilities::MANAGE );
		$id     = [
			'id' => [
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			],
		];

		$this->route( '/dashboard', 'GET', [ $settings, 'dashboard' ], $view );
		$this->route( '/meta', 'GET', [ $settings, 'meta' ], $view );

		$this->route( '/suppliers', 'GET', [ $suppliers, 'index' ], $view );
		$this->route( '/suppliers', 'POST', [ $suppliers, 'create' ], $manage, SuppliersController::schema() );
		$this->route( '/suppliers/(?P<id>\d+)', 'GET', [ $suppliers, 'show' ], $view, $id );
		$this->route( '/suppliers/(?P<id>\d+)', 'PUT', [ $suppliers, 'update' ], $manage, $id + SuppliersController::schema() );
		$this->route( '/suppliers/(?P<id>\d+)', 'DELETE', [ $suppliers, 'delete' ], $manage, $id );
		$this->route( '/suppliers/preview', 'POST', [ $suppliers, 'preview' ], $manage, SuppliersController::schema() );
		$this->route(
			'/suppliers/(?P<id>\d+)/runs',
			'POST',
			[ $runs, 'start' ],
			$run,
			$id + [
				'dry_run' => [
					'type'    => 'boolean',
					'default' => false,
				],
				'force'   => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);

		$this->route(
			'/runs',
			'GET',
			[ $runs, 'index' ],
			$view,
			[
				'supplier' => [
					'type'    => 'integer',
					'minimum' => 0,
				],
				'page'     => [
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				],
			]
		);
		$this->route( '/runs/(?P<id>\d+)', 'GET', [ $runs, 'show' ], $view, $id );
		$this->route( '/runs/(?P<id>\d+)/cancel', 'POST', [ $runs, 'cancel' ], $run, $id );
		$this->route(
			'/runs/(?P<id>\d+)/items',
			'GET',
			[ $runs, 'items' ],
			$view,
			$id + [
				'action' => [
					'type' => 'string',
					'enum' => [ '', 'created', 'updated', 'unpublished', 'error' ],
				],
				'search' => [
					'type'    => 'string',
					'default' => '',
				],
				'page'   => [
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				],
			]
		);

		$this->route( '/settings', 'GET', [ $settings, 'show' ], $view );
		$this->route( '/settings', 'PUT', [ $settings, 'update' ], $manage );
		$this->route( '/settings/telegram-test', 'POST', [ $settings, 'telegram_test' ], $manage );
		$this->route( '/pricing/preview', 'POST', [ $settings, 'pricing_preview' ], $view );
	}

	/**
	 * @param string                              $path       Route.
	 * @param string                              $method     HTTP method.
	 * @param callable                            $callback   Handler.
	 * @param callable                            $permission Permission check.
	 * @param array<string, array<string, mixed>> $args       Argument schema.
	 */
	private function route( string $path, string $method, callable $callback, callable $permission, array $args = [] ): void {
		register_rest_route(
			self::NAMESPACE,
			$path,
			[
				'methods'             => $method,
				'callback'            => $callback,
				'permission_callback' => $permission,
				'args'                => $args,
			]
		);
	}

	private static function can( string $capability ): callable {
		return static fn (): bool => current_user_can( $capability );
	}
}
