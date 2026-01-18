<?php
/**
 * Unit tests for CampaignDispatcher
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

use WhatsAppCommerceHub\Application\Services\Broadcasts\CampaignDispatcher;
use WhatsAppCommerceHub\Application\Services\Broadcasts\CampaignRepository;
use WhatsAppCommerceHub\Application\Services\Broadcasts\AudienceCalculator;
use WhatsAppCommerceHub\Application\Services\Broadcasts\BroadcastTemplateBuilder;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;

/**
 * Test CampaignDispatcher class.
 */
class CampaignDispatcherTest extends WCH_Unit_Test_Case {

	/**
	 * Campaign dispatcher instance.
	 *
	 * @var CampaignDispatcher
	 */
	private CampaignDispatcher $dispatcher;

	/**
	 * Mock repository.
	 *
	 * @var Mockery\MockInterface
	 */
	private $mock_repository;

	/**
	 * Mock audience calculator.
	 *
	 * @var Mockery\MockInterface
	 */
	private $mock_audience_calculator;

	/**
	 * Mock settings.
	 *
	 * @var Mockery\MockInterface
	 */
	private $mock_settings;

	/**
	 * Mock template builder.
	 *
	 * @var Mockery\MockInterface
	 */
	private $mock_template_builder;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Create mocks.
		$this->mock_repository          = Mockery::mock( CampaignRepository::class );
		$this->mock_audience_calculator = Mockery::mock( AudienceCalculator::class );
		$this->mock_settings            = Mockery::mock( SettingsInterface::class );
		$this->mock_template_builder    = Mockery::mock( BroadcastTemplateBuilder::class );

