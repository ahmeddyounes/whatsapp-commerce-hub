<?php
/**
 * Container Override Detection Test
 *
 * Tests for Container override detection in development mode.
 *
 * @package WhatsApp_Commerce_Hub
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Tests\Unit\Container;

use WhatsAppCommerceHub\Container\Container;
use WCH_Unit_Test_Case;

/**
 * Class ContainerOverrideDetectionTest
 *
 * Test Container override detection functionality.
 */
class ContainerOverrideDetectionTest extends WCH_Unit_Test_Case {

	/**
	 * Container instance.
	 *
	 * @var Container
	 */
	protected Container $container;

	/**
	 * Track triggered errors.
	 *
	 * @var array
	 */
	protected array $triggered_errors = [];

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Create fresh container.
		$this->container = new Container();
		$this->triggered_errors = [];

		// Set error handler to capture warnings.
		set_error_handler( [ $this, 'errorHandler' ] );
	}

	/**
	 * Teardown after each test.
	 */
	protected function tearDown(): void {
		restore_error_handler();
		parent::tearDown();
	}

	/**
	 * Custom error handler to capture warnings.
	 *
	 * @param int    $errno   Error level.
	 * @param string $errstr  Error message.
	 * @param string $errfile File where error occurred.
	 * @param int    $errline Line number where error occurred.
	 * @return bool
	 */
	public function errorHandler( int $errno, string $errstr, string $errfile, int $errline ): bool {
		if ( $errno === E_USER_WARNING ) {
			$this->triggered_errors[] = $errstr;
			return true;
		}
		return false;
	}

	/**
	 * Test that bind() triggers warning when WP_DEBUG is enabled and binding is overridden.
	 */
	public function test_bind_detects_override_in_debug_mode(): void {
		// Skip if WP_DEBUG is not enabled.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG is not enabled' );
		}

		// Arrange - First binding.
		$this->container->bind( 'test.service', fn() => 'first' );

		// Act - Override the binding.
		$this->container->bind( 'test.service', fn() => 'second' );

		// Assert.
		$this->assertNotEmpty( $this->triggered_errors );
		$this->assertStringContainsString( 'Container binding override detected', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'test.service', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'Previous: Closure', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'New: Closure', $this->triggered_errors[0] );
	}

	/**
	 * Test that bind() provides detailed info for class binding overrides.
	 */
	public function test_bind_detects_class_binding_override(): void {
		// Skip if WP_DEBUG is not enabled.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG is not enabled' );
		}

		// Arrange - First binding with a class.
		$this->container->bind( 'test.interface', \stdClass::class );

		// Act - Override with different class.
		$this->container->bind( 'test.interface', \ArrayObject::class );

		// Assert.
		$this->assertNotEmpty( $this->triggered_errors );
		$this->assertStringContainsString( 'Container binding override detected', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'test.interface', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'Previous: "stdClass"', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'New: "ArrayObject"', $this->triggered_errors[0] );
	}

	/**
	 * Test that bind() indicates singleton vs transient in warning.
	 */
	public function test_bind_indicates_singleton_vs_transient(): void {
		// Skip if WP_DEBUG is not enabled.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG is not enabled' );
		}

		// Arrange - First binding as singleton.
		$this->container->singleton( 'test.service', fn() => 'first' );

		// Act - Override with transient binding.
		$this->container->bind( 'test.service', fn() => 'second', false );

		// Assert.
		$this->assertNotEmpty( $this->triggered_errors );
		$this->assertStringContainsString( 'Previous: Closure (singleton)', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'New: Closure (transient)', $this->triggered_errors[0] );
	}

	/**
	 * Test that alias() triggers warning when alias is overridden.
	 */
	public function test_alias_detects_override_in_debug_mode(): void {
		// Skip if WP_DEBUG is not enabled.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG is not enabled' );
		}

		// Arrange - First alias.
		$this->container->bind( 'original.service', \stdClass::class );
		$this->container->alias( 'original.service', 'alias.service' );

		// Act - Override the alias.
		$this->container->alias( 'other.service', 'alias.service' );

		// Assert.
		$this->assertNotEmpty( $this->triggered_errors );
		$this->assertStringContainsString( 'Container alias override detected', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'alias.service', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'Previous: "original.service"', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'New: "other.service" (alias)', $this->triggered_errors[0] );
	}

	/**
	 * Test that instance() triggers warning when instance is overridden.
	 */
	public function test_instance_detects_override_in_debug_mode(): void {
		// Skip if WP_DEBUG is not enabled.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG is not enabled' );
		}

		// Arrange - First instance.
		$first_instance = new \stdClass();
		$this->container->instance( 'test.instance', $first_instance );

		// Act - Override the instance.
		$second_instance = new \ArrayObject();
		$this->container->instance( 'test.instance', $second_instance );

		// Assert.
		$this->assertNotEmpty( $this->triggered_errors );
		$this->assertStringContainsString( 'Container instance override detected', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'test.instance', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'Previous instance type: stdClass', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'New instance type: ArrayObject', $this->triggered_errors[0] );
	}

	/**
	 * Test that override detection includes helpful context.
	 */
	public function test_override_warnings_include_helpful_context(): void {
		// Skip if WP_DEBUG is not enabled.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG is not enabled' );
		}

		// Arrange & Act.
		$this->container->bind( 'test.service', \stdClass::class );
		$this->container->bind( 'test.service', \ArrayObject::class );

		// Assert - Check for helpful context.
		$this->assertNotEmpty( $this->triggered_errors );
		$this->assertStringContainsString( 'Check your service providers', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'overwritten', $this->triggered_errors[0] );
	}

	/**
	 * Test that no warning is triggered on first binding.
	 */
	public function test_no_warning_on_first_binding(): void {
		// Skip if WP_DEBUG is not enabled.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG is not enabled' );
		}

		// Arrange & Act.
		$this->container->bind( 'test.service', \stdClass::class );

		// Assert.
		$this->assertEmpty( $this->triggered_errors );
	}

	/**
	 * Test that override detection works for interface bindings.
	 */
	public function test_bind_detects_interface_override(): void {
		// Skip if WP_DEBUG is not enabled.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG is not enabled' );
		}

		// Arrange - Use a real interface for testing.
		$this->container->bind( \Iterator::class, \ArrayIterator::class );

		// Act - Override the interface binding.
		$this->container->bind( \Iterator::class, \EmptyIterator::class );

		// Assert.
		$this->assertNotEmpty( $this->triggered_errors );
		$this->assertStringContainsString( 'Container binding override detected', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'Iterator', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'This is an interface binding', $this->triggered_errors[0] );
	}

	/**
	 * Test that override detection works for class bindings.
	 */
	public function test_bind_detects_class_override(): void {
		// Skip if WP_DEBUG is not enabled.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG is not enabled' );
		}

		// Arrange - Use a real class for testing.
		$this->container->bind( \stdClass::class, fn() => new \stdClass() );

		// Act - Override the class binding.
		$this->container->bind( \stdClass::class, fn() => (object) [ 'modified' => true ] );

		// Assert.
		$this->assertNotEmpty( $this->triggered_errors );
		$this->assertStringContainsString( 'Container binding override detected', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'stdClass', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'This is a class binding', $this->triggered_errors[0] );
	}

	/**
	 * Test that override detection works for alias bindings.
	 */
	public function test_bind_detects_alias_override(): void {
		// Skip if WP_DEBUG is not enabled.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG is not enabled' );
		}

		// Arrange - Create a non-class, non-interface binding (alias).
		$this->container->bind( 'my.custom.alias', \stdClass::class );

		// Act - Override the alias binding.
		$this->container->bind( 'my.custom.alias', \ArrayObject::class );

		// Assert.
		$this->assertNotEmpty( $this->triggered_errors );
		$this->assertStringContainsString( 'Container binding override detected', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'my.custom.alias', $this->triggered_errors[0] );
		$this->assertStringContainsString( 'This is an alias binding', $this->triggered_errors[0] );
	}
}
