<?php
/**
 * Settings Service
 *
 * Instance-based settings service that wraps WCH_Settings.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services;

use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsService
 *
 * Implements settings management with encryption and validation.
 * Wraps the legacy WCH_Settings class for backward compatibility.
 */
class SettingsService implements SettingsInterface {

	/**
	 * The settings manager instance.
	 *
	 * @var \WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager|\WCH_Settings
	 */
	protected $settingsManager;

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	protected ?array $cache = null;

	/**
	 * The callback closure for settings update hook.
	 *
	 * Stored as property to ensure exact same reference for add/remove_action.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $updateCallback = null;

	/**
	 * Constructor.
	 *
	 * @param \WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager|\WCH_Settings $settingsManager The settings manager instance.
	 */
	public function __construct( $settingsManager ) {
		$this->settingsManager = $settingsManager;

		// Create and store callback closure to ensure exact reference for removal.
		// This ensures cache coherence even if settings are modified via legacy code.
		$this->updateCallback = function (): void {
			$this->cache = null;
		};

		add_action( 'update_option_wch_settings', $this->updateCallback, 10, 0 );
	}

	/**
	 * Destructor.
	 *
	 * Removes action hooks to prevent memory leaks when instance is destroyed.
	 */
	public function __destruct() {
		if ( null !== $this->updateCallback ) {
			remove_action( 'update_option_wch_settings', $this->updateCallback, 10 );
			$this->updateCallback = null;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get( string $key, mixed $default = null ): mixed {
		return $this->settingsManager->get( $key, $default );
	}

	/**
	 * {@inheritdoc}
	 */
	public function set( string $key, $value ): bool {
		$result = $this->settingsManager->set( $key, $value );

		// Clear cache on update.
		$this->cache = null;

		// Explicit cast for strict_types compatibility.
		return (bool) $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete( string $key ): bool {
		$result = $this->settingsManager->delete( $key );

		// Clear cache on delete.
		$this->cache = null;

		// Explicit cast for strict_types compatibility.
		return (bool) $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function has( string $key ): bool {
		// Use a unique sentinel to detect if key exists.
		// This ensures consistency with get() behavior.
		$sentinel = new \stdClass();
		$value    = $this->get( $key, $sentinel );

		return $value !== $sentinel;
	}

	/**
	 * {@inheritdoc}
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			// Return a copy to prevent external modification of internal cache.
			return $this->deepCopy( $this->cache );
		}

		// Use getAll() for SettingsManager, get_all() for legacy WCH_Settings.
		$this->cache = method_exists( $this->settingsManager, 'getAll' )
			? $this->settingsManager->getAll()
			: $this->settingsManager->get_all();

		return $this->deepCopy( $this->cache );
	}

	/**
	 * Create a deep copy of an array to prevent reference leaks.
	 *
	 * @param array $array Array to copy.
	 * @return array Deep copy of the array.
	 */
	private function deepCopy( array $array ): array {
		$copy = [];
		foreach ( $array as $key => $value ) {
			$copy[ $key ] = is_array( $value ) ? $this->deepCopy( $value ) : $value;
		}
		return $copy;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getGroup( string $group ): array {
		// Use getSection() for SettingsManager, get_section() for legacy WCH_Settings.
		return method_exists( $this->settingsManager, 'getSection' )
			? $this->settingsManager->getSection( $group )
			: $this->settingsManager->get_section( $group );
	}

	/**
	 * {@inheritdoc}
	 */
	public function isConfigured(): bool {
		$accessToken   = $this->get( 'api.access_token', '' );
		$phoneNumberId = $this->get( 'api.whatsapp_phone_number_id', '' );

		return ! empty( $accessToken ) && ! empty( $phoneNumberId );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getApiCredentials(): array {
		return [
			'access_token'        => $this->get( 'api.access_token', '' ),
			'phone_number_id'     => $this->get( 'api.whatsapp_phone_number_id', '' ),
			'business_account_id' => $this->get( 'api.whatsapp_business_account_id', '' ),
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function refresh(): void {
		$this->cache = null;
	}

	/**
	 * Get the settings manager instance.
	 *
	 * @return \WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager|\WCH_Settings
	 */
	public function getLegacyInstance() {
		return $this->settingsManager;
	}

	/**
	 * Check if a feature is enabled.
	 *
	 * @param string $feature Feature key (e.g., 'general.enable_bot', 'ai.enable_ai').
	 * @return bool
	 */
	public function isFeatureEnabled( string $feature ): bool {
		return (bool) $this->get( $feature, false );
	}

	/**
	 * Get checkout settings.
	 *
	 * @return array{
	 *     enabled_payment_methods: array,
	 *     cod_enabled: bool,
	 *     cod_extra_charge: float,
	 *     min_order_amount: float,
	 *     max_order_amount: float,
	 *     require_phone_verification: bool
	 * }
	 */
	public function getCheckoutSettings(): array {
		return [
			'enabled_payment_methods'    => (array) $this->get( 'checkout.enabled_payment_methods', [] ),
			'cod_enabled'                => (bool) $this->get( 'checkout.cod_enabled', false ),
			'cod_extra_charge'           => (float) $this->get( 'checkout.cod_extra_charge', 0.0 ),
			'min_order_amount'           => (float) $this->get( 'checkout.min_order_amount', 0.0 ),
			'max_order_amount'           => (float) $this->get( 'checkout.max_order_amount', 0.0 ),
			'require_phone_verification' => (bool) $this->get( 'checkout.require_phone_verification', false ),
		];
	}

	/**
	 * Get notification settings.
	 *
	 * @return array{
	 *     order_confirmation: bool,
	 *     order_status_updates: bool,
	 *     shipping_updates: bool,
	 *     abandoned_cart_reminder: bool,
	 *     abandoned_cart_delay_hours: int
	 * }
	 */
	public function getNotificationSettings(): array {
		return [
			'order_confirmation'         => (bool) $this->get( 'notifications.order_confirmation', true ),
			'order_status_updates'       => (bool) $this->get( 'notifications.order_status_updates', true ),
			'shipping_updates'           => (bool) $this->get( 'notifications.shipping_updates', false ),
			'abandoned_cart_reminder'    => (bool) $this->get( 'notifications.abandoned_cart_reminder', false ),
			'abandoned_cart_delay_hours' => (int) $this->get( 'notifications.abandoned_cart_delay_hours', 24 ),
		];
	}

	/**
	 * Get AI settings.
	 *
	 * @return array{
	 *     enable_ai: bool,
	 *     model: string,
	 *     temperature: float,
	 *     max_tokens: int,
	 *     system_prompt: string,
	 *     monthly_budget_cap: float
	 * }
	 */
	public function getAiSettings(): array {
		return [
			'enable_ai'          => (bool) $this->get( 'ai.enable_ai', false ),
			'model'              => (string) $this->get( 'ai.ai_model', 'gpt-4' ),
			'temperature'        => (float) $this->get( 'ai.ai_temperature', 0.7 ),
			'max_tokens'         => (int) $this->get( 'ai.ai_max_tokens', 500 ),
			'system_prompt'      => (string) $this->get( 'ai.ai_system_prompt', '' ),
			'monthly_budget_cap' => (float) $this->get( 'ai.monthly_budget_cap', 0.0 ),
		];
	}

	/**
	 * Get inventory settings.
	 *
	 * @return array{
	 *     enable_realtime_sync: bool,
	 *     low_stock_threshold: int,
	 *     notify_low_stock: bool,
	 *     auto_fix_discrepancies: bool
	 * }
	 */
	public function getInventorySettings(): array {
		return [
			'enable_realtime_sync'   => (bool) $this->get( 'inventory.enable_realtime_sync', false ),
			'low_stock_threshold'    => (int) $this->get( 'inventory.low_stock_threshold', 5 ),
			'notify_low_stock'       => (bool) $this->get( 'inventory.notify_low_stock', false ),
			'auto_fix_discrepancies' => (bool) $this->get( 'inventory.auto_fix_discrepancies', false ),
		];
	}

	/**
	 * Get cart recovery settings.
	 *
	 * @return array{
	 *     enabled: bool,
	 *     delay_sequence_1: int,
	 *     delay_sequence_2: int,
	 *     delay_sequence_3: int,
	 *     discount_enabled: bool,
	 *     discount_type: string,
	 *     discount_amount: float
	 * }
	 */
	public function getRecoverySettings(): array {
		return [
			'enabled'          => (bool) $this->get( 'recovery.enabled', false ),
			'delay_sequence_1' => (int) $this->get( 'recovery.delay_sequence_1', 4 ),
			'delay_sequence_2' => (int) $this->get( 'recovery.delay_sequence_2', 24 ),
			'delay_sequence_3' => (int) $this->get( 'recovery.delay_sequence_3', 48 ),
			'discount_enabled' => (bool) $this->get( 'recovery.discount_enabled', false ),
			'discount_type'    => (string) $this->get( 'recovery.discount_type', 'percent' ),
			'discount_amount'  => (float) $this->get( 'recovery.discount_amount', 10 ),
		];
	}
}
