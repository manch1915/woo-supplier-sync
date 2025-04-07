<?php
/**
 * Plugin entry point: builds services and wires them to WordPress hooks.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync;

use SupplierSync\Cli\Command;
use SupplierSync\Domain\Catalog\Differ;
use SupplierSync\Feed\ReaderFactory;
use SupplierSync\Infrastructure\Capabilities;
use SupplierSync\Infrastructure\Http\FeedDownloader;
use SupplierSync\Infrastructure\Http\FeedStorage;
use SupplierSync\Infrastructure\Http\SiteCrawler;
use SupplierSync\Infrastructure\Persistence\OfferRepository;
use SupplierSync\Infrastructure\Persistence\ProductMapRepository;
use SupplierSync\Infrastructure\Persistence\RunRepository;
use SupplierSync\Infrastructure\Persistence\SupplierRepository;
use SupplierSync\Infrastructure\Schema;
use SupplierSync\Infrastructure\Settings;
use SupplierSync\Infrastructure\Woo\AttributeRegistry;
use SupplierSync\Infrastructure\Woo\CategoryResolver;
use SupplierSync\Infrastructure\Woo\ImageImporter;
use SupplierSync\Infrastructure\Woo\ProductSnapshot;
use SupplierSync\Infrastructure\Woo\ProductWriter;
use SupplierSync\Notify\Notifier;
use SupplierSync\Sync\RunService;
use SupplierSync\Sync\Scheduler;
use SupplierSync\Sync\SyncRunner;

final class Plugin {

	private const PRUNE_HOOK = 'supplier_sync_prune_logs';

	private static ?Container $container = null;

	public static function container(): Container {
		if ( null !== self::$container ) {
			return self::$container;
		}

		global $wpdb;
		$c = new Container();

		$c->set( Settings::class, static fn (): Settings => new Settings() );
		$c->set( SupplierRepository::class, static fn (): SupplierRepository => new SupplierRepository( $wpdb ) );
		$c->set( OfferRepository::class, static fn (): OfferRepository => new OfferRepository( $wpdb ) );
		$c->set( RunRepository::class, static fn (): RunRepository => new RunRepository( $wpdb ) );
		$c->set( ProductMapRepository::class, static fn (): ProductMapRepository => new ProductMapRepository( $wpdb ) );
		$c->set( FeedStorage::class, static fn (): FeedStorage => new FeedStorage() );
		$c->set( FeedDownloader::class, static fn ( Container $c ): FeedDownloader => new FeedDownloader( $c->get( FeedStorage::class ) ) );
		$c->set( SiteCrawler::class, static fn (): SiteCrawler => new SiteCrawler() );
		$c->set( ReaderFactory::class, static fn (): ReaderFactory => new ReaderFactory() );
		$c->set( AttributeRegistry::class, static fn (): AttributeRegistry => new AttributeRegistry() );
		$c->set( CategoryResolver::class, static fn (): CategoryResolver => new CategoryResolver() );
		$c->set( ProductSnapshot::class, static fn (): ProductSnapshot => new ProductSnapshot() );
		$c->set( ProductWriter::class, static fn ( Container $c ): ProductWriter => new ProductWriter( $c->get( AttributeRegistry::class ) ) );
		$c->set( ImageImporter::class, static fn ( Container $c ): ImageImporter => new ImageImporter( $wpdb, $c->get( Settings::class ) ) );
		$c->set( Notifier::class, static fn ( Container $c ): Notifier => new Notifier( $c->get( Settings::class ) ) );
		$c->set(
			SyncRunner::class,
			static fn ( Container $c ): SyncRunner => new SyncRunner(
				$c->get( RunRepository::class ),
				$c->get( SupplierRepository::class ),
				$c->get( OfferRepository::class ),
				$c->get( ProductMapRepository::class ),
				$c->get( Settings::class ),
				$c->get( ReaderFactory::class ),
				$c->get( FeedDownloader::class ),
				$c->get( FeedStorage::class ),
				$c->get( SiteCrawler::class ),
				$c->get( CategoryResolver::class ),
				$c->get( ProductSnapshot::class ),
				$c->get( ProductWriter::class ),
				new Differ(),
				$c->get( Notifier::class ),
			)
		);
		$c->set( RunService::class, static fn ( Container $c ): RunService => new RunService( $c->get( RunRepository::class ), $c->get( OfferRepository::class ), $c->get( SyncRunner::class ) ) );
		$c->set( Scheduler::class, static fn ( Container $c ): Scheduler => new Scheduler( $c->get( SupplierRepository::class ), $c->get( RunService::class ) ) );

		self::$container = $c;
		return $c;
	}

	public static function boot(): void {
		if ( ! class_exists( \WooCommerce::class ) ) {
			add_action( 'admin_notices', [ self::class, 'missing_woocommerce_notice' ] );
			return;
		}

		Schema::maybe_upgrade();
		$c = self::container();

		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain( 'woo-supplier-sync', false, dirname( plugin_basename( SUPPLIER_SYNC_FILE ) ) . '/languages' );
			}
		);

		add_action( SyncRunner::HOOK, static fn ( $run_id ) => $c->get( SyncRunner::class )->handle( (int) $run_id ) );
		add_action( Scheduler::HOOK, static fn ( $supplier_id ) => $c->get( Scheduler::class )->handle( (int) $supplier_id ) );
		add_action( ImageImporter::HOOK, static fn ( $product_id ) => $c->get( ImageImporter::class )->import_for_product( (int) $product_id ) );
		add_action(
			self::PRUNE_HOOK,
			static fn () => $c->get( RunRepository::class )->prune( (int) $c->get( Settings::class )->get( 'log_retention_days' ) )
		);
		add_action(
			'action_scheduler_init',
			static function (): void {
				if ( false === as_has_scheduled_action( self::PRUNE_HOOK, [], 'supplier-sync' ) ) {
					as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::PRUNE_HOOK, [], 'supplier-sync' );
				}
			}
		);

		add_action( 'before_delete_post', static fn ( $post_id ) => $c->get( ProductMapRepository::class )->forget_product( (int) $post_id ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'supplier-sync', new Command( $c ) );
		}
	}

	public static function activate(): void {
		Schema::install();
		Capabilities::grant();
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], 'supplier-sync' );
		}
	}

	public static function missing_woocommerce_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Supplier Sync needs WooCommerce to be installed and active.', 'woo-supplier-sync' )
		);
	}
}
