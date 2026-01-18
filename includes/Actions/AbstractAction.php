<?php
/**
 * Abstract Action
 *
 * Base class for all action handlers.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Actions;

use WhatsAppCommerceHub\Actions\Contracts\ActionHandlerInterface;
use WhatsAppCommerceHub\Contracts\Services\CartServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\Domain\Customer\CustomerService;
use WhatsAppCommerceHub\Support\Messaging\MessageBuilder;
use WhatsAppCommerceHub\ValueObjects\ActionResult;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AbstractAction
 *
 * Provides common functionality for action handlers.
 */
abstract class AbstractAction implements ActionHandlerInterface {

	/**
	 * Action name.
	 *
	 * @var string
	 */
	protected string $name;

	/**
	 * Action priority.
	 *
	 * @var int
	 */
	protected int $priority = 10;

	/**
	 * Cart service.
	 *
	 * @var CartServiceInterface|null
	 */
	protected ?CartServiceInterface $cartService = null;

	/**
	 * Customer service.
	 *
	 * @var CustomerService|null
	 */
	protected ?CustomerService $customerService = null;

	/**
	 * Logger instance.
	 *
	 * @var LoggerInterface|null
	 */
	protected ?LoggerInterface $logger = null;

	/**
	 * Set logger.
	 *
	 * @param LoggerInterface $logger Logger instance.
	 * @return void
	 */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * Set cart service.
	 *
	 * @param CartServiceInterface $cartService Cart service.
	 * @return void
	 */
	public function setCartService( CartServiceInterface $cartService ): void {
		$this->cartService = $cartService;
	}

	/**
	 * Set customer service.
	 *
	 * @param CustomerService $customerService Customer service.
	 * @return void
	 */
	public function setCustomerService( CustomerService $customerService ): void {
		$this->customerService = $customerService;
	}

	/**
	 * Check if this handler supports the given action name.
	 *
	 * @param string $actionName Action name to check.
	 * @return bool True if supported.
	 */
	public function supports( string $actionName ): bool {
		return $this->getName() === $actionName;
	}

	/**
	 * Get the action name this handler responds to.
	 *
	 * @return string Action name.
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * Get action priority.
	 *
	 * @return int Priority level.
	 */
	public function getPriority(): int {
		return $this->priority;
	}

	/**
	 * Create error result with message.
	 *
	 * @param string      $errorMessage Error message to display.
	 * @param string|null $nextState    Optional state to transition to on error.
	 * @return ActionResult
	 */
	protected function error( string $errorMessage, ?string $nextState = null ): ActionResult {
		$message = $this->createMessageBuilder()->text( $errorMessage );

		return ActionResult::failure( $errorMessage, [ $message ], null, $nextState );
	}

	/**
	 * Log action execution.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Additional data to log.
	 * @param string $level   Log level (info, warning, error).
	 * @return void
	 */
	protected function log( string $message, array $data = [], string $level = 'info' ): void {
		$logger = $this->logger ?? wch( LoggerInterface::class );
		$logger->log( $level, static::class . ': ' . $message, 'action', $data );
	}

	/**
	 * Get customer profile.
	 *
	 * @param string $phone Customer phone number.
	 * @return object|null Customer profile or null.
	 */
	protected function getCustomerProfile( string $phone ): ?object {
		$service = $this->customerService ?? wch( CustomerService::class );
		return $service->getOrCreateProfile( $phone );
	}

	/**
	 * Get cart for customer.
	 *
	 * @param string $phone Customer phone number.
	 * @return array|object|null Cart data or null.
	 */
	protected function getCart( string $phone ): array|object|null {
		if ( $this->cartService ) {
			return $this->cartService->getCart( $phone );
		}

		return wch( CartServiceInterface::class )->getCart( $phone );
	}

	/**
	 * Create a new message builder instance.
	 *
	 * @return MessageBuilder
	 */
	protected function createMessageBuilder(): MessageBuilder {
		return new MessageBuilder();
	}

	/**
	 * Format price for display.
	 *
	 * @param float $price Price to format.
	 * @return string Formatted price.
	 */
	protected function formatPrice( float $price ): string {
		return wc_price( $price );
	}

	/**
	 * Check if product has stock.
	 *
	 * @param int      $productId Product ID.
	 * @param int      $quantity  Quantity to check.
	 * @param int|null $variantId Optional variation ID.
	 * @return bool Whether stock is available.
	 */
	protected function hasStock( int $productId, int $quantity = 1, ?int $variantId = null ): bool {
		$product = wc_get_product( $variantId ?? $productId );

		if ( ! $product ) {
			return false;
		}

		if ( ! $product->managing_stock() ) {
			return true;
		}

		return $product->get_stock_quantity() >= $quantity;
	}

	/**
	 * Get product image URL.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string|null Image URL or null.
	 */
	protected function getProductImageUrl( \WC_Product $product ): ?string {
		$imageId = (int) $product->get_image_id();

		if ( $imageId > 0 ) {
			$imageUrl = wp_get_attachment_image_url( $imageId, 'full' );
			return $imageUrl ?: null;
		}

		return null;
	}

	/**
	 * Calculate cart total.
	 *
	 * @param array $items Cart items.
	 * @return float Total price.
	 */
	protected function calculateCartTotal( array $items ): float {
		$total = 0.0;

		foreach ( $items as $item ) {
			$product = wc_get_product( $item['product_id'] );

			if ( ! $product ) {
				continue;
			}

			$productId     = ! empty( $item['variant_id'] ) ? $item['variant_id'] : $item['product_id'];
			$actualProduct = wc_get_product( $productId );

			if ( $actualProduct ) {
				$total += (float) $actualProduct->get_price() * (int) $item['quantity'];
			}
		}

		return round( $total, 2 );
	}
}
