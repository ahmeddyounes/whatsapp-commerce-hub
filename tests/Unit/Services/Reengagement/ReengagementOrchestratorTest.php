<?php
/**
 * Reengagement Orchestrator Tests
 *
 * Tests initialization and DI setup of the ReengagementOrchestrator.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

use WhatsAppCommerceHub\Application\Services\Reengagement\ReengagementOrchestrator;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use WhatsAppCommerceHub\Contracts\Services\CustomerServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\InactiveCustomerIdentifierInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\CampaignTypeResolverInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\ReengagementMessageBuilderInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\FrequencyCapManagerInterface;
use WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher;
use WhatsAppCommerceHub\Clients\WhatsAppApiClient;
use Mockery;

/**
 * Class ReengagementOrchestratorTest
 *
 * Tests the ReengagementOrchestrator service.
 */
class ReengagementOrchestratorTest extends WCH_Unit_Test_Case {

	/**
	 * Test orchestrator can be constructed with all dependencies via DI.
	 */
	public function test_orchestrator_constructor_with_all_dependencies(): void {
		$settings           = Mockery::mock( SettingsInterface::class );
		$customerIdentifier = Mockery::mock( InactiveCustomerIdentifierInterface::class );
		$campaignResolver   = Mockery::mock( CampaignTypeResolverInterface::class );
		$messageBuilder     = Mockery::mock( ReengagementMessageBuilderInterface::class );
		$frequencyCap       = Mockery::mock( FrequencyCapManagerInterface::class );
		$customerService    = Mockery::mock( CustomerServiceInterface::class );
		$jobDispatcher      = Mockery::mock( JobDispatcher::class );
		$logger             = Mockery::mock( LoggerInterface::class );

		$orchestrator = new ReengagementOrchestrator(
			$settings,
			$customerIdentifier,
			$campaignResolver,
			$messageBuilder,
			$frequencyCap,
			$customerService,
			$jobDispatcher,
			$logger
		);

		$this->assertInstanceOf( ReengagementOrchestrator::class, $orchestrator );
	}

