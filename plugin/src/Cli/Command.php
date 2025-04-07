<?php
/**
 * WP-CLI commands for servers that run syncs from system cron.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Cli;

use SupplierSync\Container;
use SupplierSync\Domain\Supplier;
use SupplierSync\Feed\ReaderFactory;
use SupplierSync\Infrastructure\Persistence\RunRepository;
use SupplierSync\Infrastructure\Persistence\SupplierRepository;
use SupplierSync\Infrastructure\Woo\ImageImporter;
use SupplierSync\Sync\Run;
use SupplierSync\Sync\RunService;
use SupplierSync\Sync\Scheduler;
use WP_CLI;

/**
 * Manage supplier feeds and sync runs.
 */
final class Command {

	public function __construct( private readonly Container $container ) {}

	/**
	 * Lists supplier profiles.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$rows = array_map(
			static fn ( Supplier $s ): array => [
				'id'       => $s->id,
				'name'     => $s->name,
				'type'     => $s->type,
				'schedule' => $s->schedule,
				'enabled'  => $s->enabled ? 'yes' : 'no',
				'last_run' => $s->last_run_id ?? '-',
			],
			$this->container->get( SupplierRepository::class )->all()
		);
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, [ 'id', 'name', 'type', 'schedule', 'enabled', 'last_run' ] );
	}

	/**
	 * Runs a sync for one supplier or all enabled ones.
	 *
	 * ## OPTIONS
	 *
	 * <supplier>
	 * : Supplier ID, or "all".
	 *
	 * [--dry-run]
	 * : Report what would change without touching products.
	 *
	 * [--force]
	 * : Rewrite every product even if the feed did not change.
	 *
	 * [--async]
	 * : Queue the run for Action Scheduler instead of running it here.
	 *
	 * ## EXAMPLES
	 *
	 *     wp supplier-sync run 2 --dry-run
	 *     wp supplier-sync run all
	 *
	 * @param list<string>               $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Options.
	 */
	public function run( array $args, array $assoc_args ): void {
		$repo      = $this->container->get( SupplierRepository::class );
		$service   = $this->container->get( RunService::class );
		$dry_run   = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$force     = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$async     = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'async', false );
		$suppliers = 'all' === $args[0]
			? array_filter( $repo->all(), static fn ( Supplier $s ): bool => $s->enabled )
			: array_filter( [ $repo->find( (int) $args[0] ) ] );

		if ( [] === $suppliers ) {
			WP_CLI::error( 'Supplier not found.' );
		}

		$failed = false;
		foreach ( $suppliers as $supplier ) {
			$run = $service->start( $supplier, $dry_run, $async ? 'manual' : 'cli', $force );
			if ( $async ) {
				WP_CLI::log( sprintf( '%s: queued run #%d.', $supplier->name, $run->id ) );
				continue;
			}

			WP_CLI::log( sprintf( '%s: run #%d%s', $supplier->name, $run->id, $dry_run ? ' (dry run)' : '' ) );
			$last = '';
			$run  = $service->run_now(
				$run->id,
				static function ( Run $run ) use ( &$last ): void {
					$line = sprintf( '  %-6s %3d%%', $run->phase, (int) round( $run->progress() * 100 ) );
					if ( $line !== $last ) {
						WP_CLI::log( $line );
						$last = $line;
					}
				}
			);

			$this->print_run( $run );
			$failed = $failed || Run::FAILED === $run->status;
		}

		if ( $failed ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Shows recent runs or one run with its log.
	 *
	 * ## OPTIONS
	 *
	 * [<run>]
	 * : Run ID.
	 *
	 * [--action=<action>]
	 * : Filter log rows: created, updated, unpublished, error.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function status( array $args, array $assoc_args ): void {
		$runs = $this->container->get( RunRepository::class );

		if ( ! isset( $args[0] ) ) {
			$rows = array_map(
				static fn ( Run $r ): array => [
					'id'       => $r->id,
					'supplier' => $r->supplier_id,
					'dry_run'  => $r->dry_run ? 'yes' : 'no',
					'status'   => $r->status,
				] + $r->to_array()['stats'],
				$runs->latest( 15 )
			);
			WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'supplier', 'dry_run', 'status', 'created', 'updated', 'unchanged', 'unpublished', 'errors' ] );
			return;
		}

