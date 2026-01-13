<?php
/**
 * Abandoned Cart Handler
 *
 * Handles abandoned cart tracking and reminder scheduling.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Features\AbandonedCart;

use WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager;
use WhatsAppCommerceHub\Core\Logger;
use WhatsAppCommerceHub\Clients\WhatsAppApiClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart Handler Class
 *
 * Tracks cart activity and detects abandonment.
 */
class CartHandler {

	/**
	 * Constructor
	 *
	 * @param SettingsManager   $settings Settings manager
	 * @param Logger            $logger Logger instance
	 * @param WhatsAppApiClient $apiClient WhatsApp API client
	 */
	public function __construct(
		private readonly SettingsManager $settings,
		private readonly Logger $logger,
		private readonly WhatsAppApiClient $apiClient
	) {
	}

	/**
	 * Process abandoned cart reminder job
	 *
	 * @param array<string, mixed> $args Job arguments with cart_id
	 */
	public function process( array $args ): void {
		$cartId = $args['cart_id'] ?? null;

		if ( ! $cartId ) {
			$this->logger->error( 'Invalid cart ID for abandoned cart job' );
			return;
		}

		$this->logger->info( 'Processing abandoned cart reminder', [ 'cart_id' => $cartId ] );

		$cart = $this->getCart( (int) $cartId );

		if ( ! $cart ) {
			$this->logger->warning( 'Cart not found for abandoned cart reminder', [ 'cart_id' => $cartId ] );
			return;
		}

		// Check if cart is still active
		if ( $cart['status'] !== 'active' ) {
			$this->logger->info(
				'Cart is no longer active, skipping reminder',
				[
					'cart_id' => $cartId,
					'status'  => $cart['status'],
				]
			);
			return;
		}

		// Check if cart is idle for configured hours
		$delayHours    = (int) $this->settings->get( 'notifications.abandoned_cart_delay_hours', 24 );
		$idleTimestamp = time() - ( $delayHours * HOUR_IN_SECONDS );
		$updatedAt     = strtotime( $cart['updated_at'] );

		if ( $updatedAt > $idleTimestamp ) {
			$this->logger->info(
				'Cart is not idle long enough, skipping reminder',
				[
					'cart_id'     => $cartId,
					'delay_hours' => $delayHours,
					'updated_at'  => $cart['updated_at'],
				]
			);
			return;
		}

		// Send reminder message
		$result = $this->sendReminder( $cart );

		if ( $result['success'] ) {
			$this->markReminderSent( (int) $cartId );

			$this->logger->info(
				'Abandoned cart reminder sent successfully',
				[
					'cart_id' => $cartId,
					'phone'   => $cart['customer_phone'] ?? '',
				]
			);
		} else {
			$this->logger->error(
				'Failed to send abandoned cart reminder',
				[
					'cart_id' => $cartId,
					'error'   => $result['error'] ?? 'Unknown error',
				]
			);
		}
	}

	/**
	 * Get cart by ID
	 *
	 * @param int $cartId Cart ID
	 * @return array<string, mixed>|null Cart data or null if not found
	 */
	private function getCart( int $cartId ): ?array {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_carts';

		$cart = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tableName} WHERE id = %d", $cartId ),
			ARRAY_A
		);

		if ( ! $cart ) {
			return null;
		}

		if ( isset( $cart['items'] ) && is_string( $cart['items'] ) ) {
			$decoded = json_decode( $cart['items'], true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$cart['items'] = $decoded;
			} else {
				$cart['items'] = [];
			}
		}

		return $cart;
	}

	/**
	 * Send reminder message
	 *
	 * @param array<string, mixed> $cart Cart data
	 * @return array<string, mixed> Result with success status
	 */
	private function sendReminder( array $cart ): array {
		try {
			$phone = $cart['customer_phone'] ?? '';
			if ( '' === $phone ) {
				return [
					'success' => false,
					'error'   => 'Missing customer phone number',
				];
			}
			$message = $this->buildReminderMessage( $cart );

			$result = $this->apiClient->sendMessage( $phone, $message );

			return [
				'success'    => true,
				'message_id' => $result['id'] ?? null,
			];
		} catch ( \Exception $e ) {
			return [
				'success' => false,
				'error'   => $e->getMessage(),
			];
		}
	}

	/**
	 * Build reminder message
	 *
	 * @param array<string, mixed> $cart Cart data
	 * @return string Reminder message
	 */
	private function buildReminderMessage( array $cart ): string {
		$phone        = $cart['customer_phone'] ?? '';
		$customerName = $phone ? $this->getCustomerName( $phone ) : 'there';
		$itemCount    = $this->getCartItemCount( $cart );
		$total        = number_format( (float) ( $cart['total'] ?? 0 ), 2 );

		return sprintf(
			"Hi %s! 👋\n\nYou have %d item(s) in your cart totaling $%s.\n\n" .
			"Complete your order now! Reply 'CART' to continue.",
			$customerName,
			$itemCount,
				$total
			);
	}

	/**
	 * Get cart item count from items array.
	 *
	 * @param array $cart Cart data.
	 * @return int Item count.
	 */
	private function getCartItemCount( array $cart ): int {
		$items = $cart['items'] ?? [];
		if ( is_string( $items ) ) {
			$decoded = json_decode( $items, true );
			$items   = JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ? $decoded : [];
		}

		$count = 0;
		foreach ( $items as $item ) {
			$count += (int) ( $item['quantity'] ?? 0 );
		}

		return $count;
	}

	/**
	 * Get customer name from profiles.
	 *
	 * @param string $phone Customer phone.
	 * @return string Customer name or fallback.
	 */
	private function getCustomerName( string $phone ): string {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_customer_profiles';

		$name = $wpdb->get_var(
			$wpdb->prepare( "SELECT name FROM {$tableName} WHERE phone = %s", $phone )
		);

		return $name ?: 'there';
	}

	/**
	 * Mark reminder as sent
	 *
	 * @param int $cartId Cart ID
	 */
	private function markReminderSent( int $cartId ): void {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_carts';

		$now = current_time( 'mysql' );
		$wpdb->update(
			$tableName,
			[
				'reminder_sent_at'  => $now,
				'reminder_1_sent_at' => $now,
			],
			[ 'id' => $cartId ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Track cart activity
	 *
	 * Updates the cart's last activity timestamp.
	 *
	 * @param int $cartId Cart ID
	 */
	public function trackActivity( int $cartId ): void {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_carts';

		$wpdb->update(
			$tableName,
			[ 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $cartId ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Mark cart as abandoned
	 *
	 * @param int $cartId Cart ID
	 */
	public function markAbandoned( int $cartId ): void {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_carts';

		$wpdb->update(
			$tableName,
			[
					'status'       => 'abandoned',
					'abandoned_at' => current_time( 'mysql' ),
				],
			[ 'id' => $cartId ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		$this->logger->info( 'Cart marked as abandoned', [ 'cart_id' => $cartId ] );
	}

	/**
	 * Mark cart as active
	 *
	 * @param int $cartId Cart ID
	 */
	public function markActive( int $cartId ): void {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_carts';

		$wpdb->update(
			$tableName,
			[
				'status'     => 'active',
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'id' => $cartId ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}
}
