<?php
/**
 * Abandoned Cart Recovery Service
 *
 * Handles automated abandoned cart recovery with multi-sequence messaging.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Features\AbandonedCart;

use WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager;
use WhatsAppCommerceHub\Core\Logger;
use WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher;
use WhatsAppCommerceHub\Presentation\Templates\TemplateManager;
use WhatsAppCommerceHub\Clients\WhatsAppApiClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abandoned Cart Recovery Service
 *
 * Implements a 3-sequence recovery system:
 * - Sequence 1: Initial reminder (1 hour after abandonment)
 * - Sequence 2: Follow-up with incentive (6 hours after)
 * - Sequence 3: Final reminder with urgency (24 hours after)
 */
class RecoveryService {

	/**
	 * Recovery reminder sequences with default delays
	 */
	private const SEQUENCE_DELAYS = [
		1 => 1,   // 1 hour
		2 => 6,   // 6 hours
		3 => 24,  // 24 hours
	];

	/**
	 * Template names for each sequence
	 */
	private const SEQUENCE_TEMPLATES = [
		1 => 'abandoned_cart_reminder_1',
		2 => 'abandoned_cart_reminder_2',
		3 => 'abandoned_cart_reminder_3',
	];

	/**
	 * Constructor
	 *
	 * @param SettingsManager   $settings Settings manager
	 * @param Logger            $logger Logger instance
	 * @param JobDispatcher     $jobDispatcher Job dispatcher for scheduling
	 * @param TemplateManager   $templateManager Template manager for messages
	 * @param WhatsAppApiClient $apiClient WhatsApp API client
	 */
	public function __construct(
		private readonly SettingsManager $settings,
		private readonly Logger $logger,
		private readonly JobDispatcher $jobDispatcher,
		private readonly TemplateManager $templateManager,
		private readonly WhatsAppApiClient $apiClient
	) {
	}

	/**
	 * Initialize recovery system
	 *
	 * Schedules recurring job to find and process abandoned carts.
	 */
	public function init(): void {
		if ( ! as_next_scheduled_action( 'wch_schedule_recovery_reminders', [], 'wch' ) ) {
			as_schedule_recurring_action(
				time(),
				30 * MINUTE_IN_SECONDS,
				'wch_schedule_recovery_reminders',
				[],
				'wch'
			);
		}
	}

