<?php
/**
 * Cart Repository Interface
 *
 * Interface for cart data access operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Repositories;

use WhatsAppCommerceHub\Domain\Cart\Cart;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CartRepositoryInterface
 *
 * Defines cart-specific data access operations.
 */
interface CartRepositoryInterface extends RepositoryInterface {

	/**
	 * Find an active cart by customer phone number.
	 *
	 * @param string $phone The customer phone number.
	 * @return Cart|null The cart or null if not found.
	 */
	public function findActiveByPhone( string $phone ): ?Cart;

	/**
	 * Find abandoned carts older than the specified threshold.
	 *
	 * @param int $hours_threshold Hours since last activity.
	 * @return array<Cart> Array of abandoned carts.
	 */
	public function findAbandonedCarts( int $hours_threshold = 24 ): array;

	/**
	 * Find expired carts.
	 *
	 * @return array<Cart> Array of expired carts.
	 */
	public function findExpiredCarts(): array;

	/**
	 * Mark a cart as abandoned.
	 *
	 * @param int $cart_id The cart ID.
	 * @return bool True on success.
	 */
	public function markAsAbandoned( int $cart_id ): bool;

	/**
	 * Mark that a recovery reminder was sent.
	 *
	 * @param int $cart_id        The cart ID.
	 * @param int $reminder_number The reminder sequence number (1, 2, 3).
	 * @return bool True on success.
	 */
	public function markReminderSent( int $cart_id, int $reminder_number ): bool;

	/**
	 * Mark a cart as recovered.
	 *
	 * @param int $cart_id  The cart ID.
	 * @param int $order_id The recovered order ID.
	 * @return bool True on success.
	 */
	public function markAsRecovered( int $cart_id, int $order_id ): bool;

	/**
	 * Get cart recovery statistics for a date range.
	 *
	 * @param \DateTimeInterface $start_date Start of the period.
	 * @param \DateTimeInterface $end_date   End of the period.
	 * @return array{total_abandoned: int, recovered_count: int, recovered_revenue: float, recovery_rate: float}
	 */
	public function getRecoveryStats( \DateTimeInterface $start_date, \DateTimeInterface $end_date ): array;

	/**
	 * Clean up expired carts.
	 *
	 * @param int $batch_size Number of carts to clean in one batch.
	 * @return int Number of carts cleaned.
	 */
	public function cleanupExpired( int $batch_size = 100 ): int;

	/**
	 * Get carts due for recovery reminders.
	 *
	 * @param int $reminder_number The reminder sequence (1, 2, 3).
	 * @param int $delay_hours     Hours since cart was abandoned.
	 * @param int $limit           Maximum carts to return.
	 * @return array<Cart> Carts ready for this reminder.
	 */
	public function findDueForReminder( int $reminder_number, int $delay_hours, int $limit = 50 ): array;
}
