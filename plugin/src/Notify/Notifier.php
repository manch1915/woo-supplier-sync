<?php
/**
 * Run summaries to Telegram and e-mail.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

namespace SupplierSync\Notify;

use SupplierSync\Domain\Supplier;
use SupplierSync\Infrastructure\Settings;
use SupplierSync\Sync\Run;

final class Notifier {

	public function __construct( private readonly Settings $settings ) {}

	public function run_finished( Run $run, Supplier $supplier ): void {
		$config  = (array) $this->settings->get( 'notifications' );
		$failed  = Run::FAILED === $run->status;
		$errors  = (int) ( $run->stats['errors'] ?? 0 );
		$problem = $failed || $errors > 0;

		// Dry runs are started by a person who is looking at the screen.
		if ( $run->dry_run && ! $failed ) {
			return;
		}
		if ( ! empty( $config['only_problems'] ) && ! $problem ) {
			return;
		}

		$lines = $this->summary( $run, $supplier );

		if ( ! empty( $config['telegram_token'] ) && ! empty( $config['telegram_chat'] ) ) {
			$this->telegram( (string) $config['telegram_token'], (string) $config['telegram_chat'], $lines );
		}

		$email = (string) ( $config['email'] ?? '' );
		if ( '' !== $email && is_email( $email ) ) {
			$subject = sprintf(
				/* translators: 1: supplier name, 2: status. */
				__( '[Supplier Sync] %1$s: %2$s', 'woo-supplier-sync' ),
				$supplier->name,
				$failed ? __( 'failed', 'woo-supplier-sync' ) : __( 'done', 'woo-supplier-sync' )
			);
			wp_mail( $email, $subject, implode( "\n", $lines ) );
		}
	}

	/**
	 * Sends a test message, returns an error text or null.
	 */
	public function test_telegram( string $token, string $chat ): ?string {
		return $this->telegram( $token, $chat, [ __( 'Supplier Sync is connected. Run reports will arrive here.', 'woo-supplier-sync' ) ] );
	}

	/**
	 * @return list<string>
	 */
	private function summary( Run $run, Supplier $supplier ): array {
		$stats = $run->stats;
		$lines = [
			sprintf(
				/* translators: 1: supplier, 2: run ID. */
				__( '%1$s, run #%2$d', 'woo-supplier-sync' ),
				$supplier->name,
				$run->id
			),
		];

		if ( Run::FAILED === $run->status ) {
			/* translators: %s: error message. */
			$lines[] = sprintf( __( 'Failed: %s', 'woo-supplier-sync' ), (string) $run->error );
		} else {
			$lines[] = sprintf(
				/* translators: 1: created, 2: updated, 3: unpublished, 4: unchanged, 5: errors. */
				__( 'Created %1$d, updated %2$d, taken off sale %3$d, unchanged %4$d, errors %5$d.', 'woo-supplier-sync' ),
				(int) ( $stats['created'] ?? 0 ),
				(int) ( $stats['updated'] ?? 0 ),
				(int) ( $stats['unpublished'] ?? 0 ),
				(int) ( $stats['unchanged'] ?? 0 ),
				(int) ( $stats['errors'] ?? 0 )
			);
		}

		$lines[] = admin_url( 'admin.php?page=supplier-sync#/runs/' . $run->id );
		return $lines;
	}

	/**
	 * @param string       $token Bot token.
	 * @param string       $chat  Chat ID.
	 * @param list<string> $lines Message lines.
	 */
	private function telegram( string $token, string $chat, array $lines ): ?string {
		if ( ! preg_match( '/^\d+:[\w-]+$/', $token ) ) {
			return __( 'The bot token looks wrong.', 'woo-supplier-sync' );
		}

		$response = wp_remote_post(
			'https://api.telegram.org/bot' . $token . '/sendMessage',
			[
				'timeout' => 10,
				'body'    => [
					'chat_id'                  => $chat,
					'text'                     => implode( "\n", $lines ),
					'disable_web_page_preview' => 'true',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			return is_array( $body ) ? (string) ( $body['description'] ?? 'Telegram error' ) : 'Telegram error';
		}
		return null;
	}
}