	/**
	 * Schedule recovery reminders for abandoned carts
	 *
	 * Runs every 30 minutes to find carts that need recovery messages.
	 */
	public function scheduleRecoveryReminders(): void {
		if ( ! $this->isRecoveryEnabled() ) {
			return;
		}

		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_carts';

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName ) ) !== $tableName ) {
			return;
		}

		foreach ( self::SEQUENCE_DELAYS as $sequence => $delayHours ) {
			$carts = $this->findCartsForSequence( $sequence, $delayHours );

			foreach ( $carts as $cart ) {
				$this->scheduleRecoveryMessage( (int) $cart['id'], $sequence );
			}
		}
	}

	/**
	 * Schedule a recovery message for a specific cart
	 *
	 * @param int $cartId Cart ID
	 * @param int $sequence Sequence number (1, 2, or 3)
	 */
	private function scheduleRecoveryMessage( int $cartId, int $sequence ): void {
		$args = [
			'cart_id'  => $cartId,
			'sequence' => $sequence,
		];

		// Check if already scheduled
		if ( $this->jobDispatcher->isScheduled( 'wch_process_recovery_message', $args ) ) {
			return;
		}

		$this->jobDispatcher->dispatch( 'wch_process_recovery_message', $args, 0 );

		$this->logger->info(
			'Scheduled recovery message',
			[
				'cart_id'  => $cartId,
				'sequence' => $sequence,
			]
		);
	}

	/**
	 * Process recovery message job
	 *
	 * @param array<string, mixed> $args Job arguments containing cart_id and sequence
	 */
	public function processRecoveryMessage( array $args ): void {
		$cartId   = $args['cart_id'] ?? null;
		$sequence = $args['sequence'] ?? null;

		if ( ! $cartId || ! $sequence ) {
			$this->logger->error( 'Invalid arguments for recovery message job', [ 'args' => $args ] );
			return;
		}

		$cart = $this->getCart( (int) $cartId );

		if ( ! $cart ) {
			$this->logger->warning( 'Cart not found for recovery message', [ 'cart_id' => $cartId ] );
			return;
		}

		if ( ! $this->isCartEligible( $cart, (int) $sequence ) ) {
			$this->logger->info(
				'Cart not eligible for recovery message',
				[
					'cart_id'  => $cartId,
					'sequence' => $sequence,
					'status'   => $cart['status'] ?? 'unknown',
				]
			);
			return;
		}

		$result = $this->sendRecoveryMessage( $cart, (int) $sequence );

		if ( $result['success'] ) {
			$this->logger->info(
				'Recovery message sent successfully',
				[
					'cart_id'  => $cartId,
					'sequence' => $sequence,
				]
			);
		} else {
			$this->logger->error(
				'Failed to send recovery message',
				[
					'cart_id'  => $cartId,
					'sequence' => $sequence,
					'error'    => $result['error'] ?? 'Unknown error',
				]
			);
		}
	}

	/**
	 * Find carts eligible for a specific sequence
	 *
	 * @param int $sequence Sequence number (1, 2, or 3)
	 * @param int $delayHours Hours since last activity
	 * @return array<int, array<string, mixed>> Array of cart records
	 */
	private function findCartsForSequence( int $sequence, int $delayHours ): array {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_carts';

		$reminderColumn = $this->getReminderColumn( $sequence );
		if ( null === $reminderColumn ) {
			return [];
		}

		$previousColumn = $sequence > 1 ? $this->getReminderColumn( $sequence - 1 ) : null;
		$timeThreshold = gmdate( 'Y-m-d H:i:s', time() - ( $delayHours * HOUR_IN_SECONDS ) );

		$whereClauses = [
			"status = 'abandoned'",
			'updated_at <= %s',
			"{$reminderColumn} IS NULL",
			'customer_phone IS NOT NULL',
			"customer_phone != ''",
		];

		if ( $previousColumn ) {
			$whereClauses[] = "{$previousColumn} IS NOT NULL";
		}

		$query = $wpdb->prepare(
			"SELECT * FROM {$tableName}
            WHERE " . implode( ' AND ', $whereClauses ) . '
            ORDER BY updated_at ASC
            LIMIT 50',
			$timeThreshold
		);

		return $wpdb->get_results( $query, ARRAY_A ) ?: [];
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
	 * Check if cart is eligible for recovery
	 *
	 * @param array<string, mixed> $cart Cart data
	 * @param int                  $sequence Sequence number
	 * @return bool True if eligible
	 */
	private function isCartEligible( array $cart, int $sequence ): bool {
		// Must be abandoned
		if ( ( $cart['status'] ?? '' ) !== 'abandoned' ) {
			return false;
		}

		// Must have phone number
		if ( empty( $cart['customer_phone'] ) ) {
			return false;
		}

		// Must not have already received this sequence
		$currentSequence = $this->getSentSequence( $cart );
		if ( $currentSequence >= $sequence ) {
			return false;
		}

		// Check if user opted out
		if ( $this->hasOptedOut( $cart['customer_phone'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Send recovery message
	 *
	 * @param array<string, mixed> $cart Cart data
	 * @param int                  $sequence Sequence number
	 * @return array<string, mixed> Result with success status
	 */
	public function sendRecoveryMessage( array $cart, int $sequence ): array {
		try {
			$phone        = $cart['customer_phone'] ?? '';
			if ( '' === $phone ) {
				return [
					'success' => false,
					'error'   => 'Missing customer phone number',
				];
			}
			$templateName = $this->getTemplateNameForSequence( $sequence );

			// Generate coupon if discount enabled and sequence 2 or 3
			$couponCode = null;
			if ( $sequence >= 2 && $this->isDiscountEnabled() ) {
				$couponCode = $this->generateRecoveryCoupon( $cart );
			}

			// Build template variables
			$variables = $this->buildTemplateVariables( $cart, $sequence, $couponCode );

			// Render template
			$message = $this->templateManager->renderTemplate( $templateName, $variables );

			// Send via WhatsApp API
			$result = $this->apiClient->sendMessage( $phone, $message );

			// Mark reminder sent
			$this->markReminderSent( (int) $cart['id'], $sequence, $couponCode );

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
	 * Build template variables
	 *
	 * @param array<string, mixed> $cart Cart data
	 * @param int                  $sequence Sequence number
	 * @param string|null          $couponCode Optional coupon code
	 * @return array<string, string> Template variables
	 */
	private function buildTemplateVariables( array $cart, int $sequence, ?string $couponCode ): array {
		$phone        = $cart['customer_phone'] ?? '';
		$customerName = $phone ? $this->getCustomerName( $phone ) : 'there';
		$cartTotal    = number_format( (float) ( $cart['total'] ?? 0 ), 2 );
		$itemCount    = $this->getCartItemCount( $cart );

		$variables = [
			'1' => $customerName,
			'2' => (string) $itemCount,
			'3' => '$' . $cartTotal,
		];

		if ( $couponCode ) {
			$variables['4'] = $couponCode;
			$variables['5'] = $this->getDiscountDisplay();
		}

		return $variables;
	}

	/**
	 * Get total item count from cart items.
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
	 * Get reminder column name by sequence.
	 *
	 * @param int $sequence Sequence number.
	 * @return string|null Reminder column name or null if invalid.
	 */
	private function getReminderColumn( int $sequence ): ?string {
		return match ( $sequence ) {
			1 => 'reminder_1_sent_at',
			2 => 'reminder_2_sent_at',
			3 => 'reminder_3_sent_at',
			default => null,
		};
	}

	/**
	 * Determine highest reminder sequence already sent.
	 *
	 * @param array $cart Cart data.
	 * @return int Sent sequence number.
	 */
	private function getSentSequence( array $cart ): int {
		if ( ! empty( $cart['reminder_3_sent_at'] ) ) {
			return 3;
		}
		if ( ! empty( $cart['reminder_2_sent_at'] ) ) {
			return 2;
		}
		if ( ! empty( $cart['reminder_1_sent_at'] ) ) {
			return 1;
		}

		return 0;
	}

	/**
	 * Get customer name from phone
	 *
	 * @param string $phone Phone number
	 * @return string Customer name or default
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
	 * Generate recovery coupon
	 *
	 * @param array<string, mixed> $cart Cart data
	 * @return string|null Coupon code or null on failure
	 */
	private function generateRecoveryCoupon( array $cart ): ?string {
		if ( ! function_exists( 'wc_create_coupon' ) ) {
			return null;
		}

		$code           = 'RECOVER' . strtoupper( substr( md5( (string) $cart['id'] . time() ), 0, 8 ) );
		$discountAmount = $this->settings->get( 'abandoned_cart.discount_amount', 10 );

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_amount( $discountAmount );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_date_expires( time() + ( 7 * DAY_IN_SECONDS ) );
		$coupon->save();

		return $code;
	}

	/**
	 * Mark reminder as sent
	 *
	 * @param int         $cartId Cart ID
	 * @param int         $sequence Sequence number
	 * @param string|null $couponCode Optional coupon code
	 */
	private function markReminderSent( int $cartId, int $sequence, ?string $couponCode = null ): void {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_carts';

		$reminderColumn = $this->getReminderColumn( $sequence );
		if ( null === $reminderColumn ) {
			return;
		}

		$updateData = [
			$reminderColumn => current_time( 'mysql' ),
		];

		if ( $couponCode ) {
			$updateData['recovery_coupon_code'] = $couponCode;
		}

		$wpdb->update(
			$tableName,
			$updateData,
			[ 'id' => $cartId ],
			array_fill( 0, count( $updateData ), '%s' ),
			[ '%d' ]
		);
	}

	/**
	 * Mark cart as recovered
	 *
	 * @param int   $cartId Cart ID
	 * @param int   $orderId WooCommerce order ID
	 * @param float $revenue Order revenue
	 */
	public function markCartRecovered( int $cartId, int $orderId, float $revenue ): void {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_carts';

		$wpdb->update(
			$tableName,
			[
				'status'             => 'converted',
				'recovered'          => 1,
				'recovered_order_id' => $orderId,
				'recovered_at'       => current_time( 'mysql' ),
				'recovered_revenue'  => $revenue,
			],
			[ 'id' => $cartId ],
			[ '%s', '%d', '%d', '%s', '%f' ],
			[ '%d' ]
		);

		$this->logger->info(
			'Cart marked as recovered',
			[
				'cart_id'  => $cartId,
				'order_id' => $orderId,
				'revenue'  => $revenue,
			]
		);
	}

	/**
	 * Stop recovery sequence for a phone number
	 *
	 * @param string $phone Phone number
	 * @param string $reason Reason for stopping
	 */
	public function stopSequence( string $phone, string $reason = 'customer_action' ): void {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_carts';

		$now = current_time( 'mysql' );
		$wpdb->update(
			$tableName,
			[
				'reminder_1_sent_at' => $now,
				'reminder_2_sent_at' => $now,
				'reminder_3_sent_at' => $now,
			],
			[
				'customer_phone' => $phone,
				'status'         => 'abandoned',
			],
			[ '%s', '%s', '%s' ],
			[ '%s', '%s' ]
		);

		$this->logger->info(
			'Stopped recovery sequence',
			[
				'phone'  => $phone,
				'reason' => $reason,
			]
		);
	}

	/**
	 * Get recovery statistics
	 *
	 * @param int $days Number of days to look back
	 * @return array<string, mixed> Statistics
	 */
	public function getRecoveryStats( int $days = 7 ): array {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_carts';

		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
	                    COUNT(*) as total_abandoned,
	                    SUM(CASE WHEN recovered = 1 THEN 1 ELSE 0 END) as recovered_count,
	                    SUM(CASE WHEN recovered = 1 THEN recovered_revenue ELSE 0 END) as recovered_revenue,
	                    AVG(
							CASE
								WHEN recovered = 1 THEN
									CASE
										WHEN reminder_3_sent_at IS NOT NULL THEN 3
										WHEN reminder_2_sent_at IS NOT NULL THEN 2
										WHEN reminder_1_sent_at IS NOT NULL THEN 1
										ELSE NULL
									END
								ELSE NULL
							END
						) as avg_sequence
	                FROM {$tableName}
	                WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);

		$recoveryRate = 0;
		if ( $stats['total_abandoned'] > 0 ) {
			$recoveryRate = ( $stats['recovered_count'] / $stats['total_abandoned'] ) * 100;
		}

		return [
			'total_abandoned'   => (int) $stats['total_abandoned'],
			'recovered_count'   => (int) $stats['recovered_count'],
			'recovery_rate'     => round( $recoveryRate, 2 ),
			'recovered_revenue' => (float) $stats['recovered_revenue'],
			'avg_sequence'      => round( (float) $stats['avg_sequence'], 1 ),
		];
	}

	/**
	 * Check if recovery is enabled
	 *
	 * @return bool True if enabled
	 */
	private function isRecoveryEnabled(): bool {
		return (bool) $this->settings->get( 'abandoned_cart.enabled', false );
	}

	/**
	 * Check if user has opted out
	 *
	 * @param string $phone Phone number
	 * @return bool True if opted out
	 */
	private function hasOptedOut( string $phone ): bool {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_customer_profiles';

		$optOut = $wpdb->get_var(
			$wpdb->prepare( "SELECT notification_opt_out FROM {$tableName} WHERE phone = %s", $phone )
		);

		return (int) $optOut === 1;
	}

	/**
	 * Get sequence delay in hours
	 *
	 * @param int $sequence Sequence number
	 * @return int Delay in hours
	 */
	private function getSequenceDelay( int $sequence ): int {
		$configKey = "abandoned_cart.sequence_{$sequence}_delay";
		return (int) $this->settings->get( $configKey, self::SEQUENCE_DELAYS[ $sequence ] ?? 1 );
	}

	/**
	 * Get template name for sequence
	 *
	 * @param int $sequence Sequence number
	 * @return string Template name
	 */
	private function getTemplateNameForSequence( int $sequence ): string {
		$configKey = "abandoned_cart.sequence_{$sequence}_template";
		return (string) $this->settings->get( $configKey, self::SEQUENCE_TEMPLATES[ $sequence ] ?? '' );
	}

	/**
	 * Check if discount is enabled
	 *
	 * @return bool True if enabled
	 */
	private function isDiscountEnabled(): bool {
		return (bool) $this->settings->get( 'abandoned_cart.discount_enabled', false );
	}

	/**
	 * Get discount display text
	 *
	 * @return string Discount display
	 */
	private function getDiscountDisplay(): string {
		$amount = (int) $this->settings->get( 'abandoned_cart.discount_amount', 10 );
		return "{$amount}% OFF";
	}
}
