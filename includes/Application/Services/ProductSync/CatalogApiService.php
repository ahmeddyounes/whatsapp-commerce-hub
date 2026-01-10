<?php
/**
 * Catalog API Service
 *
 * Handles WhatsApp Catalog API operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\ProductSync;

use WhatsAppCommerceHub\Contracts\Services\ProductSync\CatalogApiInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use Exception;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CatalogApiService
 *
 * Handles WhatsApp Catalog API calls.
 */
class CatalogApiService implements CatalogApiInterface {

	/**
	 * Meta key for catalog item ID.
	 */
	public const META_CATALOG_ID = '_wch_catalog_id';

	/**
	 * Meta key for last sync timestamp.
	 */
	public const META_LAST_SYNCED = '_wch_last_synced';

	/**
	 * Meta key for sync status.
	 */
	public const META_SYNC_STATUS = '_wch_sync_status';

	/**
	 * WhatsApp API client.
	 *
	 * @var \WCH_WhatsApp_API_Client|null
	 */
	protected $apiClient = null;

	/**
	 * Constructor.
	 *
	 * @param SettingsInterface|null $settings Settings service.
	 * @param LoggerInterface|null   $logger   Logger service.
	 */
	public function __construct(
		protected ?SettingsInterface $settings = null,
		protected ?LoggerInterface $logger = null
	) {
	}

