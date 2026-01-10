<?php
/**
 * Admin Settings Controller
 *
 * Handles menu registration, routing, and page rendering.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Presentation\Admin\Pages;

use WhatsAppCommerceHub\Contracts\Admin\Settings\SettingsTabRendererInterface;
use WhatsAppCommerceHub\Contracts\Admin\Settings\SettingsSanitizerInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.LineLength.MaxExceeded
// Long lines in inline CSS and i18n strings are acceptable for readability.

/**
 * Class AdminSettingsController
 *
 * Controls admin settings page.
 */
class AdminSettingsController {

	/**
	 * Menu slug.
	 */
	public const MENU_SLUG = 'wch-settings';

	/**
	 * Capability required.
	 */
	protected const CAPABILITY = 'manage_woocommerce';

	/**
	 * Settings service.
	 *
	 * @var SettingsInterface
	 */
	protected SettingsInterface $settings;

	/**
	 * Tab renderer.
	 *
	 * @var SettingsTabRendererInterface
	 */
	protected SettingsTabRendererInterface $tabRenderer;

	/**
	 * Settings sanitizer.
	 *
	 * @var SettingsSanitizerInterface
	 */
	protected SettingsSanitizerInterface $sanitizer;

	/**
	 * AJAX handler.
	 *
	 * @var SettingsAjaxHandler
	 */
	protected SettingsAjaxHandler $ajaxHandler;

	/**
	 * Constructor.
	 *
	 * @param SettingsInterface            $settings    Settings service.
	 * @param SettingsTabRendererInterface $tabRenderer Tab renderer.
	 * @param SettingsSanitizerInterface   $sanitizer   Settings sanitizer.
	 * @param SettingsAjaxHandler          $ajaxHandler AJAX handler.
	 */
	public function __construct(
		SettingsInterface $settings,
		SettingsTabRendererInterface $tabRenderer,
		SettingsSanitizerInterface $sanitizer,
		SettingsAjaxHandler $ajaxHandler
	) {
		$this->settings    = $settings;
		$this->tabRenderer = $tabRenderer;
		$this->sanitizer   = $sanitizer;
		$this->ajaxHandler = $ajaxHandler;
	}

