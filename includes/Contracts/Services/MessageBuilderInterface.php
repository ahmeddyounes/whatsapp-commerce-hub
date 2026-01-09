<?php
/**
 * Message Builder Interface
 *
 * Contract for building WhatsApp messages.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface MessageBuilderInterface
 *
 * Defines the contract for building WhatsApp messages.
 */
interface MessageBuilderInterface {

	/**
	 * Set text content for simple text message.
	 *
	 * @param string $content Text content.
	 * @return static
	 */
	public function text( string $content ): static;

	/**
	 * Set header for interactive message.
	 *
	 * @param string $type    Header type (text|image|document|video).
	 * @param string $content Header content.
	 * @return static
	 */
	public function header( string $type, string $content ): static;

	/**
	 * Set body text for interactive message.
	 *
	 * @param string $text Body text.
	 * @return static
	 */
	public function body( string $text ): static;

	/**
	 * Set footer text for interactive message.
	 *
	 * @param string $text Footer text.
	 * @return static
	 */
	public function footer( string $text ): static;

	/**
	 * Add a button to the message.
	 *
	 * @param string $type Button type (reply|url|phone|flow).
	 * @param array  $data Button data.
	 * @return static
	 */
	public function button( string $type, array $data ): static;

	/**
	 * Add a section to list message.
	 *
	 * @param string $title Section title.
	 * @param array  $rows  Section rows.
	 * @return static
	 */
	public function section( string $title, array $rows ): static;

	/**
	 * Add a single product.
	 *
	 * @param string $productId Product ID.
	 * @return static
	 */
	public function product( string $productId ): static;

	/**
	 * Add multiple products.
	 *
	 * @param array $productIds Array of product IDs.
	 * @return static
	 */
	public function productList( array $productIds ): static;

	/**
	 * Build and validate the message.
	 *
	 * @return array Formatted message array for WhatsApp API.
	 */
	public function build(): array;

	/**
	 * Reset builder state.
	 *
	 * @return static
	 */
	public function reset(): static;
}
