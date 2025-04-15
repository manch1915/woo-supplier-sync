<?php
/**
 * Settings, price calculator, dashboard numbers and reference data for the admin app.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Rest;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only stats over plugin and lookup tables.

use SupplierSync\Container;
use SupplierSync\Domain\Catalog\StockPolicy;
use SupplierSync\Domain\Pricing\ExchangeRates;
use SupplierSync\Domain\Pricing\PriceCalculator;
use SupplierSync\Domain\Pricing\PriceRule;
use SupplierSync\Domain\Pricing\PricingException;
use SupplierSync\Domain\Pricing\Rounding;
use SupplierSync\Domain\Supplier;
use SupplierSync\Feed\ReaderFactory;
use SupplierSync\Infrastructure\Capabilities;
use SupplierSync\Infrastructure\Persistence\RunRepository;
use SupplierSync\Infrastructure\Schema;
use SupplierSync\Infrastructure\Settings;
use SupplierSync\Notify\Notifier;
use SupplierSync\Sync\Run;
use SupplierSync\Sync\Scheduler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SettingsController {

	private Settings $settings;

	public function __construct( private readonly Container $container ) {
		$this->settings = $container->get( Settings::class );
	}

	public function show(): WP_REST_Response {
		return new WP_REST_Response( $this->redact( $this->settings->all() ) );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		$input   = (array) $request->get_json_params();
		$current = $this->settings->all();

		$this->settings->save( $this->sanitize( $input, $current ) );
		return new WP_REST_Response( $this->redact( $this->settings->all() ) );
	}

	public function telegram_test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$stored = (array) $this->settings->get( 'notifications' );
		$token  = (string) $request['telegram_token'];
		$token  = SupplierConfigSanitizer::SECRET_MASK === $token ? (string) ( $stored['telegram_token'] ?? '' ) : sanitize_text_field( $token );
		$chat   = sanitize_text_field( (string) $request['telegram_chat'] );

		$error = $this->container->get( Notifier::class )->test_telegram( $token, $chat );
		return null === $error
			? new WP_REST_Response( [ 'sent' => true ] )
			: new WP_Error( 'supplier_sync_telegram', $error, [ 'status' => 422 ] );
	}

	/**
	 * Price for a cost, with the steps behind it. Accepts unsaved rules so the rules screen
	 * can show the effect of an edit before saving.
	 */
	public function pricing_preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input    = (array) $request->get_json_params();
		$saved    = $this->settings->all();
		$rules    = isset( $input['rules'] ) ? $this->rules( (array) $input['rules'] ) : (array) $saved['rules'];
		$default  = isset( $input['default_rule'] ) ? $this->rule( (array) $input['default_rule'] + [ 'id' => 'default' ] ) : (array) $saved['default_rule'];
		$rates    = isset( $input['rates'] ) ? $this->rates( (array) $input['rates'] ) : (array) $saved['rates'];
		$category = absint( $input['category_id'] ?? 0 );

		$calculator = new PriceCalculator(
			array_map( static fn ( array $r ): PriceRule => PriceRule::from_array( $r ), array_values( $rules ) ),
			PriceRule::from_array( [ 'id' => 'default' ] + $default ),
			new ExchangeRates( $this->settings->store_currency(), $rates )
		);
		$lineage    = $category > 0 ? array_merge( [ $category ], array_map( 'intval', get_ancestors( $category, 'product_cat', 'taxonomy' ) ) ) : [];

		try {
			$result = $calculator->calculate(
				(float) ( $input['cost'] ?? 0 ),
				(string) ( $input['currency'] ?? $this->settings->store_currency() ),
				absint( $input['supplier_id'] ?? 0 ),
				$lineage,
				isset( $input['old_price'] ) && '' !== $input['old_price'] ? (float) $input['old_price'] : null
			);
		} catch ( PricingException $e ) {
			return new WP_Error( 'supplier_sync_pricing', $e->getMessage(), [ 'status' => 422 ] );
		}

		$rule = $calculator->find_rule( absint( $input['supplier_id'] ?? 0 ), $lineage, $result->cost );
		return new WP_REST_Response(
			[
				'cost'                 => $result->cost,
				'regular_price'        => $result->regular_price,
				'sale_price'           => $result->sale_price,
				'final_price'          => $result->final_price(),
				'margin'               => round( $result->final_price() - $result->cost, 2 ),
				'margin_percent'       => $result->cost > 0 ? round( ( $result->final_price() - $result->cost ) * 100 / $result->cost, 1 ) : null,
				'rule'                 => $rule->to_array(),
				'margin_floor_applied' => $result->margin_floor_applied,
				'currency'             => $this->settings->store_currency(),
			]
		);
	}

	public function meta(): WP_REST_Response {
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			]
		);

		$categories = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			if ( $term instanceof \WP_Term ) {
				$categories[] = [
					'id'     => $term->term_id,
					'name'   => html_entity_decode( $term->name ),
					'parent' => $term->parent,
					'count'  => $term->count,
				];
			}
		}

		return new WP_REST_Response(
			[
				'version'        => SUPPLIER_SYNC_VERSION,
				'store_currency' => $this->settings->store_currency(),
				'currencies'     => array_keys( get_woocommerce_currencies() ),
				'categories'     => $categories,
				'attributes'     => array_values( array_map( static fn ( $a ): string => (string) $a->attribute_label, wc_get_attribute_taxonomies() ) ),
				'types'          => ReaderFactory::TYPES,
				'schedules'      => array_keys( Scheduler::SCHEDULES ),
				'roundings'      => array_map( static fn ( Rounding $r ): string => $r->value, Rounding::cases() ),
				'update_fields'  => Supplier::UPDATABLE_FIELDS,
				'caps'           => [
					'view'   => current_user_can( Capabilities::VIEW ),
					'run'    => current_user_can( Capabilities::RUN ),
					'manage' => current_user_can( Capabilities::MANAGE ),
				],
				'urls'           => [
					'products'  => current_user_can( 'edit_products' ) ? admin_url( 'edit.php?post_type=product' ) : null,
					'shop'      => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ),
					'scheduler' => current_user_can( 'manage_woocommerce' ) ? admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=supplier_sync' ) : null,
				],
			]
		);
	}

	public function dashboard(): WP_REST_Response {
		global $wpdb;

		$runs   = $this->container->get( RunRepository::class );
		$recent = $runs->latest( 200 );

		$days = [];
		for ( $i = 13; $i >= 0; $i-- ) {
			$days[ gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = [
				'created'     => 0,
				'updated'     => 0,
				'unpublished' => 0,
				'errors'      => 0,
			];
		}
		$errors_24h = 0;
		foreach ( $recent as $run ) {
			if ( $run->dry_run || ( Run::COMPLETED !== $run->status && Run::FAILED !== $run->status ) ) {
				continue;
			}
			$day = substr( $run->created_at, 0, 10 );
			if ( isset( $days[ $day ] ) ) {
				foreach ( array_keys( $days[ $day ] ) as $counter ) {
					$days[ $day ][ $counter ] += (int) ( $run->stats[ $counter ] ?? 0 );
				}
			}
			if ( strtotime( $run->created_at . ' UTC' ) > time() - DAY_IN_SECONDS ) {
				$errors_24h += (int) ( $run->stats['errors'] ?? 0 ) + ( Run::FAILED === $run->status ? 1 : 0 );
			}
		}

		$stock = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.stock_status, COUNT(*) AS total FROM %i m INNER JOIN %i l ON l.product_id = m.product_id GROUP BY l.stock_status',
				Schema::table( 'products' ),
				$wpdb->prefix . 'wc_product_meta_lookup'
			),
			ARRAY_A
		);

		$active = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status IN (%s, %s)', Schema::table( 'runs' ), Run::QUEUED, Run::RUNNING ) );

		return new WP_REST_Response(
			[
				'products'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'products' ) ) ),
				'offers'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'offers' ) ) ),
				'merged'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM (SELECT unit_key FROM %i GROUP BY unit_key HAVING COUNT(DISTINCT supplier_id) > 1) t', Schema::table( 'offers' ) ) ),
				'stock'       => array_column( $stock ?? [], 'total', 'stock_status' ),
				'errors_24h'  => $errors_24h,
				'active_runs' => (int) $active,
				'activity'    => array_map(
					static fn ( string $day, array $counts ): array => [ 'day' => $day ] + $counts,
					array_keys( $days ),
					array_values( $days )
				),
				'recent_runs' => array_map(
					static fn ( Run $run ): array => $run->to_array(),
					array_slice( $recent, 0, 8 )
				),
			]
		);
	}

	/**
	 * @param array<string, mixed> $input   Request body.
	 * @param array<string, mixed> $current Stored settings.
	 * @return array<string, mixed>
	 */
	private function sanitize( array $input, array $current ): array {
		$notifications = (array) ( $input['notifications'] ?? $current['notifications'] );
		$token         = (string) ( $notifications['telegram_token'] ?? '' );
		$images        = (array) ( $input['images'] ?? $current['images'] );
		$stock         = (array) ( $input['stock'] ?? $current['stock'] );

		return [
			'rates'              => $this->rates( (array) ( $input['rates'] ?? $current['rates'] ) ),
			'default_rule'       => $this->rule( (array) ( $input['default_rule'] ?? $current['default_rule'] ) + [ 'id' => 'default' ] ),
			'rules'              => $this->rules( (array) ( $input['rules'] ?? $current['rules'] ) ),
			'stock'              => [
				'when_zero'      => StockPolicy::from_array( $stock )->when_zero,
				'allow_on_order' => ! empty( $stock['allow_on_order'] ),
			],
			'images'             => [
				'webp'      => ! empty( $images['webp'] ),
				'quality'   => min( 100, max( 40, (int) ( $images['quality'] ?? 82 ) ) ),
				'max_width' => min( 4000, max( 0, (int) ( $images['max_width'] ?? 1600 ) ) ),
			],
			'notifications'      => [
				'email'          => sanitize_email( (string) ( $notifications['email'] ?? '' ) ),
				'telegram_token' => SupplierConfigSanitizer::SECRET_MASK === $token ? (string) ( $current['notifications']['telegram_token'] ?? '' ) : sanitize_text_field( $token ),
				'telegram_chat'  => sanitize_text_field( (string) ( $notifications['telegram_chat'] ?? '' ) ),
				'only_problems'  => ! empty( $notifications['only_problems'] ),
			],
			'time_budget'        => min( 60, max( 5, (int) ( $input['time_budget'] ?? $current['time_budget'] ) ) ),
			'log_retention_days' => min( 365, max( 1, (int) ( $input['log_retention_days'] ?? $current['log_retention_days'] ) ) ),
		];
	}

	/**
	 * @param array<mixed> $rates Currency => rate.
	 * @return array<string, float>
	 */
	private function rates( array $rates ): array {
		$clean = [];
		foreach ( $rates as $code => $rate ) {
			$code = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $code ) );
			if ( 3 === strlen( $code ) && (float) $rate > 0 ) {
				$clean[ $code ] = (float) $rate;
			}
		}
		return $clean;
	}

	/**
	 * @param array<mixed> $rules Raw rules.
	 * @return list<array<string, mixed>>
	 */
	private function rules( array $rules ): array {
		$clean = [];
		foreach ( array_slice( $rules, 0, 200 ) as $rule ) {
			if ( is_array( $rule ) ) {
				$clean[] = $this->rule( $rule );
			}
		}
		return $clean;
	}

	/**
	 * @param array<mixed> $rule Raw rule.
	 * @return array<string, mixed>
	 */
	private function rule( array $rule ): array {
		$id            = sanitize_key( (string) ( $rule['id'] ?? '' ) );
		$rule['id']    = '' !== $id ? $id : 'rule-' . strtolower( wp_generate_password( 6, false ) );
		$rule['label'] = sanitize_text_field( (string) ( $rule['label'] ?? '' ) );
		return PriceRule::from_array( $rule )->to_array();
	}

	/**
	 * @param array<string, mixed> $settings Stored settings.
	 * @return array<string, mixed>
	 */
	private function redact( array $settings ): array {
		if ( '' !== (string) ( $settings['notifications']['telegram_token'] ?? '' ) ) {
			$settings['notifications']['telegram_token'] = SupplierConfigSanitizer::SECRET_MASK;
		}
		$settings['rates']          = (object) $settings['rates'];
		$settings['store_currency'] = $this->settings->store_currency();
		return $settings;
	}
}
