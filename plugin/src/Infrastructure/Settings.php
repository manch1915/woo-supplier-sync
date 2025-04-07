<?php
/**
 * Plugin settings stored in one option, with defaults and typed accessors.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Infrastructure;

use SupplierSync\Domain\Catalog\StockPolicy;
use SupplierSync\Domain\Pricing\ExchangeRates;
use SupplierSync\Domain\Pricing\PriceCalculator;
use SupplierSync\Domain\Pricing\PriceRule;
use SupplierSync\Domain\Pricing\Rounding;

final class Settings {

	public const OPTION = 'supplier_sync_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'rates'              => [],
			'default_rule'       => [
				'markup_percent'     => 30,
				'markup_fixed'       => 0,
				'min_margin_percent' => 10,
				'rounding'           => Rounding::ENDING_99->value,
			],
			'rules'              => [],
			'stock'              => [
				'when_zero'      => StockPolicy::ZERO_OUT_OF_STOCK,
				'allow_on_order' => true,
			],
			'images'             => [
				'webp'      => true,
				'quality'   => 82,
				'max_width' => 1600,
			],
			'notifications'      => [
				'email'          => '',
				'telegram_token' => '',
				'telegram_chat'  => '',
				'only_problems'  => false,
			],
			'time_budget'        => 20,
			'log_retention_days' => 30,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, [] );
		return array_replace_recursive( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	/**
	 * @param array<string, mixed> $settings Full, sanitized settings.
	 */
	public function save( array $settings ): void {
		update_option( self::OPTION, $settings, false );
	}

	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	public function store_currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
	}

	public function calculator(): PriceCalculator {
		$all   = $this->all();
		$rules = array_map( static fn ( $rule ): PriceRule => PriceRule::from_array( (array) $rule ), array_values( (array) $all['rules'] ) );

		return new PriceCalculator(
			$rules,
			PriceRule::from_array( [ 'id' => 'default' ] + (array) $all['default_rule'] ),
			new ExchangeRates( $this->store_currency(), array_map( 'floatval', (array) $all['rates'] ) )
		);
	}

	public function stock_policy(): StockPolicy {
		return StockPolicy::from_array( (array) $this->all()['stock'] );
	}

	/**
	 * Changes when anything that affects prices or stock changes, which forces a full refresh.
	 */
	public function pricing_hash(): string {
		$all = $this->all();
		return md5( (string) wp_json_encode( [ $all['rates'], $all['default_rule'], $all['rules'], $all['stock'], $this->store_currency() ] ) );
	}

	public function time_budget(): int {
		return max( 5, min( 60, (int) $this->all()['time_budget'] ) );
	}
}
