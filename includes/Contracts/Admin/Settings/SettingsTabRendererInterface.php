<?php
/**
 * Settings Tab Renderer Interface
 *
 * Contract for rendering admin settings tabs.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Admin\Settings;

use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface SettingsTabRendererInterface
 *
 * Defines contract for settings tab rendering.
 */
interface SettingsTabRendererInterface {

	/**
	 * Render a specific settings tab.
	 *
	 * @param string            $tab      Tab identifier.
	 * @param SettingsInterface $settings Settings service.
	 * @return void
	 */
	public function renderTab( string $tab, SettingsInterface $settings ): void;

	/**
	 * Get available tabs configuration.
	 *
	 * @return array<string, string> Tab ID => Tab Label.
	 */
	public function getTabs(): array;

	/**
	 * Render the tab navigation.
	 *
	 * @param string $activeTab Currently active tab.
	 * @return void
	 */
	public function renderTabNavigation( string $activeTab ): void;
}
