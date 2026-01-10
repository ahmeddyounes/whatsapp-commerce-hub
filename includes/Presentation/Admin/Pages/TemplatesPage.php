<?php
/**
 * Admin Templates Page
 *
 * Provides admin interface for managing WhatsApp message templates.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Presentation\Admin\Pages;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplatesPage
 *
 * Handles the admin interface for viewing and managing message templates.
 */
class TemplatesPage {

	/**
	 * Initialize the admin templates page.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'addMenuItem' ), 55 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueScripts' ) );
		add_action( 'admin_post_wch_sync_templates', array( $this, 'handleSyncTemplates' ) );
		add_action( 'wp_ajax_wch_preview_template', array( $this, 'ajaxPreviewTemplate' ) );
	}

	/**
	 * Add menu item under WooCommerce.
	 *
	 * @return void
	 */
	public function addMenuItem(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Message Templates', 'whatsapp-commerce-hub' ),
			__( 'WCH Templates', 'whatsapp-commerce-hub' ),
			'manage_woocommerce',
			'wch-templates',
			array( $this, 'renderPage' )
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueScripts( string $hook ): void {
		if ( 'woocommerce_page_wch-templates' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wch-admin-templates',
			WCH_PLUGIN_URL . 'assets/css/admin-templates.css',
			array(),
			WCH_VERSION
		);

		wp_enqueue_script(
			'wch-admin-templates',
			WCH_PLUGIN_URL . 'assets/js/admin-templates.js',
			array( 'jquery' ),
			WCH_VERSION,
			true
		);

		wp_localize_script(
			'wch-admin-templates',
			'wchTemplates',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wch_templates_nonce' ),
			)
		);
	}

