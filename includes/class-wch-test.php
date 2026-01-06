<?php
/**
 * Test class to verify autoloader functionality.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test class for autoloader verification.
 */
class WCH_Test {
	/**
	 * Test method to verify class is loaded.
	 *
	 * @return string Test message.
	 */
	public function test_autoloader() {
		return 'Autoloader is working correctly!';
	}
}
