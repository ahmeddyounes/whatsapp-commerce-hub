<?php
/**
 * Broadcast Wizard Renderer
 *
 * Handles rendering of the campaign wizard UI.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Admin\Broadcasts;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BroadcastWizardRenderer
 *
 * Renders the multi-step campaign wizard.
 */
class BroadcastWizardRenderer {

	/**
	 * Render the campaign wizard.
	 *
	 * @param int $campaignId Campaign ID for editing (0 for new).
	 * @return void
	 */
	public function render( int $campaignId = 0 ): void {
		?>
		<div class="wch-campaign-wizard" data-campaign-id="<?php echo esc_attr( $campaignId ); ?>">
			<?php $this->renderStepIndicators(); ?>

			<div class="wch-wizard-content">
				<?php
				$this->renderTemplateStep();
				$this->renderAudienceStep();
				$this->renderPersonalizationStep();
				$this->renderScheduleStep();
				$this->renderReviewStep();
				?>
			</div>

			<?php $this->renderNavigation(); ?>
		</div>
		<?php
	}

	/**
	 * Render step indicators.
	 *
	 * @return void
	 */
	protected function renderStepIndicators(): void {
		$steps = array(
			1 => __( 'Template', 'whatsapp-commerce-hub' ),
			2 => __( 'Audience', 'whatsapp-commerce-hub' ),
			3 => __( 'Personalize', 'whatsapp-commerce-hub' ),
			4 => __( 'Schedule', 'whatsapp-commerce-hub' ),
			5 => __( 'Review', 'whatsapp-commerce-hub' ),
		);
		?>
		<div class="wch-wizard-steps">
			<?php foreach ( $steps as $num => $label ) : ?>
				<div class="wch-step<?php echo 1 === $num ? ' active' : ''; ?>" data-step="<?php echo esc_attr( $num ); ?>">
					<span class="wch-step-number"><?php echo esc_html( $num ); ?></span>
					<span class="wch-step-label"><?php echo esc_html( $label ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render template selection step.
	 *
	 * @return void
	 */
	protected function renderTemplateStep(): void {
		?>
		<div class="wch-wizard-panel" data-panel="1">
			<h2><?php esc_html_e( 'Select Template', 'whatsapp-commerce-hub' ); ?></h2>
			<div class="wch-template-selection">
				<div class="wch-templates-list" id="wch-templates-list">
					<p class="wch-loading"><?php esc_html_e( 'Loading templates...', 'whatsapp-commerce-hub' ); ?></p>
				</div>
				<div class="wch-template-preview" id="wch-template-preview">
					<h3><?php esc_html_e( 'Template Preview', 'whatsapp-commerce-hub' ); ?></h3>
					<div class="wch-preview-content">
						<p class="wch-placeholder"><?php esc_html_e( 'Select a template to preview', 'whatsapp-commerce-hub' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render audience selection step.
	 *
	 * @return void
	 */
	protected function renderAudienceStep(): void {
		?>
		<div class="wch-wizard-panel" data-panel="2" style="display:none;">
			<h2><?php esc_html_e( 'Select Audience', 'whatsapp-commerce-hub' ); ?></h2>
			<div class="wch-audience-builder">
				<div class="wch-audience-criteria">
					<?php $this->renderAudienceCriteria(); ?>
					<?php $this->renderAudienceExclusions(); ?>
				</div>
				<div class="wch-audience-count">
					<div class="wch-count-box">
						<div class="wch-count-number" id="wch-audience-count">-</div>
						<div class="wch-count-label"><?php esc_html_e( 'Estimated Recipients', 'whatsapp-commerce-hub' ); ?></div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render audience criteria fields.
	 *
	 * @return void
	 */
	protected function renderAudienceCriteria(): void {
		?>
		<div class="wch-form-field">
			<label>
				<input type="checkbox" name="audience_all" value="1" checked>
				<?php esc_html_e( 'All opted-in customers', 'whatsapp-commerce-hub' ); ?>
			</label>
		</div>
		<div class="wch-form-field">
			<label>
				<input type="checkbox" name="audience_recent_orders" value="1">
				<?php esc_html_e( 'Ordered in last', 'whatsapp-commerce-hub' ); ?>
				<input type="number" name="recent_orders_days" value="30" min="1" max="365" style="width: 80px;">
				<?php esc_html_e( 'days', 'whatsapp-commerce-hub' ); ?>
			</label>
		</div>
		<div class="wch-form-field">
			<label>
				<input type="checkbox" name="audience_category" value="1">
				<?php esc_html_e( 'Purchased from category:', 'whatsapp-commerce-hub' ); ?>
				<select name="category_id" style="width: 200px;">
					<option value=""><?php esc_html_e( 'Select category', 'whatsapp-commerce-hub' ); ?></option>
					<?php $this->renderCategoryOptions(); ?>
				</select>
			</label>
		</div>
		<div class="wch-form-field">
			<label>
				<input type="checkbox" name="audience_cart_abandoners" value="1">
				<?php esc_html_e( 'Cart abandoners (last 7 days)', 'whatsapp-commerce-hub' ); ?>
			</label>
		</div>
		<?php
	}

	/**
	 * Render category options.
	 *
	 * @return void
	 */
	protected function renderCategoryOptions(): void {
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $categories ) ) {
			return;
		}

		foreach ( $categories as $category ) {
			printf(
				'<option value="%s">%s</option>',
				esc_attr( $category->term_id ),
				esc_html( $category->name )
			);
		}
	}

	/**
	 * Render audience exclusion options.
	 *
	 * @return void
	 */
	protected function renderAudienceExclusions(): void {
		?>
		<h3><?php esc_html_e( 'Exclusions', 'whatsapp-commerce-hub' ); ?></h3>
		<div class="wch-form-field">
			<label>
				<input type="checkbox" name="exclude_recent_broadcast" value="1">
				<?php esc_html_e( 'Exclude customers who received a broadcast in last', 'whatsapp-commerce-hub' ); ?>
				<input type="number" name="exclude_broadcast_days" value="7" min="1" max="30" style="width: 80px;">
				<?php esc_html_e( 'days', 'whatsapp-commerce-hub' ); ?>
			</label>
		</div>
		<?php
	}

	/**
	 * Render personalization step.
	 *
	 * @return void
	 */
	protected function renderPersonalizationStep(): void {
		?>
		<div class="wch-wizard-panel" data-panel="3" style="display:none;">
			<h2><?php esc_html_e( 'Personalize Message', 'whatsapp-commerce-hub' ); ?></h2>
			<div class="wch-personalization">
				<div class="wch-variable-mapping" id="wch-variable-mapping">
					<p class="wch-placeholder"><?php esc_html_e( 'Template variables will appear here', 'whatsapp-commerce-hub' ); ?></p>
				</div>
				<div class="wch-personalization-preview">
					<h3><?php esc_html_e( 'Preview with Sample Data', 'whatsapp-commerce-hub' ); ?></h3>
					<div class="wch-preview-content" id="wch-personalization-preview">
						<p class="wch-placeholder"><?php esc_html_e( 'Preview will appear here', 'whatsapp-commerce-hub' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render schedule step.
	 *
	 * @return void
	 */
	protected function renderScheduleStep(): void {
		?>
		<div class="wch-wizard-panel" data-panel="4" style="display:none;">
			<h2><?php esc_html_e( 'Schedule Campaign', 'whatsapp-commerce-hub' ); ?></h2>
			<div class="wch-schedule-options">
				<div class="wch-form-field">
					<label>
						<input type="radio" name="send_timing" value="now" checked>
						<?php esc_html_e( 'Send Now', 'whatsapp-commerce-hub' ); ?>
					</label>
				</div>
				<div class="wch-form-field">
					<label>
						<input type="radio" name="send_timing" value="scheduled">
						<?php esc_html_e( 'Schedule for later', 'whatsapp-commerce-hub' ); ?>
					</label>
					<?php $this->renderScheduleDatetime(); ?>
				</div>
				<div class="wch-optimal-time-suggestion">
					<p class="description">
						<span class="dashicons dashicons-lightbulb"></span>
						<?php esc_html_e( 'Suggested optimal send time: 10:00 AM based on historical open rates', 'whatsapp-commerce-hub' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render schedule datetime fields.
	 *
	 * @return void
	 */
	protected function renderScheduleDatetime(): void {
		$tzstring   = get_option( 'timezone_string' );
		$selectedTz = $tzstring ? $tzstring : 'UTC';

		$timezones = array(
			'UTC'                 => 'UTC',
			'America/New_York'    => 'Eastern Time',
			'America/Chicago'     => 'Central Time',
			'America/Denver'      => 'Mountain Time',
			'America/Los_Angeles' => 'Pacific Time',
			'Europe/London'       => 'London',
			'Europe/Paris'        => 'Paris',
			'Asia/Dubai'          => 'Dubai',
			'Asia/Kolkata'        => 'India',
			'Asia/Singapore'      => 'Singapore',
			'Asia/Tokyo'          => 'Tokyo',
			'Australia/Sydney'    => 'Sydney',
		);
		?>
		<div class="wch-schedule-datetime" style="margin-left: 30px; display: none;">
			<label>
				<?php esc_html_e( 'Date:', 'whatsapp-commerce-hub' ); ?>
				<input type="date" name="schedule_date" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
			</label>
			<label>
				<?php esc_html_e( 'Time:', 'whatsapp-commerce-hub' ); ?>
				<input type="time" name="schedule_time">
			</label>
			<label>
				<?php esc_html_e( 'Timezone:', 'whatsapp-commerce-hub' ); ?>
				<select name="schedule_timezone">
					<?php foreach ( $timezones as $tz => $label ) : ?>
						<option value="<?php echo esc_attr( $tz ); ?>" <?php selected( $selectedTz, $tz ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
		</div>
		<?php
	}

	/**
	 * Render review step.
	 *
	 * @return void
	 */
	protected function renderReviewStep(): void {
		?>
		<div class="wch-wizard-panel" data-panel="5" style="display:none;">
			<h2><?php esc_html_e( 'Review & Send', 'whatsapp-commerce-hub' ); ?></h2>
			<div class="wch-campaign-review">
				<div class="wch-review-section">
					<h3><?php esc_html_e( 'Campaign Name', 'whatsapp-commerce-hub' ); ?></h3>
					<div class="wch-form-field">
						<input type="text" name="campaign_name" placeholder="<?php esc_attr_e( 'Enter campaign name', 'whatsapp-commerce-hub' ); ?>" style="width: 100%; max-width: 500px;">
					</div>
				</div>

				<div class="wch-review-section">
					<h3><?php esc_html_e( 'Campaign Summary', 'whatsapp-commerce-hub' ); ?></h3>
					<table class="wch-review-table">
						<tr>
							<th><?php esc_html_e( 'Template:', 'whatsapp-commerce-hub' ); ?></th>
							<td id="review-template">-</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Audience:', 'whatsapp-commerce-hub' ); ?></th>
							<td id="review-audience">-</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Schedule:', 'whatsapp-commerce-hub' ); ?></th>
							<td id="review-schedule">-</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Estimated Cost:', 'whatsapp-commerce-hub' ); ?></th>
							<td id="review-cost">-</td>
						</tr>
					</table>
				</div>

				<div class="wch-review-section">
					<h3><?php esc_html_e( 'Message Preview', 'whatsapp-commerce-hub' ); ?></h3>
					<div class="wch-message-preview" id="review-message-preview">
						<p class="wch-placeholder"><?php esc_html_e( 'Message preview will appear here', 'whatsapp-commerce-hub' ); ?></p>
					</div>
				</div>

				<div class="wch-review-actions">
					<button type="button" class="button" id="wch-send-test">
						<?php esc_html_e( 'Send Test Message', 'whatsapp-commerce-hub' ); ?>
					</button>
					<button type="button" class="button button-primary button-large" id="wch-confirm-send">
						<?php esc_html_e( 'Confirm & Schedule Campaign', 'whatsapp-commerce-hub' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render wizard navigation.
	 *
	 * @return void
	 */
	protected function renderNavigation(): void {
		?>
		<div class="wch-wizard-navigation">
			<button type="button" class="button button-secondary" id="wch-wizard-prev" style="display:none;">
				<?php esc_html_e( 'Previous', 'whatsapp-commerce-hub' ); ?>
			</button>
			<button type="button" class="button button-primary" id="wch-wizard-next">
				<?php esc_html_e( 'Next', 'whatsapp-commerce-hub' ); ?>
			</button>
		</div>
		<?php
	}
}