	/**
	 * Handle template sync action.
	 *
	 * @return void
	 */
	public function handleSyncTemplates(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wch_sync_templates' ) ) {
			wp_die( esc_html__( 'Invalid nonce', 'whatsapp-commerce-hub' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied', 'whatsapp-commerce-hub' ) );
		}

		try {
			$templateManager = \WCH_Template_Manager::getInstance();
			$templates       = $templateManager->sync_templates();

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'   => 'wch-templates',
						'synced' => count( $templates ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		} catch ( \Exception $e ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => 'wch-templates',
						'error' => rawurlencode( $e->getMessage() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * AJAX handler for template preview.
	 *
	 * @return void
	 */
	public function ajaxPreviewTemplate(): void {
		check_ajax_referer( 'wch_templates_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$templateName = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( $_POST['template_name'] ) ) : '';
		$variables    = isset( $_POST['variables'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['variables'] ) ) : array();

		if ( empty( $templateName ) ) {
			wp_send_json_error( array( 'message' => 'Template name required' ) );
		}

		try {
			$templateManager = \WCH_Template_Manager::getInstance();
			$rendered        = $templateManager->render_template( $templateName, $variables );

			wp_send_json_success( array( 'template' => $rendered ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Render the templates page.
	 *
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'whatsapp-commerce-hub' ) );
		}

		$templateManager = \WCH_Template_Manager::getInstance();
		$templates       = $templateManager->get_templates();
		$lastSync        = $templateManager->get_last_sync_time();
		$usageStats      = $templateManager->get_all_usage_stats();

		$statsByName         = $this->buildStatsIndex( $usageStats );
		$templatesByCategory = $this->groupTemplatesByCategory( $templates );

		$this->renderPageHtml( $templates, $templatesByCategory, $lastSync, $statsByName );
	}

	/**
	 * Build stats index by template name.
	 *
	 * @param array $usageStats Usage statistics.
	 * @return array
	 */
	private function buildStatsIndex( array $usageStats ): array {
		$statsByName = array();
		foreach ( $usageStats as $stat ) {
			$statsByName[ $stat['template_name'] ] = $stat;
		}
		return $statsByName;
	}

	/**
	 * Group templates by category.
	 *
	 * @param array $templates Templates.
	 * @return array
	 */
	private function groupTemplatesByCategory( array $templates ): array {
		$templatesByCategory = array();
		foreach ( $templates as $template ) {
			$category = $template['mapped_category'] ?? $template['category'] ?? 'other';
			if ( ! isset( $templatesByCategory[ $category ] ) ) {
				$templatesByCategory[ $category ] = array();
			}
			$templatesByCategory[ $category ][] = $template;
		}
		return $templatesByCategory;
	}

	/**
	 * Render the page HTML.
	 *
	 * @param array    $templates           All templates.
	 * @param array    $templatesByCategory Templates grouped by category.
	 * @param int|null $lastSync            Last sync timestamp.
	 * @param array    $statsByName         Usage stats indexed by template name.
	 * @return void
	 */
	private function renderPageHtml( array $templates, array $templatesByCategory, ?int $lastSync, array $statsByName ): void {
		?>
		<div class="wrap wch-templates-page">
			<h1><?php esc_html_e( 'WhatsApp Message Templates', 'whatsapp-commerce-hub' ); ?></h1>

			<?php $this->renderNotices(); ?>

			<div class="wch-templates-header">
				<div class="wch-sync-info">
					<?php if ( $lastSync ) : ?>
						<p>
							<?php
							printf(
								/* translators: %s: time ago */
								esc_html__( 'Last synced: %s', 'whatsapp-commerce-hub' ),
								esc_html( human_time_diff( $lastSync, time() ) . ' ago' )
							);
							?>
						</p>
					<?php else : ?>
						<p><?php esc_html_e( 'Templates not yet synced', 'whatsapp-commerce-hub' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="wch-sync-action">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wch_sync_templates' ); ?>
						<input type="hidden" name="action" value="wch_sync_templates">
						<button type="submit" class="button button-primary">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Sync Templates', 'whatsapp-commerce-hub' ); ?>
						</button>
					</form>
				</div>
			</div>

			<?php if ( empty( $templates ) ) : ?>
				<div class="wch-no-templates">
					<p><?php esc_html_e( 'No templates found. Click "Sync Templates" to fetch templates from WhatsApp.', 'whatsapp-commerce-hub' ); ?></p>
				</div>
			<?php else : ?>
				<div class="wch-templates-summary">
					<p>
						<?php
						printf(
							/* translators: %d: number of templates */
							esc_html( _n( '%d template available', '%d templates available', count( $templates ), 'whatsapp-commerce-hub' ) ),
							count( $templates )
						);
						?>
					</p>
				</div>

				<?php foreach ( $templatesByCategory as $category => $categoryTemplates ) : ?>
					<?php $this->renderTemplateCategory( $category, $categoryTemplates, $statsByName ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a template category.
	 *
	 * @param string $category          Category name.
	 * @param array  $categoryTemplates Templates in this category.
	 * @param array  $statsByName       Usage stats indexed by template name.
	 * @return void
	 */
	private function renderTemplateCategory( string $category, array $categoryTemplates, array $statsByName ): void {
		?>
		<div class="wch-template-category">
			<h2><?php echo esc_html( ucwords( str_replace( '_', ' ', $category ) ) ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Language', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Status', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Usage', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Preview', 'whatsapp-commerce-hub' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $categoryTemplates as $template ) : ?>
						<?php $this->renderTemplateRow( $template, $statsByName ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render a template row.
	 *
	 * @param array $template    Template data.
	 * @param array $statsByName Usage stats indexed by template name.
	 * @return void
	 */
	private function renderTemplateRow( array $template, array $statsByName ): void {
		$templateStats = $statsByName[ $template['name'] ] ?? null;
		$usageCount    = $templateStats ? $templateStats['usage_count'] : 0;
		$status        = strtolower( $template['status'] );
		$statusClass   = 'wch-status-' . $status;
		?>
		<tr>
			<td>
				<strong><?php echo esc_html( $template['name'] ); ?></strong>
			</td>
			<td>
				<?php echo esc_html( $template['language'] ); ?>
			</td>
			<td>
				<span class="wch-template-status <?php echo esc_attr( $statusClass ); ?>">
					<?php echo esc_html( ucfirst( $status ) ); ?>
				</span>
			</td>
			<td>
				<?php
				if ( $usageCount > 0 ) {
					printf(
						/* translators: %d: usage count */
						esc_html( _n( '%d time', '%d times', $usageCount, 'whatsapp-commerce-hub' ) ),
						$usageCount
					);
				} else {
					esc_html_e( 'Not used', 'whatsapp-commerce-hub' );
				}
				?>
			</td>
			<td>
				<button
					type="button"
					class="button wch-preview-template"
					data-template="<?php echo esc_attr( $template['name'] ); ?>"
				>
					<?php esc_html_e( 'Preview', 'whatsapp-commerce-hub' ); ?>
				</button>
			</td>
		</tr>
		<tr class="wch-template-preview-row" id="preview-<?php echo esc_attr( $template['name'] ); ?>" style="display: none;">
			<td colspan="5">
				<div class="wch-template-preview">
					<?php $this->renderTemplatePreview( $template ); ?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render template preview.
	 *
	 * @param array $template Template data.
	 * @return void
	 */
	private function renderTemplatePreview( array $template ): void {
		?>
		<div class="wch-template-content">
			<?php if ( ! empty( $template['components'] ) ) : ?>
				<?php foreach ( $template['components'] as $component ) : ?>
					<?php $this->renderTemplateComponent( $component ); ?>
				<?php endforeach; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'No preview available', 'whatsapp-commerce-hub' ); ?></p>
			<?php endif; ?>

			<?php
			$variables = $this->extractVariablesFromTemplate( $template );
			if ( ! empty( $variables ) ) :
				?>
				<div class="wch-preview-variables">
					<p><strong><?php esc_html_e( 'Variables:', 'whatsapp-commerce-hub' ); ?></strong></p>
					<ul>
						<?php foreach ( $variables as $var ) : ?>
							<li><code><?php echo esc_html( $var ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a template component.
	 *
	 * @param array $component Component data.
	 * @return void
	 */
	private function renderTemplateComponent( array $component ): void {
		$type = $component['type'] ?? '';
		$text = $component['text'] ?? '';

		if ( 'HEADER' === $type ) {
			?>
			<div class="wch-preview-header">
				<strong><?php echo esc_html( $text ); ?></strong>
			</div>
			<?php
		} elseif ( 'BODY' === $type ) {
			?>
			<div class="wch-preview-body">
				<?php echo nl2br( esc_html( $text ) ); ?>
			</div>
			<?php
		} elseif ( 'FOOTER' === $type ) {
			?>
			<div class="wch-preview-footer">
				<small><?php echo esc_html( $text ); ?></small>
			</div>
			<?php
		} elseif ( 'BUTTONS' === $type && ! empty( $component['buttons'] ) ) {
			?>
			<div class="wch-preview-buttons">
				<?php foreach ( $component['buttons'] as $button ) : ?>
					<button type="button" class="wch-preview-button" disabled>
						<?php echo esc_html( $button['text'] ?? '' ); ?>
					</button>
				<?php endforeach; ?>
			</div>
			<?php
		}
	}

	/**
	 * Extract variables from template.
	 *
	 * @param array $template Template data.
	 * @return array List of variables found.
	 */
	private function extractVariablesFromTemplate( array $template ): array {
		$variables = array();

		if ( empty( $template['components'] ) ) {
			return $variables;
		}

		foreach ( $template['components'] as $component ) {
			$text = $component['text'] ?? '';
			preg_match_all( '/\{\{(\d+)\}\}/', $text, $matches );

			if ( ! empty( $matches[0] ) ) {
				$variables = array_merge( $variables, $matches[0] );
			}
		}

		return array_unique( $variables );
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function renderNotices(): void {
		if ( isset( $_GET['synced'] ) ) {
			$count = intval( $_GET['synced'] );
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %d: number of templates */
						esc_html( _n( '%d template synced successfully.', '%d templates synced successfully.', $count, 'whatsapp-commerce-hub' ) ),
						$count
					);
					?>
				</p>
			</div>
			<?php
		}

		if ( isset( $_GET['error'] ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %s: error message */
						esc_html__( 'Error: %s', 'whatsapp-commerce-hub' ),
						esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) )
					);
					?>
				</p>
			</div>
			<?php
		}
	}
}
