<?php
/**
 * Payment Gateway Registry
 *
 * Manages payment gateway registration and retrieval.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.1.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Payments;

use WhatsAppCommerceHub\Contracts\Payments\PaymentGatewayRegistryInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaymentGatewayRegistry
 *
 * Plugin-based architecture for managing payment gateways.
 */
class PaymentGatewayRegistry implements PaymentGatewayRegistryInterface {

	/**
	 * Registered payment gateways.
	 *
	 * @var array<string, \WCH_Payment_Gateway>
	 */
	private array $gateways = [];

	/**
	 * Whether default gateways have been loaded.
	 *
	 * @var bool
	 */
	private bool $defaults_loaded = false;

	/**
	 * Option name for enabled gateways.
	 *
	 * @var string
	 */
	private const ENABLED_OPTION = 'wch_enabled_payment_methods';

	/**
	 * Default enabled gateways.
	 *
	 * @var array
	 */
	private const DEFAULT_ENABLED = [ 'cod', 'stripe' ];

	/**
	 * Constructor.
	 *
	 * @param bool $auto_register Whether to auto-register default gateways.
	 */
	public function __construct( bool $auto_register = true ) {
		if ( $auto_register ) {
			$this->loadDefaultGateways();
		}
	}

	/**
	 * Register a payment gateway.
	 *
	 * @param string               $id Gateway identifier.
	 * @param \WCH_Payment_Gateway $gateway Gateway instance.
	 * @return void
	 */
	public function register( string $id, \WCH_Payment_Gateway $gateway ): void {
		$this->gateways[ $id ] = $gateway;

		/**
		 * Fires after a payment gateway is registered.
		 *
		 * @param string              $id Gateway identifier.
		 * @param \WCH_Payment_Gateway $gateway Gateway instance.
		 */
		do_action( 'wch_payment_gateway_registered', $id, $gateway );
	}

	/**
	 * Get a gateway by ID.
	 *
	 * @param string $id Gateway identifier.
	 * @return \WCH_Payment_Gateway|null Gateway instance or null if not found.
	 */
	public function get( string $id ): ?\WCH_Payment_Gateway {
		return $this->gateways[ $id ] ?? null;
	}

	/**
	 * Check if a gateway is registered.
	 *
	 * @param string $id Gateway identifier.
	 * @return bool True if gateway exists.
	 */
	public function has( string $id ): bool {
		return isset( $this->gateways[ $id ] );
	}

	/**
	 * Get all registered gateways.
	 *
	 * @return array<string, \WCH_Payment_Gateway> All registered gateways.
	 */
	public function all(): array {
		return $this->gateways;
	}

	/**
	 * Get all enabled gateways.
	 *
	 * @return array<string, \WCH_Payment_Gateway> Enabled gateways.
	 */
	public function getEnabled(): array {
		$enabled_ids = $this->getEnabledIds();
		$enabled     = [];

		foreach ( $enabled_ids as $id ) {
			if ( isset( $this->gateways[ $id ] ) ) {
				$enabled[ $id ] = $this->gateways[ $id ];
			}
		}

		return $enabled;
	}

	/**
	 * Get the list of enabled gateway IDs from options.
	 *
	 * @return array<string> List of enabled gateway IDs.
	 */
	private function getEnabledIds(): array {
		$enabled_ids = get_option( self::ENABLED_OPTION, self::DEFAULT_ENABLED );

		if ( ! is_array( $enabled_ids ) ) {
			return self::DEFAULT_ENABLED;
		}

		return $enabled_ids;
	}

	/**
	 * Get available gateways for a specific country.
	 *
	 * @param string $country Two-letter country code.
	 * @return array<string, \WCH_Payment_Gateway> Available gateways.
	 */
	public function getAvailable( string $country ): array {
		if ( empty( $country ) && function_exists( 'WC' ) ) {
			$wc = WC();
			if ( $wc && isset( $wc->countries ) ) {
				$country = $wc->countries->get_base_country();
			}
		}

		$available = [];
		$enabled   = $this->getEnabled();

		foreach ( $enabled as $id => $gateway ) {
			if ( $gateway->is_available( $country ) ) {
				$available[ $id ] = $gateway;
			}
		}

		/**
		 * Filter available payment gateways for a country.
		 *
		 * @param array  $available Available gateways.
		 * @param string $country Country code.
		 */
		return apply_filters( 'wch_available_payment_gateways', $available, $country );
	}

	/**
	 * Remove a gateway from the registry.
	 *
	 * @param string $id Gateway identifier.
	 * @return bool True if removed, false if not found.
	 */
	public function remove( string $id ): bool {
		if ( ! isset( $this->gateways[ $id ] ) ) {
			return false;
		}

		unset( $this->gateways[ $id ] );

		/**
		 * Fires after a payment gateway is removed.
		 *
		 * @param string $id Gateway identifier.
		 */
		do_action( 'wch_payment_gateway_removed', $id );

		return true;
	}