	/**
	 * Initialize controller.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'addMenuItem' ), 50 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueScripts' ) );
		add_action( 'admin_post_wch_save_settings', array( $this, 'handleSaveSettings' ) );

		// Register AJAX handlers.
		$this->ajaxHandler->register();
	}

	/**
	 * Add admin menu item.
	 *
	 * @return void
	 */
	public function addMenuItem(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'WhatsApp Commerce Hub Settings', 'whatsapp-commerce-hub' ),
			__( 'WhatsApp Commerce Hub', 'whatsapp-commerce-hub' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'renderPage' )
		);

		add_action( 'load-' . $hook, array( $this, 'addHelpTab' ) );
	}

	/**
	 * Add contextual help tab.
	 *
	 * @return void
	 */
	public function addHelpTab(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'wch_settings_help',
				'title'   => __( 'Settings Help', 'whatsapp-commerce-hub' ),
				'content' => '<p>' . __( 'Configure your WhatsApp Commerce Hub settings here. For detailed documentation, visit:', 'whatsapp-commerce-hub' ) . '</p>' .
							'<ul>' .
							'<li><a href="https://developers.facebook.com/docs/whatsapp/business-platform" target="_blank">' . __( 'WhatsApp Business Platform Documentation', 'whatsapp-commerce-hub' ) . '</a></li>' .
							'<li><a href="https://woocommerce.com/documentation/" target="_blank">' . __( 'WooCommerce Documentation', 'whatsapp-commerce-hub' ) . '</a></li>' .
							'</ul>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . __( 'For more information:', 'whatsapp-commerce-hub' ) . '</strong></p>' .
			'<p><a href="https://developers.facebook.com/docs/whatsapp" target="_blank">' . __( 'WhatsApp Docs', 'whatsapp-commerce-hub' ) . '</a></p>'
		);
	}

	/**
	 * Enqueue scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueScripts( string $hook ): void {
		if ( 'woocommerce_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wch-admin-settings',
			WCH_PLUGIN_URL . 'assets/css/admin-settings.css',
			array(),
			WCH_VERSION
		);

		wp_enqueue_script(
			'wch-admin-settings',
			WCH_PLUGIN_URL . 'assets/js/admin-settings.js',
			array( 'jquery' ),
			WCH_VERSION,
			true
		);

		wp_localize_script(
			'wch-admin-settings',
			'wchSettings',
			array(
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'wch_settings_nonce' ),
				'webhook_url' => rest_url( 'wch/v1/webhook' ),
				'strings'     => array(
					'testing'        => __( 'Testing...', 'whatsapp-commerce-hub' ),
					'syncing'        => __( 'Syncing...', 'whatsapp-commerce-hub' ),
					'success'        => __( 'Success', 'whatsapp-commerce-hub' ),
					'error'          => __( 'Error', 'whatsapp-commerce-hub' ),
					'copied'         => __( 'Copied!', 'whatsapp-commerce-hub' ),
					'confirm_reset'  => __( 'Are you sure you want to reset all settings to defaults? This cannot be undone.', 'whatsapp-commerce-hub' ),
					'confirm_clear'  => __( 'Are you sure you want to clear all logs?', 'whatsapp-commerce-hub' ),
					'settings_saved' => __( 'Settings saved successfully', 'whatsapp-commerce-hub' ),
					'settings_error' => __( 'Error saving settings', 'whatsapp-commerce-hub' ),
				),
			)
		);
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'whatsapp-commerce-hub' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$activeTab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'connection';

		// Ensure verify token exists.
		$verifyToken = $this->settings->get( 'api.webhook_verify_token' );
		if ( empty( $verifyToken ) ) {
			$verifyToken = wp_generate_password( 32, false );
			$this->settings->set( 'api.webhook_verify_token', $verifyToken );
		}

		?>
		<div class="wrap wch-settings-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors( 'wch_settings' ); ?>

			<?php $this->tabRenderer->renderTabNavigation( $activeTab ); ?>

			<div class="wch-settings-content">
				<form method="post" id="wch-settings-form">
					<?php wp_nonce_field( 'wch_save_settings', 'wch_settings_nonce' ); ?>
					<input type="hidden" name="active_tab" value="<?php echo esc_attr( $activeTab ); ?>">

					<?php $this->tabRenderer->renderTab( $activeTab, $this->settings ); ?>

					<p class="submit">
						<button type="submit" class="button button-primary button-large" id="wch-save-settings">
							<?php esc_html_e( 'Save Settings', 'whatsapp-commerce-hub' ); ?>
						</button>
						<span class="spinner"></span>
						<span class="wch-save-message"></span>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle settings form submission.
	 *
	 * @return void
	 */
	public function handleSaveSettings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied', 'whatsapp-commerce-hub' ) );
		}

		check_admin_referer( 'wch_save_settings', 'wch_settings_nonce' );

		$activeTab = isset( $_POST['active_tab'] ) ? sanitize_key( $_POST['active_tab'] ) : 'connection';

		// Process each tab's settings.
		$sections = array( 'api', 'catalog', 'checkout', 'notifications', 'ai', 'advanced' );

		foreach ( $sections as $section ) {
			if ( isset( $_POST[ $section ] ) && is_array( $_POST[ $section ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				foreach ( $_POST[ $section ] as $key => $value ) {
					$settingKey     = $section . '.' . sanitize_key( $key );
					$sanitizedValue = $this->sanitizer->sanitize( $value, $key );
					$this->settings->set( $settingKey, $sanitizedValue );
				}
			}
		}

		add_settings_error(
			'wch_settings',
			'settings_updated',
			__( 'Settings saved successfully.', 'whatsapp-commerce-hub' ),
			'success'
		);

		set_transient( 'settings_errors', get_settings_errors(), 30 );

		$redirectUrl = add_query_arg(
			array(
				'page'             => self::MENU_SLUG,
				'tab'              => $activeTab,
				'settings-updated' => 'true',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirectUrl );
		exit;
	}

	/**
	 * Get the settings service.
	 *
	 * @return SettingsInterface
	 */
	public function getSettings(): SettingsInterface {
		return $this->settings;
	}

	/**
	 * Get the tab renderer.
	 *
	 * @return SettingsTabRendererInterface
	 */
	public function getTabRenderer(): SettingsTabRendererInterface {
		return $this->tabRenderer;
	}
}
