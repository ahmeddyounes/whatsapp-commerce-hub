<?php
/**
 * Legacy Class Mapper
 *
 * Maps legacy WCH_ prefixed classes to their new PSR-4 equivalents.
 *
 * @package WhatsAppCommerceHub
 * @subpackage Core
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Core;

/**
 * Class LegacyClassMapper
 *
 * Provides mapping between legacy and new class names.
 */
class LegacyClassMapper {
	/**
	 * Class mapping array
	 *
	 * @var array
	 */
	private static array $mapping = array();

	/**
	 * Get complete class mapping
	 *
	 * Maps legacy WCH_ classes to new PSR-4 classes.
	 *
	 * @return array Mapping of old class => new class.
	 */
	public static function getMapping(): array {
		if ( ! empty( self::$mapping ) ) {
			return self::$mapping;
		}

		self::$mapping = array(
			// Phase 2: Core Infrastructure.
			'WCH_Logger'                => 'WhatsAppCommerceHub\Core\Logger',
			'WCH_Error_Handler'         => 'WhatsAppCommerceHub\Core\ErrorHandler',
			'WCH_Encryption'            => 'WhatsAppCommerceHub\Infrastructure\Security\Encryption',
			'WCH_Database_Manager'      => 'WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager',
			'WCH_Settings'              => 'WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager',

			// Phase 3: Domain Layer - Cart Domain.
			'WCH_Cart_Manager'          => 'WhatsAppCommerceHub\Domain\Cart\CartService',
			'WCH_Cart_Exception'        => 'WhatsAppCommerceHub\Domain\Cart\CartException',
			
			// Phase 3: Domain Layer - Catalog/Product.
			'WCH_Product_Sync_Service'  => 'WhatsAppCommerceHub\Application\Services\ProductSyncService',
			'WCH_Catalog_Browser'       => 'WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser',
			'WCH_Order_Sync_Service'    => 'WhatsAppCommerceHub\Application\Services\OrderSyncService',
			'WCH_Inventory_Sync_Handler' => 'WhatsAppCommerceHub\Application\Services\InventorySyncService',
			'WCH_Customer_Profile'      => 'WhatsAppCommerceHub\Domain\Customer\CustomerProfile',
			'WCH_Customer_Service'      => 'WhatsAppCommerceHub\Domain\Customer\CustomerService',
			'WCH_Conversation_Context'  => 'WhatsAppCommerceHub\Domain\Conversation\Context',
			'WCH_Conversation_FSM'      => 'WhatsAppCommerceHub\Domain\Conversation\StateMachine',
			'WCH_Intent'                => 'WhatsAppCommerceHub\Domain\Conversation\Intent',
			'WCH_Intent_Classifier'     => 'WhatsAppCommerceHub\Support\AI\IntentClassifier',
			'WCH_Context_Manager'       => 'WhatsAppCommerceHub\Support\AI\ConversationContext',
			'WCH_Parsed_Response'       => 'WhatsAppCommerceHub\ValueObjects\ParsedResponse',
			'WCH_Action_Result'         => 'WhatsAppCommerceHub\ValueObjects\ActionResult',
			'WCH_Exception'             => 'WhatsAppCommerceHub\Exceptions\WchException',
			'WCH_API_Exception'         => 'WhatsAppCommerceHub\Exceptions\ApiException',

			// Phase 4: Infrastructure Layer.
			'WCH_REST_API'              => 'WhatsAppCommerceHub\Infrastructure\Api\Rest\RestApi',
			'WCH_REST_Controller'       => 'WhatsAppCommerceHub\Infrastructure\Api\Rest\RestController',
			'WCH_Webhook_Handler'       => 'WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\WebhookController',
			'WCH_WhatsApp_API_Client'   => 'WhatsAppCommerceHub\Infrastructure\Api\Clients\WhatsAppApiClient',
			'WCH_Conversations_Controller' => 'WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\ConversationsController',
			'WCH_Analytics_Controller'  => 'WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\AnalyticsController',
			'WCH_Queue'                 => 'WhatsAppCommerceHub\Infrastructure\Queue\QueueManager',
			'WCH_Job_Dispatcher'        => 'WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher',
			'WCH_Sync_Job_Handler'      => 'WhatsAppCommerceHub\Infrastructure\Queue\Handlers\SyncJobHandler',

			// Phase 6: Presentation Layer.
			'WCH_Admin_Analytics'       => 'WhatsAppCommerceHub\Presentation\Admin\Pages\AnalyticsPage',
			'WCH_Admin_Catalog_Sync'    => 'WhatsAppCommerceHub\Presentation\Admin\Pages\CatalogSyncPage',
			'WCH_Admin_Inbox'           => 'WhatsAppCommerceHub\Presentation\Admin\Pages\InboxPage',
			'WCH_Admin_Jobs'            => 'WhatsAppCommerceHub\Presentation\Admin\Pages\JobsPage',
			'WCH_Admin_Logs'            => 'WhatsAppCommerceHub\Presentation\Admin\Pages\LogsPage',
			'WCH_Admin_Templates'       => 'WhatsAppCommerceHub\Presentation\Admin\Pages\TemplatesPage',
			'WCH_Admin_Broadcasts'      => 'WhatsAppCommerceHub\Presentation\Admin\Pages\BroadcastsPage',
			'WCH_Admin_Settings'        => 'WhatsAppCommerceHub\Presentation\Admin\Pages\SettingsPage',
			'WCH_Dashboard_Widgets'     => 'WhatsAppCommerceHub\Presentation\Admin\Widgets\DashboardWidgets',
			'WCH_Template_Manager'      => 'WhatsAppCommerceHub\Presentation\Templates\TemplateManager',
			'WCH_Flow_Action'           => 'WhatsAppCommerceHub\Presentation\Actions\AbstractAction',
			'WCH_Action_AddToCart'      => 'WhatsAppCommerceHub\Presentation\Actions\AddToCartAction',
			'WCH_Action_ShowCart'       => 'WhatsAppCommerceHub\Presentation\Actions\ShowCartAction',
			'WCH_Action_ShowProduct'    => 'WhatsAppCommerceHub\Presentation\Actions\ShowProductAction',
			'WCH_Action_ShowCategory'   => 'WhatsAppCommerceHub\Presentation\Actions\ShowCategoryAction',
			'WCH_Action_ShowMainMenu'   => 'WhatsAppCommerceHub\Presentation\Actions\ShowMainMenuAction',
			'WCH_Action_RequestAddress' => 'WhatsAppCommerceHub\Presentation\Actions\RequestAddressAction',
			'WCH_Action_ConfirmOrder'   => 'WhatsAppCommerceHub\Presentation\Actions\ConfirmOrderAction',
			'WCH_Action_ProcessPayment' => 'WhatsAppCommerceHub\Presentation\Actions\ProcessPaymentAction',

			// Phase 7: Feature Modules.
			'WCH_Abandoned_Cart_Recovery' => 'WhatsAppCommerceHub\Features\AbandonedCart\RecoveryService',
			'WCH_Abandoned_Cart_Handler' => 'WhatsAppCommerceHub\Features\AbandonedCart\CartHandler',
			'WCH_Cart_Cleanup_Handler'  => 'WhatsAppCommerceHub\Features\AbandonedCart\CleanupHandler',
			'WCH_Reengagement_Service'  => 'WhatsAppCommerceHub\Features\Reengagement\ReengagementService',
			'WCH_Broadcast_Job_Handler' => 'WhatsAppCommerceHub\Features\Broadcasts\BroadcastJobHandler',
			'WCH_Analytics_Data'        => 'WhatsAppCommerceHub\Features\Analytics\AnalyticsData',
			'WCH_Order_Notifications'   => 'WhatsAppCommerceHub\Features\Notifications\OrderNotifications',
			'WCH_Refund_Handler'        => 'WhatsAppCommerceHub\Features\Payments\RefundService',
			'WCH_Payment_Webhook_Handler' => 'WhatsAppCommerceHub\Features\Payments\WebhookHandler',

			// Phase 8: Support & Utilities.
			'WCH_Response_Parser'       => 'WhatsAppCommerceHub\Support\AI\ResponseParser',
			'WCH_AI_Assistant'          => 'WhatsAppCommerceHub\Support\AI\AiAssistant',
			'WCH_Message_Builder'       => 'WhatsAppCommerceHub\Support\Messaging\MessageBuilder',
			'WCH_Address_Parser'        => 'WhatsAppCommerceHub\Support\Utilities\AddressParser',
		);

		return apply_filters( 'wch_legacy_class_mapping', self::$mapping );
	}

	/**
	 * Get new class name for legacy class
	 *
	 * @param string $old_class Legacy class name.
	 * @return string|null New class name or null if not mapped.
	 */
	public static function getNewClass( string $old_class ): ?string {
		$mapping = self::getMapping();
		return $mapping[ $old_class ] ?? null;
	}

	/**
	 * Check if a class is legacy
	 *
	 * @param string $class_name Class name to check.
	 * @return bool True if legacy.
	 */
	public static function isLegacy( string $class_name ): bool {
		return isset( self::getMapping()[ $class_name ] );
	}

	/**
	 * Get legacy class for new class
	 *
	 * Reverse lookup.
	 *
	 * @param string $new_class New PSR-4 class name.
	 * @return string|null Legacy class name or null if not found.
	 */
	public static function getLegacyClass( string $new_class ): ?string {
		$mapping = array_flip( self::getMapping() );
		return $mapping[ $new_class ] ?? null;
	}
}
