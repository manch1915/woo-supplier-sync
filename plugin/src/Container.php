<?php
/**
 * Minimal lazy service container.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync;

final class Container {

	/**
	 * @var array<class-string, callable(self): object>
	 */
	private array $factories = [];

	/**
	 * @var array<class-string, object>
	 */
	private array $instances = [];

	/**
	 * @template T of object
	 * @param class-string<T>     $id      Service class.
	 * @param callable(self): T   $factory Builds the service.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id Service class.
	 * @return T
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->instances[ $id ] ) ) {
			if ( ! isset( $this->factories[ $id ] ) ) {
				throw new \LogicException( esc_html( "Service $id is not registered." ) );
			}
			$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
		}

		$service = $this->instances[ $id ];
		assert( $service instanceof $id );
		return $service;
	}
}
