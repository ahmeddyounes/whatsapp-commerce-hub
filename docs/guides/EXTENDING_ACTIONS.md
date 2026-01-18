# Extending Actions: Developer Guide

This guide provides practical examples for extending the WhatsApp Commerce Hub with custom action handlers.

## Table of Contents

1. [Quick Start](#quick-start)
2. [Basic Custom Action](#basic-custom-action)
3. [Advanced Examples](#advanced-examples)
4. [Testing Custom Actions](#testing-custom-actions)
5. [Troubleshooting](#troubleshooting)

---

## Quick Start

### Prerequisites

- WhatsApp Commerce Hub installed and activated
- Basic understanding of WordPress hooks
- PHP 8.0 or higher
- Familiarity with object-oriented PHP

### Three Steps to Add a Custom Action

1. **Create** a handler class implementing `ActionHandlerInterface`
2. **Register** the handler using the `wch_register_action_handlers` hook
3. **Trigger** the action via intent classification, pattern matching, or button payloads

---

## Basic Custom Action

### Example 1: Simple Help Action

Create a custom help action that provides detailed assistance:

```php
<?php
/**
 * Custom Help Action
 * File: wp-content/plugins/my-wch-extension/includes/Actions/CustomHelpAction.php
 */

namespace MyWCHExtension\Actions;

use WhatsAppCommerceHub\Actions\AbstractAction;
use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

class CustomHelpAction extends AbstractAction {

	/**
	 * Primary action name
	 */
	public function getName(): string {
		return 'custom_help';
	}

	/**
	 * Support multiple action names
	 */
	public function supports( string $actionName ): bool {
		return in_array( $actionName, [
			'custom_help',
			'help',
			'assistance',
			'support'
		], true );
	}

	/**
	 * Higher priority to override default help
	 */
	public function getPriority(): int {
		return 20; // Higher than default (10)
	}

	/**
	 * Handle the action
	 */
	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult {
		// Log action
		$this->log( 'Custom help requested', [
			'phone' => $phone,
			'state' => $context->getCurrentState(),
		] );

		// Get customer profile for personalization
		$customer = $this->getCustomerProfile( $phone );
		$firstName = $customer->getFirstName() ?: 'there';

		// Build help message
		$message = $this->createMessageBuilder()
			->text( "Hi {$firstName}! 👋\n\nHere's how I can help you:" )
			->text( "\n\n🛍️ *Shopping*" )
			->text( "\n- Browse products and categories" )
			->text( "\n- Add items to your cart" )
			->text( "\n- View your cart and checkout" )
			->text( "\n\n💬 *Support*" )
			->text( "\n- Track your orders" )
			->text( "\n- Contact customer service" )
			->text( "\n- Get product recommendations" )
			->addButton( '🏠 Main Menu', 'show_main_menu' )
			->addButton( '🛒 View Cart', 'show_cart' )
			->addButton( '📦 My Orders', 'show_orders' );

		// Return success
		return ActionResult::success(
			messages: [ $message ],
			nextState: 'browsing',
			contextUpdates: [
				'last_help_viewed' => time(),
			]
		);
	}
}
```

### Register the Action

```php
<?php
/**
 * Plugin initialization
 * File: wp-content/plugins/my-wch-extension/my-wch-extension.php
 */

add_action( 'wch_register_action_handlers', function( $registry, $container ) {
	// Create handler instance
	$handler = new \MyWCHExtension\Actions\CustomHelpAction();

	// Inject dependencies
	$handler->setLogger( $container->get( 'logger' ) );
	$handler->setCartService( $container->get( \WhatsAppCommerceHub\Contracts\Services\CartServiceInterface::class ) );
	$handler->setCustomerService( $container->get( \WhatsAppCommerceHub\Services\CustomerService::class ) );

	// Register with registry
	$registry->register( $handler );
}, 10, 2 );
```

---

## Advanced Examples

### Example 2: Product Recommendation Action

Create an action that recommends products based on customer history:

```php
<?php

namespace MyWCHExtension\Actions;

use WhatsAppCommerceHub\Actions\AbstractAction;
use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

class ProductRecommendationAction extends AbstractAction {

	public function getName(): string {
		return 'recommend_products';
	}

	public function supports( string $actionName ): bool {
		return in_array( $actionName, [
			'recommend_products',
			'recommendations',
			'suggest_products',
		], true );
	}

	public function getPriority(): int {
		return 10;
	}

	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult {
		// Get customer profile and purchase history
		$customer = $this->getCustomerProfile( $phone );

		// Get recommendations (custom logic)
		$recommendations = $this->getRecommendations( $customer, $params );

		if ( empty( $recommendations ) ) {
			return $this->error(
				'No recommendations available at the moment.',
				'browsing'
			);
		}

		// Build message with recommendations
		$message = $this->createMessageBuilder()
			->text( "🎯 *Recommended for You*\n\n" );

		foreach ( $recommendations as $index => $product ) {
			$price = $this->formatPrice( (float) $product->get_price() );

			$message->text(
				sprintf(
					"%d. *%s*\n   %s\n   Price: %s\n\n",
					$index + 1,
					$product->get_name(),
					wp_trim_words( $product->get_short_description(), 15 ),
					$price
				)
			);

			// Add button for each product
			$message->addButton(
				"View {$product->get_name()}",
				'show_product',
				[ 'product_id' => $product->get_id() ]
			);

			// Limit to 3 products
			if ( $index >= 2 ) {
				break;
			}
		}

		$message->addButton( '🏠 Main Menu', 'show_main_menu' );

		return ActionResult::success(
			messages: [ $message ],
			nextState: 'browsing',
			contextUpdates: [
				'recommendations_shown' => array_map( fn( $p ) => $p->get_id(), $recommendations ),
				'recommendation_timestamp' => time(),
			]
		);
	}

	/**
	 * Get product recommendations based on customer data
	 */
	private function getRecommendations( $customer, array $params ): array {
		// Get customer's past orders
		$orders = wc_get_orders( [
			'customer_id' => $customer->getId(),
			'limit'       => 5,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		// Collect purchased product IDs and categories
		$purchasedCategories = [];
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( $product ) {
					$categories = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'ids' ] );
					$purchasedCategories = array_merge( $purchasedCategories, $categories );
				}
			}
		}

		// Get unique categories
		$purchasedCategories = array_unique( $purchasedCategories );

		if ( empty( $purchasedCategories ) ) {
			// No purchase history, return popular products
			return $this->getPopularProducts();
		}

		// Find products in same categories
		$args = [
			'status'         => 'publish',
			'limit'          => 6,
			'orderby'        => 'popularity',
			'tax_query'      => [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $purchasedCategories,
					'operator' => 'IN',
				],
			],
		];

		return wc_get_products( $args );
	}

	/**
	 * Get popular products as fallback
	 */
	private function getPopularProducts(): array {
		return wc_get_products( [
			'status'  => 'publish',
			'limit'   => 6,
			'orderby' => 'popularity',
		] );
	}
}
```

### Example 3: Custom Checkout Step

Add a custom step to collect gift message during checkout:

```php
<?php

namespace MyWCHExtension\Actions;

use WhatsAppCommerceHub\Actions\AbstractAction;
use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

class GiftMessageAction extends AbstractAction {

	public function getName(): string {
		return 'request_gift_message';
	}

	public function supports( string $actionName ): bool {
		return $actionName === 'request_gift_message';
	}

	public function getPriority(): int {
		return 10;
	}

	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult {
		// Check if gift message is provided
		if ( isset( $params['gift_message'] ) && ! empty( $params['gift_message'] ) ) {
			return $this->processGiftMessage( $phone, $params['gift_message'], $context );
		}

		// Ask for gift message
		$message = $this->createMessageBuilder()
			->text( "🎁 *Gift Message*\n\n" )
			->text( "Would you like to add a gift message to this order?\n\n" )
			->text( "You can include a personal note that will be included with the delivery." )
			->addButton( 'Add Message', 'gift_message_yes' )
			->addButton( 'Skip', 'gift_message_skip' );

		return ActionResult::success(
			messages: [ $message ],
			nextState: 'checkout',
			contextUpdates: [
				'awaiting_gift_message' => true,
			]
		);
	}

	/**
	 * Process the gift message
	 */
	private function processGiftMessage( string $phone, string $message, ConversationContext $context ): ActionResult {
		// Validate message length
		if ( strlen( $message ) > 200 ) {
			return $this->error(
				'Gift message is too long. Please keep it under 200 characters.',
				'checkout'
			);
		}

		// Store in context
		$response = $this->createMessageBuilder()
			->text( "✅ Gift message added!\n\n" )
			->text( "Your message:\n_{$message}_\n\n" )
			->text( "Let's continue with your order." )
			->addButton( 'Continue to Payment', 'process_payment' )
			->addButton( 'Edit Message', 'request_gift_message' );

		return ActionResult::success(
			messages: [ $response ],
			nextState: 'checkout',
			contextUpdates: [
				'gift_message' => $message,
				'awaiting_gift_message' => false,
			]
		);
	}
}
```

### Example 4: Integration with External API

Create an action that checks product availability via external API:

```php
<?php

namespace MyWCHExtension\Actions;

use WhatsAppCommerceHub\Actions\AbstractAction;
use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

class CheckExternalStockAction extends AbstractAction {

	private string $apiEndpoint = 'https://api.example.com/stock';
	private string $apiKey;

	public function __construct() {
		$this->apiKey = get_option( 'my_wch_external_api_key', '' );
	}

	public function getName(): string {
		return 'check_external_stock';
	}

	public function supports( string $actionName ): bool {
		return $actionName === 'check_external_stock';
	}

	public function getPriority(): int {
		return 10;
	}

	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult {
		// Validate parameters
		if ( empty( $params['product_id'] ) ) {
			return $this->error( 'Product ID is required.', 'browsing' );
		}

		$productId = absint( $params['product_id'] );
		$product   = wc_get_product( $productId );

		if ( ! $product ) {
			return $this->error( 'Product not found.', 'browsing' );
		}

		// Check external stock
		$this->log( 'Checking external stock', [ 'product_id' => $productId ] );

		try {
			$stockData = $this->checkExternalApi( $product->get_sku() );

			$inStock = $stockData['available'] ?? false;
			$quantity = $stockData['quantity'] ?? 0;
			$nextRestock = $stockData['next_restock'] ?? null;

			// Build response message
			$message = $this->createMessageBuilder()
				->text( "*{$product->get_name()}*\n\n" );

			if ( $inStock && $quantity > 0 ) {
				$message->text( "✅ *In Stock*\n" )
					->text( "Available quantity: {$quantity}\n\n" )
					->addButton( 'Add to Cart', 'add_to_cart', [ 'product_id' => $productId ] );
			} else {
				$message->text( "❌ *Out of Stock*\n" );

				if ( $nextRestock ) {
					$message->text( "Expected restock: {$nextRestock}\n\n" );
				}

				$message->addButton( 'Notify When Available', 'stock_notification', [ 'product_id' => $productId ] );
			}

			$message->addButton( 'Back to Browse', 'show_main_menu' );

			return ActionResult::success(
				messages: [ $message ],
				nextState: 'browsing',
				contextUpdates: [
					'last_stock_check' => time(),
					'external_stock' => $stockData,
				]
			);

		} catch ( \Exception $e ) {
			$this->log( 'External stock check failed', [
				'error' => $e->getMessage(),
			], 'error' );

			return $this->error(
				'Unable to check stock availability. Please try again later.',
				'browsing'
			);
		}
	}

	/**
	 * Check external API for stock information
	 */
	private function checkExternalApi( string $sku ): array {
		$response = wp_remote_post(
			$this->apiEndpoint,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $this->apiKey,
					'Content-Type'  => 'application/json',
				],
				'body'    => json_encode( [ 'sku' => $sku ] ),
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \Exception( 'Invalid API response' );
		}

		return $data;
	}
}
```

---

## Testing Custom Actions

### Unit Testing

Create PHPUnit tests for your custom actions:

```php
<?php

namespace MyWCHExtension\Tests;

use MyWCHExtension\Actions\CustomHelpAction;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;
use PHPUnit\Framework\TestCase;

class CustomHelpActionTest extends TestCase {

	public function test_handle_returns_success(): void {
		$action = new CustomHelpAction();

		$context = new ConversationContext(
			phone: '1234567890',
			currentState: 'browsing',
			stateData: [],
			slots: [],
			history: []
		);

		$result = $action->handle( '1234567890', [], $context );

		$this->assertTrue( $result->isSuccess() );
		$this->assertEquals( 'browsing', $result->getNextState() );
		$this->assertCount( 1, $result->getMessages() );
	}

	public function test_supports_multiple_action_names(): void {
		$action = new CustomHelpAction();

		$this->assertTrue( $action->supports( 'custom_help' ) );
		$this->assertTrue( $action->supports( 'help' ) );
		$this->assertTrue( $action->supports( 'assistance' ) );
		$this->assertFalse( $action->supports( 'unknown_action' ) );
	}

	public function test_priority_overrides_default(): void {
		$action = new CustomHelpAction();

		$this->assertGreaterThan( 10, $action->getPriority() );
	}
}
```

### Integration Testing

Test your action in the full conversation flow:

```php
<?php
// In wp-admin or via WP-CLI

// Simulate a webhook message
$processor = wch( \WhatsAppCommerceHub\Queue\Processors\WebhookMessageProcessor::class );

$webhookData = [
	'from'      => '1234567890',
	'message'   => [
		'type' => 'text',
		'text' => 'help',
	],
	'timestamp' => time(),
];

// Process the message
$processor->process( $webhookData );

// Check conversation state
$conversation = wch( \WhatsAppCommerceHub\Repositories\ConversationRepository::class )
	->findByPhone( '1234567890' );

// Verify state and context
var_dump( $conversation->getState() );
var_dump( $conversation->getContext() );
```

---

## Troubleshooting

### Handler Not Being Called

1. **Check Registration**: Ensure your handler is registered in the `wch_register_action_handlers` hook
2. **Verify Priority**: Higher priority handlers execute first; check if another handler is intercepting
3. **Check `supports()` Method**: Ensure it returns true for the action name being triggered
4. **Enable Debug Logging**: Set `WP_DEBUG_LOG` to true and check the logs

```php
// Enable debug logging in wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

### Action Returning Errors

1. **Validate Parameters**: Check that all required parameters are present
2. **Check Dependencies**: Ensure services (cart, customer) are properly injected
3. **Review Error Logs**: Check `/wp-content/debug.log` for exceptions
4. **Add Logging**: Use `$this->log()` to trace execution

```php
public function handle( string $phone, array $params, ConversationContext $context ): ActionResult {
	$this->log( 'Action started', [
		'phone'  => $phone,
		'params' => $params,
		'state'  => $context->getCurrentState(),
	] );

	// Your logic here

	$this->log( 'Action completed successfully' );
}
```

### Messages Not Sending

1. **Check ActionResult**: Ensure you're returning messages in the result
2. **Verify MessageBuilder**: Check that messages are properly built
3. **WhatsApp API**: Verify WhatsApp API credentials and webhook configuration
4. **Queue Status**: Check that background jobs are processing

### Context Not Persisting

1. **Return Context Updates**: Ensure you include `contextUpdates` in `ActionResult::success()`
2. **Check Serialization**: Ensure context data is JSON-serializable
3. **Verify Database**: Check that conversation records are being saved

```php
return ActionResult::success(
	messages: [ $message ],
	nextState: 'browsing',
	contextUpdates: [
		'my_custom_data' => $data, // Must be JSON-serializable
		'timestamp' => time(),
	]
);
```

---

## Best Practices

1. **Always Extend `AbstractAction`**: Provides helpers for common operations
2. **Use Priority Wisely**: Default (10), override (20+), fallback (1-9)
3. **Validate Inputs**: Check all parameters before processing
4. **Log Important Events**: Use `$this->log()` for debugging and monitoring
5. **Handle Errors Gracefully**: Return helpful error messages to users
6. **Keep Messages Concise**: WhatsApp works best with short, focused messages
7. **Use Buttons for Navigation**: Provide clear next steps
8. **Test Thoroughly**: Write unit tests and test in real conversations
9. **Document Your Actions**: Add PHPDoc comments explaining parameters and behavior
10. **Follow Naming Conventions**: Use snake_case for action names

---

## Additional Resources

- [Action Routing Architecture Documentation](../architecture/ACTION_ROUTING_FSM.md)
- [ActionHandlerInterface Contract](../../includes/Actions/Contracts/ActionHandlerInterface.php)
- [AbstractAction Base Class](../../includes/Actions/AbstractAction.php)
- [Core Action Examples](../../includes/Actions/)

---

## Support

For questions or issues:
- Review the architecture documentation
- Check existing action implementations in `includes/Actions/`
- Submit issues to the project repository
