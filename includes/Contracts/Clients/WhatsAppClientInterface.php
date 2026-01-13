<?php
/**
 * WhatsApp Client Interface
 *
 * Contract for WhatsApp Business Cloud API operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Clients;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface WhatsAppClientInterface
 *
 * Defines the contract for WhatsApp Business Cloud API operations.
 */
interface WhatsAppClientInterface {

	/**
	 * Send text message.
	 *
	 * @param string $to          Recipient phone number in E.164 format.
	 * @param string $text        Message text.
	 * @param bool   $preview_url Whether to enable URL preview. Default false.
	 * @return array{message_id: string|null, status: string}
	 * @throws \InvalidArgumentException If phone number is invalid.
	 * @throws \RuntimeException If send fails.
	 */
	public function sendTextMessage( string $to, string $text, bool $preview_url = false ): array;

	/**
	 * Send interactive list message.
	 *
	 * @param string $to          Recipient phone number in E.164 format.
	 * @param string $header      Header text.
	 * @param string $body        Body text.
	 * @param string $footer      Footer text.
	 * @param string $button_text Button text.
	 * @param array  $sections    Array of sections with rows.
	 * @return array{message_id: string|null, status: string}
	 * @throws \RuntimeException If send fails.
	 */
	public function sendInteractiveList(
		string $to,
		string $header,
		string $body,
		string $footer,
		string $button_text,
		array $sections
	): array;

	/**
	 * Send interactive buttons message.
	 *
	 * @param string $to      Recipient phone number in E.164 format.
	 * @param string $header  Header text.
	 * @param string $body    Body text.
	 * @param string $footer  Footer text.
	 * @param array  $buttons Array of buttons.
	 * @return array{message_id: string|null, status: string}
	 * @throws \RuntimeException If send fails.
	 */
	public function sendInteractiveButtons(
		string $to,
		string $header,
		string $body,
		string $footer,
		array $buttons
	): array;

	/**
	 * Send template message.
	 *
	 * @param string $to            Recipient phone number in E.164 format.
	 * @param string $template_name Template name.
	 * @param string $language_code Language code (e.g., 'en_US').
	 * @param array  $components    Template components.
	 * @return array{message_id: string|null, status: string}
	 * @throws \RuntimeException If send fails.
	 */
	public function sendTemplate(
		string $to,
		string $template_name,
		string $language_code,
		array $components = []
	): array;

	/**
	 * Send image message.
	 *
	 * @param string      $to             Recipient phone number in E.164 format.
	 * @param string      $image_url_or_id Image URL or media ID.
	 * @param string|null $caption        Optional caption.
	 * @return array{message_id: string|null, status: string}
	 * @throws \RuntimeException If send fails.
	 */
	public function sendImage( string $to, string $image_url_or_id, ?string $caption = null ): array;

	/**
	 * Send document message.
	 *
	 * @param string      $to                Recipient phone number in E.164 format.
	 * @param string      $document_url_or_id Document URL or media ID.
	 * @param string|null $filename          Optional filename.
	 * @param string|null $caption           Optional caption.
	 * @return array{message_id: string|null, status: string}
	 * @throws \RuntimeException If send fails.
	 */
	public function sendDocument(
		string $to,
		string $document_url_or_id,
		?string $filename = null,
		?string $caption = null
	): array;

	/**
	 * Send product message.
	 *
	 * @param string $to                  Recipient phone number in E.164 format.
	 * @param string $catalog_id          Catalog ID.
	 * @param string $product_retailer_id Product retailer ID.
	 * @return array{message_id: string|null, status: string}
	 * @throws \RuntimeException If send fails.
	 */
	public function sendProductMessage( string $to, string $catalog_id, string $product_retailer_id ): array;