	/**
	 * Test init() schedules all required jobs.
	 */
	public function test_init_schedules_all_jobs(): void {
		$settings           = Mockery::mock( SettingsInterface::class );
		$customerIdentifier = Mockery::mock( InactiveCustomerIdentifierInterface::class );
		$campaignResolver   = Mockery::mock( CampaignTypeResolverInterface::class );
		$messageBuilder     = Mockery::mock( ReengagementMessageBuilderInterface::class );
		$frequencyCap       = Mockery::mock( FrequencyCapManagerInterface::class );
		$customerService    = Mockery::mock( CustomerServiceInterface::class );
		$jobDispatcher      = Mockery::mock( JobDispatcher::class );
		$logger             = Mockery::mock( LoggerInterface::class );

		$orchestrator = new ReengagementOrchestrator(
			$settings,
			$customerIdentifier,
			$campaignResolver,
			$messageBuilder,
			$frequencyCap,
			$customerService,
			$jobDispatcher,
			$logger
		);

		// Mock Action Scheduler functions.
		global $wch_test_scheduled_actions;
		$wch_test_scheduled_actions = [];

		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			function as_next_scheduled_action( $hook, $args = [], $group = '' ) {
				global $wch_test_scheduled_actions;
				$key = $hook . ':' . $group;
				return $wch_test_scheduled_actions[ $key ] ?? false;
			}
		}

		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = [], $group = '' ) {
				global $wch_test_scheduled_actions;
				$key                                = $hook . ':' . $group;
				$wch_test_scheduled_actions[ $key ] = time();
				return true;
			}
		}

		// Initialize the orchestrator.
		$orchestrator->init();

		// Verify that all three jobs were scheduled.
		$this->assertNotFalse(
			$wch_test_scheduled_actions['wch_process_reengagement_campaigns:wch'] ?? false,
			'wch_process_reengagement_campaigns should be scheduled'
		);
		$this->assertNotFalse(
			$wch_test_scheduled_actions['wch_check_back_in_stock:wch'] ?? false,
			'wch_check_back_in_stock should be scheduled'
		);
		$this->assertNotFalse(
			$wch_test_scheduled_actions['wch_check_price_drops:wch'] ?? false,
			'wch_check_price_drops should be scheduled'
		);
	}

	/**
	 * Test isEnabled() respects settings.
	 */
	public function test_is_enabled_respects_settings(): void {
		$settings           = Mockery::mock( SettingsInterface::class );
		$customerIdentifier = Mockery::mock( InactiveCustomerIdentifierInterface::class );
		$campaignResolver   = Mockery::mock( CampaignTypeResolverInterface::class );
		$messageBuilder     = Mockery::mock( ReengagementMessageBuilderInterface::class );
		$frequencyCap       = Mockery::mock( FrequencyCapManagerInterface::class );
		$customerService    = Mockery::mock( CustomerServiceInterface::class );
		$jobDispatcher      = Mockery::mock( JobDispatcher::class );
		$logger             = Mockery::mock( LoggerInterface::class );

		// Test when enabled.
		$settings->shouldReceive( 'get' )
			->with( 'reengagement.enabled', false )
			->andReturn( true );

		$orchestrator = new ReengagementOrchestrator(
			$settings,
			$customerIdentifier,
			$campaignResolver,
			$messageBuilder,
			$frequencyCap,
			$customerService,
			$jobDispatcher,
			$logger
		);

		$this->assertTrue( $orchestrator->isEnabled() );

		// Test when disabled.
		$settings2 = Mockery::mock( SettingsInterface::class );
		$settings2->shouldReceive( 'get' )
			->with( 'reengagement.enabled', false )
			->andReturn( false );

		$orchestrator2 = new ReengagementOrchestrator(
			$settings2,
			$customerIdentifier,
			$campaignResolver,
			$messageBuilder,
			$frequencyCap,
			$customerService,
			$jobDispatcher,
			$logger
		);

		$this->assertFalse( $orchestrator2->isEnabled() );
	}

	/**
	 * Test queueMessage() dispatches job via JobDispatcher.
	 */
	public function test_queue_message_dispatches_job(): void {
		$settings           = Mockery::mock( SettingsInterface::class );
		$customerIdentifier = Mockery::mock( InactiveCustomerIdentifierInterface::class );
		$campaignResolver   = Mockery::mock( CampaignTypeResolverInterface::class );
		$messageBuilder     = Mockery::mock( ReengagementMessageBuilderInterface::class );
		$frequencyCap       = Mockery::mock( FrequencyCapManagerInterface::class );
		$customerService    = Mockery::mock( CustomerServiceInterface::class );
		$jobDispatcher      = Mockery::mock( JobDispatcher::class );
		$logger             = Mockery::mock( LoggerInterface::class );

		$customer = [
			'phone' => '+1234567890',
			'name'  => 'Test Customer',
		];

		// Mock campaign resolver.
		$campaignResolver->shouldReceive( 'resolve' )
			->with( $customer )
			->andReturn( 'back_in_stock' );

		// Mock job dispatcher.
		$jobDispatcher->shouldReceive( 'dispatch' )
			->once()
			->with(
				'wch_send_reengagement_message',
				[
					'customer_phone' => '+1234567890',
					'campaign_type'  => 'back_in_stock',
				],
				0
			)
			->andReturn( true );

		// Mock logger.
		$logger->shouldReceive( 'log' )
			->with( 'debug', Mockery::any(), 'reengagement', Mockery::any() )
			->andReturn( null );

		$orchestrator = new ReengagementOrchestrator(
			$settings,
			$customerIdentifier,
			$campaignResolver,
			$messageBuilder,
			$frequencyCap,
			$customerService,
			$jobDispatcher,
			$logger
		);

		$result = $orchestrator->queueMessage( $customer );

		$this->assertTrue( $result );
	}

	/**
	 * Test processCampaigns() returns 0 when disabled.
	 */
	public function test_process_campaigns_returns_zero_when_disabled(): void {
		$settings           = Mockery::mock( SettingsInterface::class );
		$customerIdentifier = Mockery::mock( InactiveCustomerIdentifierInterface::class );
		$campaignResolver   = Mockery::mock( CampaignTypeResolverInterface::class );
		$messageBuilder     = Mockery::mock( ReengagementMessageBuilderInterface::class );
		$frequencyCap       = Mockery::mock( FrequencyCapManagerInterface::class );
		$customerService    = Mockery::mock( CustomerServiceInterface::class );
		$jobDispatcher      = Mockery::mock( JobDispatcher::class );
		$logger             = Mockery::mock( LoggerInterface::class );

		$settings->shouldReceive( 'get' )
			->with( 'reengagement.enabled', false )
			->andReturn( false );

		$orchestrator = new ReengagementOrchestrator(
			$settings,
			$customerIdentifier,
			$campaignResolver,
			$messageBuilder,
			$frequencyCap,
			$customerService,
			$jobDispatcher,
			$logger
		);

		$result = $orchestrator->processCampaigns();

		$this->assertSame( 0, $result );
	}

	/**
	 * Test setApiClient() allows optional API client injection.
	 */
	public function test_set_api_client_allows_optional_injection(): void {
		$settings           = Mockery::mock( SettingsInterface::class );
		$customerIdentifier = Mockery::mock( InactiveCustomerIdentifierInterface::class );
		$campaignResolver   = Mockery::mock( CampaignTypeResolverInterface::class );
		$messageBuilder     = Mockery::mock( ReengagementMessageBuilderInterface::class );
		$frequencyCap       = Mockery::mock( FrequencyCapManagerInterface::class );
		$customerService    = Mockery::mock( CustomerServiceInterface::class );
		$jobDispatcher      = Mockery::mock( JobDispatcher::class );
		$logger             = Mockery::mock( LoggerInterface::class );
		$apiClient          = Mockery::mock( WhatsAppApiClient::class );

		$orchestrator = new ReengagementOrchestrator(
			$settings,
			$customerIdentifier,
			$campaignResolver,
			$messageBuilder,
			$frequencyCap,
			$customerService,
			$jobDispatcher,
			$logger
		);

		// Should not throw exception.
		try {
			$orchestrator->setApiClient( $apiClient );
			$this->assertTrue( true );
		} catch ( \Throwable $e ) {
			$this->fail( 'setApiClient should not throw exception: ' . $e->getMessage() );
		}
	}

	/**
	 * Clean up Mockery after each test.
	 */
	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}
}