		// Create dispatcher instance.
		$this->dispatcher = new CampaignDispatcher(
			$this->mock_repository,
			$this->mock_audience_calculator,
			$this->mock_settings,
			$this->mock_template_builder
		);
	}

	/**
	 * Test scheduling campaign with recipients.
	 */
	public function test_schedule_dispatches_batches() {
		$campaign = [
			'id'            => 123,
			'name'          => 'Test Campaign',
			'template_name' => 'welcome_message',
			'template_data' => [],
			'audience'      => [ 'audience_all' => true ],
		];

		$recipients = array_map(
			fn( $i ) => '+1234567' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ),
			range( 1, 150 )
		);

		// Mock audience calculator to return recipients.
		$this->mock_audience_calculator->shouldReceive( 'getRecipients' )
			->once()
			->with( $campaign['audience'] )
			->andReturn( $recipients );

		// Mock repository update calls.
		$this->mock_repository->shouldReceive( 'updateStatus' )
			->once()
			->andReturn( true );

		$this->mock_repository->shouldReceive( 'updateStats' )
			->once()
			->andReturn( true );

		// Schedule campaign.
		$job_id = $this->dispatcher->schedule( $campaign );

		$this->assertIsString( $job_id );
		$this->assertStringStartsWith( 'broadcast_123_', $job_id );
	}

	/**
	 * Test scheduling campaign with no recipients returns null.
	 */
	public function test_schedule_returns_null_with_no_recipients() {
		$campaign = [
			'id'            => 123,
			'name'          => 'Test Campaign',
			'template_name' => 'welcome_message',
			'template_data' => [],
			'audience'      => [ 'audience_all' => true ],
		];

		// Mock audience calculator to return empty array.
		$this->mock_audience_calculator->shouldReceive( 'getRecipients' )
			->once()
			->with( $campaign['audience'] )
			->andReturn( [] );

		// Schedule campaign.
		$job_id = $this->dispatcher->schedule( $campaign );

		$this->assertNull( $job_id );
	}

	/**
	 * Test scheduling with delay sets scheduled status.
	 */
	public function test_schedule_with_delay_sets_scheduled_status() {
		$campaign = [
			'id'            => 123,
			'name'          => 'Test Campaign',
			'template_name' => 'welcome_message',
			'template_data' => [],
			'audience'      => [ 'audience_all' => true ],
		];

		$recipients = [ '+1234567890', '+1234567891' ];

		// Mock audience calculator.
		$this->mock_audience_calculator->shouldReceive( 'getRecipients' )
			->once()
			->andReturn( $recipients );

		// Mock repository - verify status is 'scheduled'.
		$this->mock_repository->shouldReceive( 'updateStatus' )
			->once()
			->with( 123, 'scheduled', Mockery::on( function ( $extra_data ) {
				return isset( $extra_data['scheduled_at'] )
					&& isset( $extra_data['job_id'] )
					&& isset( $extra_data['total_batches'] );
			} ) )
			->andReturn( true );

		$this->mock_repository->shouldReceive( 'updateStats' )
			->once()
			->andReturn( true );

		// Schedule with delay.
		$job_id = $this->dispatcher->schedule( $campaign, 3600 );

		$this->assertIsString( $job_id );
	}

	/**
	 * Test scheduling without delay sets sending status.
	 */
	public function test_schedule_without_delay_sets_sending_status() {
		$campaign = [
			'id'            => 123,
			'name'          => 'Test Campaign',
			'template_name' => 'welcome_message',
			'template_data' => [],
			'audience'      => [ 'audience_all' => true ],
		];

		$recipients = [ '+1234567890', '+1234567891' ];

		// Mock audience calculator.
		$this->mock_audience_calculator->shouldReceive( 'getRecipients' )
			->once()
			->andReturn( $recipients );

		// Mock repository - verify status is 'sending'.
		$this->mock_repository->shouldReceive( 'updateStatus' )
			->once()
			->with( 123, 'sending', Mockery::on( function ( $extra_data ) {
				return isset( $extra_data['sent_at'] )
					&& isset( $extra_data['job_id'] )
					&& isset( $extra_data['total_batches'] );
			} ) )
			->andReturn( true );

		$this->mock_repository->shouldReceive( 'updateStats' )
			->once()
			->andReturn( true );

		// Schedule without delay.
		$job_id = $this->dispatcher->schedule( $campaign, 0 );

		$this->assertIsString( $job_id );
	}

	/**
	 * Test build message creates proper structure.
	 */
	public function test_build_message_creates_proper_structure() {
		$campaign = [
			'template_name'   => 'welcome_message',
			'template_data'   => [ 'language' => 'en' ],
			'personalization' => [
				'1' => [
					'type'  => 'customer_name',
					'value' => '',
				],
			],
		];

		$message = $this->dispatcher->buildMessage( $campaign );

		$this->assertIsArray( $message );
		$this->assertArrayHasKey( 'template_name', $message );
		$this->assertArrayHasKey( 'template_data', $message );
		$this->assertArrayHasKey( 'variables', $message );
		$this->assertEquals( 'welcome_message', $message['template_name'] );
	}

	/**
	 * Test get estimated cost calculation.
	 */
	public function test_get_estimated_cost_calculates_correctly() {
		$cost = $this->dispatcher->getEstimatedCost( 100 );
		$this->assertEquals( 0.58, $cost );

		$cost = $this->dispatcher->getEstimatedCost( 1000 );
		$this->assertEquals( 5.8, $cost );
	}

	/**
	 * Test cancelling a scheduled campaign.
	 */
	public function test_cancel_cancels_scheduled_campaign() {
		$campaign = [
			'id'     => 123,
			'status' => 'scheduled',
			'job_id' => 'broadcast_123_456',
		];

		// Mock repository.
		$this->mock_repository->shouldReceive( 'getById' )
			->once()
			->with( 123 )
			->andReturn( $campaign );

		$this->mock_repository->shouldReceive( 'updateStatus' )
			->once()
			->with( 123, 'cancelled' )
			->andReturn( true );

		$cancelled = $this->dispatcher->cancel( 123 );

		$this->assertTrue( $cancelled );
	}

	/**
	 * Test cancelling non-scheduled campaign returns false.
	 */
	public function test_cancel_returns_false_for_non_scheduled() {
		$campaign = [
			'id'     => 123,
			'status' => 'sending', // Not scheduled.
		];

		// Mock repository.
		$this->mock_repository->shouldReceive( 'getById' )
			->once()
			->with( 123 )
			->andReturn( $campaign );

		$cancelled = $this->dispatcher->cancel( 123 );

		$this->assertFalse( $cancelled );
	}

	/**
	 * Test cancelling non-existent campaign returns false.
	 */
	public function test_cancel_returns_false_for_non_existent() {
		// Mock repository.
		$this->mock_repository->shouldReceive( 'getById' )
			->once()
			->with( 999 )
			->andReturn( null );

		$cancelled = $this->dispatcher->cancel( 999 );

		$this->assertFalse( $cancelled );
	}
}