		$run = $runs->find( (int) $args[0] );
		if ( null === $run ) {
			WP_CLI::error( 'Run not found.' );
		}
		$this->print_run( $run );

		$items = $runs->items( $run->id, $assoc_args['action'] ?? null, '', 100, 0 );
		foreach ( $items['items'] as $item ) {
			WP_CLI::log( sprintf( '  [%s] %s %s', $item['action'], $item['title'], $item['message'] ) );
			foreach ( $item['changes'] as $path => $change ) {
				WP_CLI::log( sprintf( '      %s: %s -> %s', $path, wp_json_encode( $change[0] ), wp_json_encode( $change[1] ) ) );
			}
		}
	}

	/**
	 * Imports supplier profiles from a JSON file (an array of profiles).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the JSON file.
	 *
	 * @subcommand import-profiles
	 *
	 * @param list<string> $args Positional arguments.
	 */
	public function import_profiles( array $args ): void {
		$raw      = file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$profiles = json_decode( (string) $raw, true );
		if ( ! is_array( $profiles ) ) {
			WP_CLI::error( 'The file is not a JSON array.' );
		}

		$repo      = $this->container->get( SupplierRepository::class );
		$scheduler = $this->container->get( Scheduler::class );
		$existing  = [];
		foreach ( $repo->all() as $supplier ) {
			$existing[ $supplier->name ] = $supplier->id;
		}

		foreach ( $profiles as $profile ) {
			$type = (string) ( $profile['type'] ?? '' );
			if ( ! in_array( $type, ReaderFactory::TYPES, true ) ) {
				WP_CLI::warning( sprintf( 'Skipped "%s": unknown type.', (string) ( $profile['name'] ?? '?' ) ) );
				continue;
			}
			$name     = (string) $profile['name'];
			$supplier = $repo->save(
				new Supplier(
					id: $existing[ $name ] ?? 0,
					name: $name,
					type: $type,
					config: (array) ( $profile['config'] ?? [] ),
					schedule: (string) ( $profile['schedule'] ?? 'manual' ),
					enabled: (bool) ( $profile['enabled'] ?? true ),
				)
			);
			$scheduler->sync( $supplier );
			WP_CLI::log( sprintf( '%s #%d', isset( $existing[ $name ] ) ? 'Updated' : 'Created', $supplier->id ) );
		}
		WP_CLI::success( 'Profiles imported.' );
	}

	/**
	 * Downloads pending product images now instead of waiting for the queue.
	 *
	 * ## OPTIONS
	 *
	 * [--product=<id>]
	 * : Only this product.
	 *
	 * @subcommand import-images
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function import_images( array $args, array $assoc_args ): void {
		$importer = $this->container->get( ImageImporter::class );
		$ids      = isset( $assoc_args['product'] )
			? [ (int) $assoc_args['product'] ]
			: array_map(
				'intval',
				get_posts(
					[
						'post_type'      => 'product',
						'post_status'    => 'any',
						'fields'         => 'ids',
						'posts_per_page' => -1,
						'meta_key'       => '_supplier_sync_group', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					]
				)
			);

		foreach ( $ids as $index => $id ) {
			$importer->import_for_product( $id );
			as_unschedule_all_actions( ImageImporter::HOOK, [ $id ], 'supplier-sync' );
			if ( 0 === ( $index + 1 ) % 10 ) {
				WP_CLI::log( sprintf( '  %d of %d products', $index + 1, count( $ids ) ) );
			}
		}

		$stats = $importer->stats();
		WP_CLI::success( sprintf( 'Downloaded %d, reused %d, failed %d.', $stats['downloaded'], $stats['reused'], $stats['failed'] ) );
	}

	private function print_run( Run $run ): void {
		$stats = $run->to_array()['stats'];
		$line  = sprintf(
			'Run #%d %s: created %d, updated %d, unchanged %d, unpublished %d, skipped %d, errors %d',
			$run->id,
			$run->status,
			$stats['created'],
			$stats['updated'],
			$stats['unchanged'],
			$stats['unpublished'],
			$stats['skipped'],
			$stats['errors']
		);

		if ( Run::FAILED === $run->status ) {
			WP_CLI::warning( $line . ' - ' . (string) $run->error );
		} else {
			WP_CLI::success( $line );
		}
	}
}