	/**
	 * Send product list message.
	 *
	 * @param string $to         Recipient phone number in E.164 format.
	 * @param string $catalog_id Catalog ID.
	 * @param string $header     Header text.
	 * @param string $body       Body text.
	 * @param array  $sections   Array of product sections.
	 * @return array{message_id: string|null, status: string}
	 * @throws \RuntimeException If send fails.
	 */
	public function sendProductList(
		string $to,
		string $catalog_id,
		string $header,
		string $body,
		array $sections
	): array;

	/**
	 * Mark message as read.
	 *
	 * @param string $message_id Message ID to mark as read.
	 * @return array{status: bool}
	 * @throws \RuntimeException If request fails.
	 */
	public function markAsRead( string $message_id ): array;

	/**
	 * Get media URL from media ID.
	 *
	 * @param string $media_id Media ID.
	 * @return string Media URL.
	 * @throws \RuntimeException If request fails.
	 */
	public function getMediaUrl( string $media_id ): string;

	/**
	 * Upload media file.
	 *
	 * @param string $file_path Path to file to upload.
	 * @param string $mime_type MIME type of the file.
	 * @return string Media ID.
	 * @throws \InvalidArgumentException If file not found.
	 * @throws \RuntimeException If upload fails.
	 */
	public function uploadMedia( string $file_path, string $mime_type ): string;

	/**
	 * Get business profile.
	 *
	 * @return array Business profile data.
	 * @throws \RuntimeException If request fails.
	 */
	public function getBusinessProfile(): array;

	/**
	 * Update business profile.
	 *
	 * @param array $data Profile data to update.
	 * @return array Updated profile data.
	 * @throws \RuntimeException If request fails.
	 */
	public function updateBusinessProfile( array $data ): array;

	/**
	 * Create or update a product in the WhatsApp catalog.
	 *
	 * @param string $catalog_id   Catalog ID.
	 * @param array  $product_data Product data in catalog format.
	 * @return array Response data with product ID.
	 * @throws \RuntimeException If request fails.
	 */
	public function createCatalogProduct( string $catalog_id, array $product_data ): array;

	/**
	 * Update a product in the WhatsApp catalog.
	 *
	 * @param string $catalog_id   Catalog ID.
	 * @param string $product_id   Product retailer ID.
	 * @param array  $product_data Product data to update.
	 * @return array Response data.
	 * @throws \RuntimeException If request fails.
	 */
	public function updateCatalogProduct( string $catalog_id, string $product_id, array $product_data ): array;

	/**
	 * Delete a product from the WhatsApp catalog.
	 *
	 * @param string $catalog_id Catalog ID.
	 * @param string $product_id Product retailer ID or catalog item ID.
	 * @return array Response data.
	 * @throws \RuntimeException If request fails.
	 */
	public function deleteCatalogProduct( string $catalog_id, string $product_id ): array;

	/**
	 * Get a product from the WhatsApp catalog.
	 *
	 * @param string $catalog_id Catalog ID.
	 * @param string $product_id Product retailer ID.
	 * @return array Product data.
	 * @throws \RuntimeException If request fails.
	 */
	public function getCatalogProduct( string $catalog_id, string $product_id ): array;

	/**
	 * List products in the WhatsApp catalog.
	 *
	 * @param string $catalog_id Catalog ID.
	 * @param array  $params     Optional query parameters (limit, after, before).
	 * @return array Products list with paging info.
	 * @throws \RuntimeException If request fails.
	 */
	public function listCatalogProducts( string $catalog_id, array $params = [] ): array;

	/**
	 * Validate phone number format.
	 *
	 * @param string $phone Phone number to validate.
	 * @return bool True if valid E.164 format.
	 */
	public function validatePhoneNumber( string $phone ): bool;

	/**
	 * Check if client is available (circuit breaker state).
	 *
	 * @return bool True if available.
	 */
	public function isAvailable(): bool;

	/**
	 * Get API health status.
	 *
	 * @return array{healthy: bool, latency_ms: int|null, last_error: string|null}
	 */
	public function getHealthStatus(): array;
}
