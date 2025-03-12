<?php
/**
 * Manual exchange rates to the store currency.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Domain\Pricing;

final class ExchangeRates {

	private const ALIASES = [ 'RUR' => 'RUB' ];

	/**
	 * @var array<string, float>
	 */
	private array $rates = [];

	/**
	 * @param string               $store_currency Store currency code.
	 * @param array<string, float> $rates          Units of store currency per 1 unit of the key currency.
	 */
	public function __construct( private readonly string $store_currency, array $rates ) {
		foreach ( $rates as $code => $rate ) {
			if ( $rate > 0 ) {
				$this->rates[ self::normalize( (string) $code ) ] = (float) $rate;
			}
		}
	}

	/**
	 * @param float  $amount   Amount in the source currency.
	 * @param string $currency Source currency, empty means store currency.
	 *
	 * @throws PricingException When the rate is unknown.
	 */
	public function convert( float $amount, string $currency ): float {
		$code = self::normalize( $currency );
		if ( '' === $code || self::normalize( $this->store_currency ) === $code ) {
			return $amount;
		}
		if ( ! isset( $this->rates[ $code ] ) ) {
			throw new PricingException( sprintf( 'No exchange rate for %s.', $code ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $amount * $this->rates[ $code ];
	}

	public function store_currency(): string {
		return $this->store_currency;
	}

	private static function normalize( string $code ): string {
		$code = strtoupper( trim( $code ) );
		return self::ALIASES[ $code ] ?? $code;
	}
}
