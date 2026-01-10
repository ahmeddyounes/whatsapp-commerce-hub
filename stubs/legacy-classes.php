<?php
/**
 * PHPStan stubs for legacy WCH_* classes
 *
 * These stubs help PHPStan understand legacy class aliases
 * that are created at runtime via class_alias().
 *
 * @package WhatsApp_Commerce_Hub
 */

// Legacy class aliases - these are actually aliases to PSR-4 classes
// defined at runtime in wch_legacy_autoloader()

/**
 * WCH_Logger stub with static methods for backwards compatibility
 */
class WCH_Logger {
	public static function instance(): \WhatsAppCommerceHub\Core\Logger {
		return \WhatsAppCommerceHub\Core\Logger::instance();
	}
	public static function log( string $message, array $context = [], string $level = 'info' ): void {
		\WhatsAppCommerceHub\Core\Logger::logStatic( $message, $context, $level );
	}
	public static function info( string $message, array $context = [] ): void {
		\WhatsAppCommerceHub\Core\Logger::instance()->info( $message, $context['category'] ?? 'general', $context );
	}
	public static function error( string $message, array $context = [] ): void {
		\WhatsAppCommerceHub\Core\Logger::instance()->error( $message, $context['category'] ?? 'general', $context );
	}
	public static function warning( string $message, array $context = [] ): void {
		\WhatsAppCommerceHub\Core\Logger::instance()->warning( $message, $context['category'] ?? 'general', $context );
	}
	public static function debug( string $message, array $context = [] ): void {
		\WhatsAppCommerceHub\Core\Logger::instance()->debug( $message, $context['category'] ?? 'general', $context );
	}
}

/**
 * WCH_Customer_Service stub with static instance method
 */
class WCH_Customer_Service {
	private static ?\WhatsAppCommerceHub\Domain\Customer\CustomerService $instance = null;
	public static function instance(): \WhatsAppCommerceHub\Domain\Customer\CustomerService {
		if ( null === self::$instance ) {
			self::$instance = new \WhatsAppCommerceHub\Domain\Customer\CustomerService();
		}
		return self::$instance;
	}
}

/**
 * WCH_Cart_Manager stub with static instance method
 */
class WCH_Cart_Manager {
	private static ?\WhatsAppCommerceHub\Domain\Cart\CartService $instance = null;
	public static function instance(): \WhatsAppCommerceHub\Domain\Cart\CartService {
		if ( null === self::$instance ) {
			self::$instance = new \WhatsAppCommerceHub\Domain\Cart\CartService();
		}
		return self::$instance;
	}
}

/**
 * WCH_Settings stub with static getInstance method
 */
class WCH_Settings {
	private static ?\WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager $instance = null;
	public static function getInstance(): \WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager {
		if ( null === self::$instance ) {
			self::$instance = new \WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager();
		}
		return self::$instance;
	}
	public static function instance(): \WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager {
		return self::getInstance();
	}
}

/**
 * WCH_Payment_Manager stub with static instance method
 */
class WCH_Payment_Manager {
	private static ?\WhatsAppCommerceHub\Payments\PaymentGatewayRegistry $instance = null;
	public static function instance(): \WhatsAppCommerceHub\Payments\PaymentGatewayRegistry {
		if ( null === self::$instance ) {
			self::$instance = new \WhatsAppCommerceHub\Payments\PaymentGatewayRegistry();
		}
		return self::$instance;
	}
}

class WCH_Message_Builder extends \WhatsAppCommerceHub\Support\Messaging\MessageBuilder {}
class WCH_WhatsApp_API_Client extends \WhatsAppCommerceHub\Clients\WhatsAppApiClient {}
class WCH_Database_Manager extends \WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager {}
class WCH_Template_Manager extends \WhatsAppCommerceHub\Presentation\Templates\TemplateManager {}
class WCH_Catalog_Browser extends \WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser {}
class WCH_Product_Sync_Service extends \WhatsAppCommerceHub\Application\Services\ProductSyncService {}
class WCH_Order_Sync_Service extends \WhatsAppCommerceHub\Application\Services\OrderSyncService {}
class WCH_Conversation_FSM extends \WhatsAppCommerceHub\Domain\Conversation\StateMachine {}
class WCH_Intent_Classifier extends \WhatsAppCommerceHub\Support\AI\IntentClassifier {}
class WCH_AI_Assistant extends \WhatsAppCommerceHub\Support\AI\AiAssistant {}
class WCH_Response_Parser extends \WhatsAppCommerceHub\Support\AI\ResponseParser {}
class WCH_Address_Parser extends \WhatsAppCommerceHub\Support\Utilities\AddressParser {}
class WCH_Queue extends \WhatsAppCommerceHub\Infrastructure\Queue\QueueManager {}
class WCH_Job_Dispatcher extends \WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher {}
class WCH_Analytics_Data extends \WhatsAppCommerceHub\Features\Analytics\AnalyticsData {}