	/**
	 * {@inheritdoc}
	 */
	public function createProduct( array $catalogData ): array {
		if ( ! $this->isConfigured() ) {
			return [
				'success' => false,
				'error'   => 'WhatsApp API not configured',
			];
		}

		$apiClient = $this->getApiClient();
		$catalogId = $this->getCatalogId();

		if ( ! $apiClient || ! $catalogId ) {
			return [
				'success' => false,
				'error'   => 'WhatsApp API not configured',
			];
		}

		try {
			$response = $apiClient->create_catalog_product( $catalogId, $catalogData );

			$this->log(
				'info',
				'Product synced to WhatsApp catalog',
				[
					'retailer_id' => $catalogData['retailer_id'] ?? 'unknown',
					'catalog_id'  => $catalogId,
				]
			);

			return [
				'success'         => true,
				'catalog_item_id' => $response['id'] ?? null,
			];
		} catch ( Exception $e ) {
			$this->log(
				'error',
				'Failed to sync product to WhatsApp catalog',
				[
					'retailer_id' => $catalogData['retailer_id'] ?? 'unknown',
					'error'       => $e->getMessage(),
				]
			);

			return [
				'success' => false,
				'error'   => $e->getMessage(),
			];
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteProduct( string $catalogItemId ): array {
		if ( ! $this->isConfigured() ) {
			return [
				'success' => false,
				'error'   => 'WhatsApp API not configured',
			];
		}

		$apiClient = $this->getApiClient();
		$catalogId = $this->getCatalogId();

		if ( ! $apiClient || ! $catalogId ) {
			return [
				'success' => false,
				'error'   => 'WhatsApp API not configured',
			];
		}

		try {
			$apiClient->delete_catalog_product( $catalogId, $catalogItemId );

			$this->log(
				'info',
				'Product removed from WhatsApp catalog',
				[
					'catalog_item_id' => $catalogItemId,
					'catalog_id'      => $catalogId,
				]
			);

			return [ 'success' => true ];
		} catch ( Exception $e ) {
			$this->log(
				'error',
				'Failed to delete product from WhatsApp catalog',
				[
					'catalog_item_id' => $catalogItemId,
					'error'           => $e->getMessage(),
				]
			);

			return [
				'success' => false,
				'error'   => $e->getMessage(),
			];
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCatalogId(): ?string {
		$catalogId = $this->getSetting( 'catalog.catalog_id' );
		return ! empty( $catalogId ) ? (string) $catalogId : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function isConfigured(): bool {
		$phoneNumberId = $this->getSetting( 'api.whatsapp_phone_number_id' );
		$accessToken   = $this->getSetting( 'api.access_token' );
		$catalogId     = $this->getCatalogId();

		return ! empty( $phoneNumberId ) && ! empty( $accessToken ) && ! empty( $catalogId );
	}

	/**
	 * Update product sync metadata.
	 *
	 * @param int    $productId Product ID.
	 * @param string $status    Sync status.
	 * @param string $catalogItemId Optional catalog item ID.
	 * @param string $message   Optional status message.
	 * @return void
	 */
	public function updateSyncStatus( int $productId, string $status, string $catalogItemId = '', string $message = '' ): void {
		update_post_meta( $productId, self::META_SYNC_STATUS, $status );
		update_post_meta( $productId, self::META_LAST_SYNCED, current_time( 'mysql' ) );

		if ( ! empty( $catalogItemId ) ) {
			update_post_meta( $productId, self::META_CATALOG_ID, $catalogItemId );
		}

		if ( ! empty( $message ) ) {
			update_post_meta( $productId, '_wch_sync_message', $message );
		} else {
			delete_post_meta( $productId, '_wch_sync_message' );
		}
	}

	/**
	 * Clear product sync metadata.
	 *
	 * @param int $productId Product ID.
	 * @return void
	 */
	public function clearSyncMetadata( int $productId ): void {
		delete_post_meta( $productId, self::META_CATALOG_ID );
		delete_post_meta( $productId, self::META_LAST_SYNCED );
		delete_post_meta( $productId, self::META_SYNC_STATUS );
		delete_post_meta( $productId, '_wch_sync_hash' );
		delete_post_meta( $productId, '_wch_sync_message' );
	}

	/**
	 * Get catalog item ID for a product.
	 *
	 * @param int $productId Product ID.
	 * @return string|null Catalog item ID or null.
	 */
	public function getCatalogItemId( int $productId ): ?string {
		$catalogItemId = get_post_meta( $productId, self::META_CATALOG_ID, true );
		return ! empty( $catalogItemId ) ? (string) $catalogItemId : null;
	}

	/**
	 * Get API client instance.
	 *
	 * @return \WCH_WhatsApp_API_Client|null
	 */
	protected function getApiClient() {
		if ( null !== $this->apiClient ) {
			return $this->apiClient;
		}

		try {
			$phoneNumberId = $this->getSetting( 'api.whatsapp_phone_number_id' );
			$accessToken   = $this->getSetting( 'api.access_token' );
			$apiVersion    = $this->getSetting( 'api.api_version', 'v18.0' );

			if ( empty( $phoneNumberId ) || empty( $accessToken ) ) {
				return null;
			}

			if ( ! class_exists( 'WCH_WhatsApp_API_Client' ) ) {
				return null;
			}

			$this->apiClient = new \WCH_WhatsApp_API_Client(
				[
					'phone_number_id' => $phoneNumberId,
					'access_token'    => $accessToken,
					'api_version'     => $apiVersion,
				]
			);

			return $this->apiClient;
		} catch ( Exception $e ) {
			$this->log(
				'error',
				'Failed to initialize API client',
				[
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed Setting value.
	 */
	protected function getSetting( string $key, mixed $default = null ): mixed {
		if ( null !== $this->settings ) {
			return $this->settings->get( $key, $default );
		}

		if ( class_exists( 'WCH_Settings' ) ) {
			return \WCH_Settings::instance()->get( $key, $default );
		}

		return $default;
	}

	/**
	 * Log message.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Context data.
	 * @return void
	 */
	protected function log( string $level, string $message, array $context = [] ): void {
		$context['category'] = 'product-sync';

		if ( null !== $this->logger ) {
			$this->logger->log( $level, $message, 'product_sync', $context );
			return;
		}

		if ( class_exists( 'WCH_Logger' ) ) {
			\WCH_Logger::log( $message, $context, $level );
		}
	}
}