	/**
	 * Get all gateway IDs.
	 *
	 * @return array<string> List of gateway IDs.
	 */
	public function ids(): array {
		return array_keys( $this->gateways );
	}

	/**
	 * Load default payment gateways.
	 *
	 * @return void
	 */
	private function loadDefaultGateways(): void {
		if ( $this->defaults_loaded ) {
			return;
		}

		$this->defaults_loaded = true;

		// Ensure gateway interface and classes are loaded.
		$this->loadGatewayFiles();

		// Register built-in gateways.
		$this->registerBuiltinGateways();

		/**
		 * Allow third-party gateways to be registered.
		 *
		 * @param PaymentGatewayRegistry $registry Registry instance.
		 */
		do_action( 'wch_register_payment_gateways', $this );
	}

	/**
	 * Load gateway class files.
	 *
	 * @return void
	 */
	private function loadGatewayFiles(): void {
		$gateway_dir = defined( 'WCH_PLUGIN_DIR' ) ? WCH_PLUGIN_DIR . 'includes/Payments/' : '';

		if ( empty( $gateway_dir ) || ! is_dir( $gateway_dir ) ) {
			return;
		}

		// Load interface first.
		$interface_file = $gateway_dir . 'interface-wch-payment-gateway.php';
		if ( file_exists( $interface_file ) && ! interface_exists( 'WCH_Payment_Gateway' ) ) {
			require_once $interface_file;
		}

		// Auto-discover gateway files.
		$gateway_files = glob( $gateway_dir . 'class-wch-payment-*.php' );
		if ( $gateway_files ) {
			foreach ( $gateway_files as $file ) {
				require_once $file;
			}
		}
	}

	/**
	 * Register built-in payment gateways.
	 *
	 * @return void
	 */
	private function registerBuiltinGateways(): void {
		$builtin_gateways = [
			'cod'         => 'WCH_Payment_COD',
			'stripe'      => 'WCH_Payment_Stripe',
			'razorpay'    => 'WCH_Payment_Razorpay',
			'whatsapppay' => 'WCH_Payment_WhatsAppPay',
			'pix'         => 'WCH_Payment_PIX',
		];

		/**
		 * Filter the list of built-in gateways to register.
		 *
		 * @param array $builtin_gateways Array of gateway_id => class_name.
		 */
		$builtin_gateways = apply_filters( 'wch_builtin_payment_gateways', $builtin_gateways );

		foreach ( $builtin_gateways as $id => $class_name ) {
			if ( class_exists( $class_name ) ) {
				try {
					$gateway = new $class_name();
					if ( $gateway instanceof \WCH_Payment_Gateway ) {
						$this->register( $id, $gateway );
					}
				} catch ( \Throwable $e ) {
					// Log error but don't break the entire registry.
					if ( class_exists( 'WCH_Logger' ) ) {
						\WCH_Logger::error(
							"Failed to instantiate payment gateway: {$id}",
							[
								'category' => 'payments',
								'class'    => $class_name,
								'error'    => $e->getMessage(),
							]
						);
					}
				}
			}
		}
	}

	/**
	 * Enable a gateway.
	 *
	 * @param string $id Gateway identifier.
	 * @return bool True if enabled.
	 */
	public function enable( string $id ): bool {
		if ( ! $this->has( $id ) ) {
			return false;
		}

		$enabled = $this->getEnabledIds();

		if ( ! in_array( $id, $enabled, true ) ) {
			$enabled[] = $id;
			update_option( self::ENABLED_OPTION, $enabled );
		}

		return true;
	}

	/**
	 * Disable a gateway.
	 *
	 * @param string $id Gateway identifier.
	 * @return bool True if disabled.
	 */
	public function disable( string $id ): bool {
		$enabled = $this->getEnabledIds();

		$key = array_search( $id, $enabled, true );
		if ( false !== $key ) {
			unset( $enabled[ $key ] );
			$enabled = array_values( $enabled ); // Re-index array.
			update_option( self::ENABLED_OPTION, $enabled );
			return true;
		}

		return false;
	}

	/**
	 * Check if a gateway is enabled.
	 *
	 * @param string $id Gateway identifier.
	 * @return bool True if enabled.
	 */
	public function isEnabled( string $id ): bool {
		return in_array( $id, $this->getEnabledIds(), true );
	}

	/**
	 * Get gateway metadata for admin display.
	 *
	 * @return array Gateway metadata.
	 */
	public function getMetadata(): array {
		$metadata = [];

		foreach ( $this->gateways as $id => $gateway ) {
			$metadata[ $id ] = [
				'id'      => $id,
				'title'   => $gateway->get_title(),
				'enabled' => $this->isEnabled( $id ),
			];
		}

		return $metadata;
	}
}
