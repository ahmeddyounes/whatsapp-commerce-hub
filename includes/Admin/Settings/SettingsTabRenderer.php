<?php
/**
 * Settings Tab Renderer Service
 *
 * Handles rendering of admin settings tabs.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Admin\Settings;

use WhatsAppCommerceHub\Contracts\Admin\Settings\SettingsTabRendererInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsTabRenderer
 *
 * Renders settings tabs in admin UI.
 */
class SettingsTabRenderer implements SettingsTabRendererInterface {

	/**
	 * Available tabs configuration.
	 *
	 * @var array<string, string>
	 */
	protected array $tabs = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->tabs = [
			'connection'    => __( 'Connection', 'whatsapp-commerce-hub' ),
			'catalog'       => __( 'Catalog', 'whatsapp-commerce-hub' ),
			'checkout'      => __( 'Checkout', 'whatsapp-commerce-hub' ),
			'notifications' => __( 'Notifications', 'whatsapp-commerce-hub' ),
			'ai'            => __( 'AI', 'whatsapp-commerce-hub' ),
			'advanced'      => __( 'Advanced', 'whatsapp-commerce-hub' ),
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function getTabs(): array {
		return $this->tabs;
	}

	/**
	 * {@inheritdoc}
	 */
	public function renderTabNavigation( string $activeTab ): void {
		?>
		<nav class="nav-tab-wrapper wch-nav-tab-wrapper">
			<?php foreach ( $this->tabs as $tabId => $label ) : ?>
				<a href="?page=wch-settings&tab=<?php echo esc_attr( $tabId ); ?>"
					class="nav-tab <?php echo esc_attr( $tabId === $activeTab ? 'nav-tab-active' : '' ); ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * {@inheritdoc}
	 */
	public function renderTab( string $tab, SettingsInterface $settings ): void {
		switch ( $tab ) {
			case 'connection':
				$this->renderConnectionTab( $settings );
				break;
			case 'catalog':
				$this->renderCatalogTab( $settings );
				break;
			case 'checkout':
				$this->renderCheckoutTab( $settings );
				break;
			case 'notifications':
				$this->renderNotificationsTab( $settings );
				break;
			case 'ai':
				$this->renderAiTab( $settings );
				break;
			case 'advanced':
				$this->renderAdvancedTab( $settings );
				break;
		}
	}

	/**
	 * Render Connection tab.
	 *
	 * @param SettingsInterface $settings Settings service.
	 * @return void
	 */
	protected function renderConnectionTab( SettingsInterface $settings ): void {
		$phoneNumberId = $settings->get( 'api.phone_number_id', '' );
		$businessId    = $settings->get( 'api.business_account_id', '' );
		$accessToken   = $settings->get( 'api.access_token', '' );
		$verifyToken   = $settings->get( 'api.webhook_verify_token', '' );
		$webhookUrl    = rest_url( 'wch/v1/webhook' );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="phone_number_id"><?php esc_html_e( 'WhatsApp Phone Number ID', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="text" name="api[phone_number_id]" id="phone_number_id"
								value="<?php echo esc_attr( $phoneNumberId ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'Your WhatsApp Business Account Phone Number ID', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="business_account_id"><?php esc_html_e( 'Business Account ID', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="text" name="api[business_account_id]" id="business_account_id"
								value="<?php echo esc_attr( $businessId ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'Your WhatsApp Business Account ID', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="access_token"><?php esc_html_e( 'Access Token', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="password" name="api[access_token]" id="access_token"
								value="<?php echo esc_attr( $accessToken ); ?>"
								class="regular-text" autocomplete="off">
						<p class="description">
							<?php esc_html_e( 'Your WhatsApp Business Platform Access Token (stored encrypted)', 'whatsapp-commerce-hub' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="verify_token"><?php esc_html_e( 'Webhook Verify Token', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="text" id="verify_token"
							value="<?php echo esc_attr( $verifyToken ); ?>"
							class="regular-text" readonly>
						<button type="button" class="button" id="regenerate-verify-token">
							<?php esc_html_e( 'Regenerate', 'whatsapp-commerce-hub' ); ?>
						</button>
						<p class="description">
							<?php
							esc_html_e(
								'Use this token when configuring webhooks in your WhatsApp Business Account',
								'whatsapp-commerce-hub'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="webhook_url"><?php esc_html_e( 'Webhook URL', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="text" id="webhook_url"
							value="<?php echo esc_url( $webhookUrl ); ?>"
							class="regular-text" readonly>
						<button type="button" class="button" id="copy-webhook-url">
							<?php esc_html_e( 'Copy', 'whatsapp-commerce-hub' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Configure this URL in your WhatsApp Business Account webhook settings', 'whatsapp-commerce-hub' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Test Connection', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<button type="button" class="button" id="test-connection">
							<?php esc_html_e( 'Test Connection', 'whatsapp-commerce-hub' ); ?>
						</button>
						<span class="spinner"></span>
						<div id="connection-status" class="wch-status-message"></div>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render Catalog tab.
	 *
	 * @param SettingsInterface $settings Settings service.
	 * @return void
	 */
	protected function renderCatalogTab( SettingsInterface $settings ): void {
		$syncEnabled = $settings->get( 'catalog.sync_enabled', false );
		$productMode = $settings->get( 'catalog.product_selection', 'all' );
		$categories  = $settings->get( 'catalog.categories', [] );
		$products    = $settings->get( 'catalog.products', [] );
		$includeOos  = $settings->get( 'catalog.include_out_of_stock', false );
		$lastSync    = $settings->get( 'catalog.last_sync', '' );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Enable Product Sync', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="catalog[sync_enabled]" value="1" <?php checked( $syncEnabled, true ); ?>>
							<?php esc_html_e( 'Enable automatic product catalog synchronization', 'whatsapp-commerce-hub' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Product Selection', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="radio" name="catalog[product_selection]" value="all" <?php checked( $productMode, 'all' ); ?>>
							<?php esc_html_e( 'All Products', 'whatsapp-commerce-hub' ); ?>
						</label><br>
						<label>
							<input type="radio" name="catalog[product_selection]" value="categories" <?php checked( $productMode, 'categories' ); ?>>
							<?php esc_html_e( 'Specific Categories', 'whatsapp-commerce-hub' ); ?>
						</label><br>
						<label>
							<input type="radio" name="catalog[product_selection]" value="products" <?php checked( $productMode, 'products' ); ?>>
							<?php esc_html_e( 'Specific Products', 'whatsapp-commerce-hub' ); ?>
						</label>
					</td>
				</tr>
				<tr class="wch-product-categories" <?php echo 'categories' !== $productMode ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="catalog_categories"><?php esc_html_e( 'Select Categories', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<?php $this->renderCategorySelect( $categories ); ?>
						<p class="description"><?php esc_html_e( 'Select which product categories to sync', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr class="wch-product-products" <?php echo 'products' !== $productMode ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="catalog_products"><?php esc_html_e( 'Select Products', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<?php $this->renderProductSelect( $products ); ?>
						<p class="description"><?php esc_html_e( 'Search and select specific products to sync', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Include Out of Stock', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="catalog[include_out_of_stock]" value="1" <?php checked( $includeOos, true ); ?>>
							<?php esc_html_e( 'Include out of stock products in catalog', 'whatsapp-commerce-hub' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Sync Status', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<button type="button" class="button" id="sync-catalog-now">
							<?php esc_html_e( 'Sync Now', 'whatsapp-commerce-hub' ); ?>
						</button>
						<span class="spinner"></span>
						<div id="sync-progress" class="wch-progress-bar" style="display:none;">
							<div class="wch-progress-fill"></div>
						</div>
						<?php if ( $lastSync ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: last sync date and time */
									esc_html__( 'Last synced: %s', 'whatsapp-commerce-hub' ),
									esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $lastSync ) ) )
								);
								?>
							</p>
						<?php endif; ?>
						<div id="sync-status" class="wch-status-message"></div>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render Checkout tab.
	 *
	 * @param SettingsInterface $settings Settings service.
	 * @return void
	 */
	protected function renderCheckoutTab( SettingsInterface $settings ): void {
		$enabledMethods = $settings->get( 'checkout.enabled_payment_methods', [] );
		$codEnabled     = $settings->get( 'checkout.cod_enabled', false );
		$codExtraCharge = $settings->get( 'checkout.cod_extra_charge', 0 );
		$minOrder       = $settings->get( 'checkout.min_order_amount', 0 );
		$maxOrder       = $settings->get( 'checkout.max_order_amount', 0 );
		$phoneVerify    = $settings->get( 'checkout.phone_verification', false );

		$availableGateways = $this->getAvailablePaymentGateways();
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Enabled Payment Methods', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<?php foreach ( $availableGateways as $gatewayId => $gatewayTitle ) : ?>
							<label>
								<input type="checkbox" name="checkout[enabled_payment_methods][]"
										value="<?php echo esc_attr( $gatewayId ); ?>"
										<?php checked( in_array( $gatewayId, (array) $enabledMethods, true ) ); ?>>
								<?php echo esc_html( $gatewayTitle ); ?>
							</label><br>
						<?php endforeach; ?>
						<p class="description">
							<?php esc_html_e( 'Select which payment methods are available for WhatsApp checkout', 'whatsapp-commerce-hub' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Cash on Delivery', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="checkout[cod_enabled]" value="1"
								<?php checked( $codEnabled, true ); ?>>
							<?php esc_html_e( 'Enable Cash on Delivery', 'whatsapp-commerce-hub' ); ?>
						</label>
					</td>
				</tr>
				<tr class="wch-cod-settings" <?php echo ! $codEnabled ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="cod_extra_charge">
							<?php esc_html_e( 'COD Extra Charge', 'whatsapp-commerce-hub' ); ?>
						</label>
					</th>
					<td>
						<input type="number" name="checkout[cod_extra_charge]" id="cod_extra_charge"
								value="<?php echo esc_attr( $codExtraCharge ); ?>"
								step="0.01" min="0" class="small-text">
						<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
						<p class="description">
							<?php esc_html_e( 'Additional charge for Cash on Delivery orders', 'whatsapp-commerce-hub' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Order Limits', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label for="min_order_amount">
							<?php esc_html_e( 'Minimum Order Amount:', 'whatsapp-commerce-hub' ); ?>
						</label>
						<input type="number" name="checkout[min_order_amount]" id="min_order_amount"
								value="<?php echo esc_attr( $minOrder ); ?>"
								step="0.01" min="0" class="small-text">
						<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
						<br><br>
						<label for="max_order_amount">
							<?php esc_html_e( 'Maximum Order Amount:', 'whatsapp-commerce-hub' ); ?>
						</label>
						<input type="number" name="checkout[max_order_amount]" id="max_order_amount"
								value="<?php echo esc_attr( $maxOrder ); ?>"
								step="0.01" min="0" class="small-text">
						<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
						<p class="description">
							<?php esc_html_e( 'Set minimum and maximum order amounts (0 for no limit)', 'whatsapp-commerce-hub' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Phone Verification', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="checkout[phone_verification]" value="1" <?php checked( $phoneVerify, true ); ?>>
							<?php esc_html_e( 'Require phone number verification for checkout', 'whatsapp-commerce-hub' ); ?>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render Notifications tab.
	 *
	 * @param SettingsInterface $settings Settings service.
	 * @return void
	 */
	protected function renderNotificationsTab( SettingsInterface $settings ): void {
		$notificationTypes = [
			'order_confirmation' => __( 'Order Confirmation', 'whatsapp-commerce-hub' ),
			'status_updates'     => __( 'Order Status Updates', 'whatsapp-commerce-hub' ),
			'shipping'           => __( 'Shipping Notifications', 'whatsapp-commerce-hub' ),
			'abandoned_cart'     => __( 'Abandoned Cart Reminders', 'whatsapp-commerce-hub' ),
		];

		$cartDelay = $settings->get( 'notifications.abandoned_cart_delay', 24 );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Notification Types', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<?php foreach ( $notificationTypes as $type => $label ) : ?>
							<?php $enabled = $settings->get( "notifications.{$type}_enabled", true ); ?>
							<div class="wch-notification-row">
								<label>
									<input type="checkbox" name="notifications[<?php echo esc_attr( $type ); ?>_enabled]"
											value="1" <?php checked( $enabled, true ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
								<button type="button" class="button button-small wch-test-notification"
										data-type="<?php echo esc_attr( $type ); ?>">
									<?php esc_html_e( 'Test', 'whatsapp-commerce-hub' ); ?>
								</button>
								<span class="spinner"></span>
								<span class="wch-test-result"></span>
							</div>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="abandoned_cart_delay">
							<?php esc_html_e( 'Abandoned Cart Delay', 'whatsapp-commerce-hub' ); ?>
						</label>
					</th>
					<td>
						<input type="number" name="notifications[abandoned_cart_delay]" id="abandoned_cart_delay"
								value="<?php echo esc_attr( $cartDelay ); ?>"
								min="1" max="168" class="small-text">
						<?php esc_html_e( 'hours', 'whatsapp-commerce-hub' ); ?>
						<p class="description">
							<?php esc_html_e( 'How long to wait before sending abandoned cart reminder (1-168 hours)', 'whatsapp-commerce-hub' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render AI tab.
	 *
	 * @param SettingsInterface $settings Settings service.
	 * @return void
	 */
	protected function renderAiTab( SettingsInterface $settings ): void {
		$aiEnabled    = $settings->get( 'ai.enabled', false );
		$openaiKey    = $settings->get( 'ai.openai_api_key', '' );
		$model        = $settings->get( 'ai.model', 'gpt-4' );
		$temperature  = $settings->get( 'ai.temperature', 0.7 );
		$systemPrompt = $settings->get( 'ai.system_prompt', '' );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Enable AI Assistant', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="ai[enabled]" value="1" <?php checked( $aiEnabled, true ); ?>>
							<?php esc_html_e( 'Enable AI-powered customer assistant', 'whatsapp-commerce-hub' ); ?>
						</label>
					</td>
				</tr>
				<tr class="wch-ai-settings" <?php echo ! $aiEnabled ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="password" name="ai[openai_api_key]" id="openai_api_key"
								value="<?php echo esc_attr( $openaiKey ); ?>" class="regular-text" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your OpenAI API key (stored encrypted)', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr class="wch-ai-settings" <?php echo ! $aiEnabled ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="ai_model"><?php esc_html_e( 'AI Model', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<select name="ai[model]" id="ai_model">
							<option value="gpt-4" <?php selected( $model, 'gpt-4' ); ?>>GPT-4</option>
							<option value="gpt-3.5-turbo" <?php selected( $model, 'gpt-3.5-turbo' ); ?>>GPT-3.5 Turbo</option>
						</select>
						<p class="description"><?php esc_html_e( 'Select the OpenAI model to use', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr class="wch-ai-settings" <?php echo ! $aiEnabled ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="ai_temperature"><?php esc_html_e( 'Temperature', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="range" name="ai[temperature]" id="ai_temperature"
							min="0" max="1" step="0.1"
							value="<?php echo esc_attr( $temperature ); ?>">
						<span id="temperature-value"><?php echo esc_html( $temperature ); ?></span>
						<p class="description">
							<?php esc_html_e( 'Controls randomness: 0 = focused, 1 = creative', 'whatsapp-commerce-hub' ); ?>
						</p>
					</td>
				</tr>
				<tr class="wch-ai-settings" <?php echo ! $aiEnabled ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="system_prompt">
							<?php esc_html_e( 'Custom System Prompt', 'whatsapp-commerce-hub' ); ?>
						</label>
					</th>
					<td>
						<textarea name="ai[system_prompt]" id="system_prompt"
							rows="8" class="large-text"><?php echo esc_textarea( $systemPrompt ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Custom instructions for the AI assistant behavior', 'whatsapp-commerce-hub' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render Advanced tab.
	 *
	 * @param SettingsInterface $settings Settings service.
	 * @return void
	 */
	protected function renderAdvancedTab( SettingsInterface $settings ): void {
		$debugMode    = $settings->get( 'advanced.debug_mode', false );
		$logRetention = $settings->get( 'advanced.log_retention_days', 30 );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Debug Mode', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="advanced[debug_mode]" value="1" <?php checked( $debugMode, true ); ?>>
							<?php esc_html_e( 'Enable debug logging', 'whatsapp-commerce-hub' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Log detailed information for troubleshooting', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="log_retention_days"><?php esc_html_e( 'Log Retention', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="number" name="advanced[log_retention_days]" id="log_retention_days"
								value="<?php echo esc_attr( $logRetention ); ?>" min="1" max="365" class="small-text">
						<?php esc_html_e( 'days', 'whatsapp-commerce-hub' ); ?>
						<p class="description"><?php esc_html_e( 'Number of days to keep log files', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Logs', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<button type="button" class="button" id="clear-logs">
							<?php esc_html_e( 'Clear All Logs', 'whatsapp-commerce-hub' ); ?>
						</button>
						<span class="spinner"></span>
						<p class="description"><?php esc_html_e( 'Remove all log files', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Export Settings', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<button type="button" class="button" id="export-settings">
							<?php esc_html_e( 'Export Settings', 'whatsapp-commerce-hub' ); ?>
						</button>
						<p class="description"><?php esc_html_e( 'Download settings as JSON file', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="import-settings-file"><?php esc_html_e( 'Import Settings', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="file" id="import-settings-file" accept=".json">
						<button type="button" class="button" id="import-settings">
							<?php esc_html_e( 'Import Settings', 'whatsapp-commerce-hub' ); ?>
						</button>
						<span class="spinner"></span>
						<p class="description"><?php esc_html_e( 'Upload and restore settings from JSON file', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Reset Settings', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<button type="button" class="button button-secondary" id="reset-settings">
							<?php esc_html_e( 'Reset to Defaults', 'whatsapp-commerce-hub' ); ?>
						</button>
						<span class="spinner"></span>
						<p class="description"><?php esc_html_e( 'Reset all settings to default values', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render category select.
	 *
	 * @param array $selectedCategories Selected category IDs.
	 * @return void
	 */
	protected function renderCategorySelect( array $selectedCategories ): void {
		$productCategories = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			]
		);

		if ( empty( $productCategories ) || is_wp_error( $productCategories ) ) {
			return;
		}

		echo '<select name="catalog[categories][]" id="catalog_categories" multiple class="wch-multiselect" style="width: 400px; height: 150px;">';
		foreach ( $productCategories as $category ) {
			$is_selected = in_array( $category->term_id, $selectedCategories, true );
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $category->term_id ),
				$is_selected ? ' selected' : '',
				esc_html( $category->name )
			);
		}
		echo '</select>';
	}

	/**
	 * Render product select.
	 *
	 * @param array $selectedProducts Selected product IDs.
	 * @return void
	 */
	protected function renderProductSelect( array $selectedProducts ): void {
		?>
		<input type="text" id="catalog_products_search"
				placeholder="<?php esc_attr_e( 'Search products...', 'whatsapp-commerce-hub' ); ?>" class="regular-text">
		<div id="catalog_products_list" class="wch-product-list">
			<?php
			if ( ! empty( $selectedProducts ) ) {
				foreach ( $selectedProducts as $productId ) {
					$product = wc_get_product( $productId );
					if ( $product ) {
						echo '<div class="wch-product-item" data-id="' . esc_attr( $productId ) . '">';
						echo esc_html( $product->get_name() );
						echo '<input type="hidden" name="catalog[products][]" value="' . esc_attr( $productId ) . '">';
						echo '<button type="button" class="button-link-delete wch-remove-product">&times;</button>';
						echo '</div>';
					}
				}
			}
			?>
		</div>
		<?php
	}

	/**
	 * Get available payment gateways.
	 *
	 * @return array<string, string> Gateway ID => Title.
	 */
	protected function getAvailablePaymentGateways(): array {
		$gateways = [];

		if ( class_exists( 'WCH_Payment_Manager' ) ) {
			$paymentManager    = \WCH_Payment_Manager::getInstance();
			$availableGateways = $paymentManager->get_available_gateways();

			foreach ( $availableGateways as $gatewayId => $gateway ) {
				$gateways[ $gatewayId ] = $gateway->get_title();
			}
		}

		return $gateways;
	}
}
