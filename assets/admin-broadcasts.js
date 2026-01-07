/**
 * Broadcasts Admin JavaScript
 */

(function($) {
	'use strict';

	const BroadcastsAdmin = {
		currentStep: 1,
		totalSteps: 5,
		campaignData: {
			id: 0,
			name: '',
			template_name: '',
			template_data: {},
			audience: {},
			audience_size: 0,
			personalization: {},
			schedule: {},
		},

		init: function() {
			this.bindEvents();
			this.loadTemplates();
			this.updateAudienceCount();
		},

		bindEvents: function() {
			// Wizard navigation
			$('#wch-wizard-next').on('click', () => this.nextStep());
			$('#wch-wizard-prev').on('click', () => this.prevStep());

			// Template selection
			$(document).on('click', '.wch-template-item', (e) => this.selectTemplate(e));

			// Audience criteria changes
			$('.wch-audience-criteria input, .wch-audience-criteria select').on('change', () => this.updateAudienceCount());

			// Schedule timing change
			$('input[name="send_timing"]').on('change', (e) => this.toggleScheduleDateTime(e));

			// Campaign actions
			$('#wch-send-test').on('click', () => this.sendTestBroadcast());
			$('#wch-confirm-send').on('click', () => this.confirmSendCampaign());

			// List actions
			$(document).on('click', '.wch-delete-campaign', (e) => this.deleteCampaign(e));
			$(document).on('click', '.wch-duplicate-campaign', (e) => this.duplicateCampaign(e));

			// Export report
			$('#wch-export-report').on('click', (e) => this.exportReport(e));

			// Step navigation
			$('.wch-step').on('click', (e) => {
				const stepNum = parseInt($(e.currentTarget).data('step'));
				if (stepNum < this.currentStep) {
					this.goToStep(stepNum);
				}
			});
		},

		loadTemplates: function() {
			if (!$('#wch-templates-list').length) {
				return;
			}

			$('#wch-templates-list').html('<p class="wch-loading">Loading templates...</p>');

			$.ajax({
				url: wchBroadcasts.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wch_get_approved_templates',
					nonce: wchBroadcasts.nonce,
				},
				success: (response) => {
					if (response.success && response.data.templates) {
						this.renderTemplates(response.data.templates);
					} else {
						$('#wch-templates-list').html('<p>No approved templates found.</p>');
					}
				},
				error: () => {
					$('#wch-templates-list').html('<p>Error loading templates.</p>');
				},
			});
		},

		renderTemplates: function(templates) {
			const $list = $('#wch-templates-list');
			$list.empty();

			if (templates.length === 0) {
				$list.html('<p>No approved templates available.</p>');
				return;
			}

			templates.forEach((template) => {
				const isApproved = template.status === 'APPROVED';
				const statusClass = isApproved ? '' : 'unapproved';
				const statusText = isApproved ? 'Approved' : template.status || 'Unknown';

				const $item = $(`
					<div class="wch-template-item" data-template-name="${template.name}">
						<h4>${template.name || 'Unnamed Template'}</h4>
						<div>
							<span class="wch-template-category">${template.category || 'general'}</span>
							<span class="wch-template-status ${statusClass}">${statusText}</span>
						</div>
					</div>
				`);

				$item.data('template', template);
				$list.append($item);
			});
		},

		selectTemplate: function(e) {
			const $item = $(e.currentTarget);
			const template = $item.data('template');

			$('.wch-template-item').removeClass('selected');
			$item.addClass('selected');

			this.campaignData.template_name = template.name;
			this.campaignData.template_data = template;

			this.renderTemplatePreview(template);
			this.extractTemplateVariables(template);
		},

		renderTemplatePreview: function(template) {
			const $preview = $('#wch-template-preview .wch-preview-content');
			$preview.empty();

			// Check if template is approved
			if (template.status !== 'APPROVED') {
				$preview.append(`
					<div class="wch-template-warning">
						<p><strong>Warning:</strong> This template is not approved and cannot be used for broadcasts.</p>
					</div>
				`);
			}

			// Render template components
			if (template.components && Array.isArray(template.components)) {
				template.components.forEach((component) => {
					if (component.type === 'HEADER') {
						$preview.append(`<div style="font-weight: bold; margin-bottom: 8px;">${this.highlightVariables(component.text || '')}</div>`);
					} else if (component.type === 'BODY') {
						$preview.append(`<div style="margin-bottom: 8px;">${this.highlightVariables(component.text || '')}</div>`);
					} else if (component.type === 'FOOTER') {
						$preview.append(`<div style="font-size: 12px; color: #999; margin-top: 8px;">${component.text || ''}</div>`);
					}
				});
			} else {
				$preview.append('<p class="wch-placeholder">Preview not available</p>');
			}
		},

		highlightVariables: function(text) {
			// Highlight {{1}}, {{2}}, etc.
			return text.replace(/\{\{(\d+)\}\}/g, '<span style="background: #fef7e5; padding: 2px 6px; border-radius: 3px; font-weight: 600;">{{$1}}</span>');
		},

		extractTemplateVariables: function(template) {
			const variables = [];

			if (template.components && Array.isArray(template.components)) {
				template.components.forEach((component) => {
					if (component.type === 'BODY' && component.text) {
						const matches = component.text.match(/\{\{(\d+)\}\}/g);
						if (matches) {
							matches.forEach((match) => {
								const num = match.match(/\d+/)[0];
								if (!variables.includes(num)) {
									variables.push(num);
								}
							});
						}
					}
				});
			}

			this.campaignData.template_variables = variables.sort();
		},

		updateAudienceCount: function() {
			if (!$('#wch-audience-count').length) {
				return;
			}

			const criteria = {
				audience_all: $('input[name="audience_all"]').is(':checked'),
				audience_recent_orders: $('input[name="audience_recent_orders"]').is(':checked'),
				recent_orders_days: $('input[name="recent_orders_days"]').val(),
				audience_category: $('input[name="audience_category"]').is(':checked'),
				category_id: $('select[name="category_id"]').val(),
				audience_cart_abandoners: $('input[name="audience_cart_abandoners"]').is(':checked'),
				exclude_recent_broadcast: $('input[name="exclude_recent_broadcast"]').is(':checked'),
				exclude_broadcast_days: $('input[name="exclude_broadcast_days"]').val(),
			};

			this.campaignData.audience = criteria;

			$('#wch-audience-count').html('<span class="wch-loading"></span>');

			$.ajax({
				url: wchBroadcasts.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wch_get_audience_count',
					nonce: wchBroadcasts.nonce,
					criteria: JSON.stringify(criteria),
				},
				success: (response) => {
					if (response.success) {
						const count = response.data.count || 0;
						this.campaignData.audience_size = count;
						$('#wch-audience-count').text(count.toLocaleString());
					} else {
						$('#wch-audience-count').text('Error');
					}
				},
				error: () => {
					$('#wch-audience-count').text('Error');
				},
			});
		},

		toggleScheduleDateTime: function(e) {
			const timing = $(e.target).val();
			if (timing === 'scheduled') {
				$('.wch-schedule-datetime').slideDown();
			} else {
				$('.wch-schedule-datetime').slideUp();
			}
		},

		nextStep: function() {
			if (!this.validateCurrentStep()) {
				return;
			}

			if (this.currentStep < this.totalSteps) {
				this.currentStep++;
				this.updateWizardUI();

				if (this.currentStep === 3) {
					this.renderPersonalizationStep();
				} else if (this.currentStep === 5) {
					this.renderReviewStep();
				}
			}
		},

		prevStep: function() {
			if (this.currentStep > 1) {
				this.currentStep--;
				this.updateWizardUI();
			}
		},

		goToStep: function(stepNum) {
			if (stepNum >= 1 && stepNum <= this.currentStep) {
				this.currentStep = stepNum;
				this.updateWizardUI();
			}
		},

		updateWizardUI: function() {
			// Update step indicators
			$('.wch-step').removeClass('active').each(function(index) {
				const stepNum = index + 1;
				if (stepNum < BroadcastsAdmin.currentStep) {
					$(this).addClass('completed');
				} else if (stepNum === BroadcastsAdmin.currentStep) {
					$(this).addClass('active');
				} else {
					$(this).removeClass('completed');
				}
			});

			// Show/hide panels
			$('.wch-wizard-panel').hide();
			$(`.wch-wizard-panel[data-panel="${this.currentStep}"]`).show();

			// Update navigation buttons
			if (this.currentStep === 1) {
				$('#wch-wizard-prev').hide();
			} else {
				$('#wch-wizard-prev').show();
			}

			if (this.currentStep === this.totalSteps) {
				$('#wch-wizard-next').hide();
			} else {
				$('#wch-wizard-next').show();
			}

			// Scroll to top
			$('.wch-campaign-wizard').get(0).scrollIntoView({ behavior: 'smooth' });
		},

		validateCurrentStep: function() {
			switch (this.currentStep) {
				case 1:
					if (!this.campaignData.template_name) {
						alert('Please select a template');
						return false;
					}
					if (this.campaignData.template_data.status !== 'APPROVED') {
						alert('Selected template is not approved');
						return false;
					}
					break;
				case 2:
					if (this.campaignData.audience_size === 0) {
						alert('No recipients match the selected criteria');
						return false;
					}
					break;
				case 3:
					// Personalization validation if needed
					break;
				case 4:
					// Schedule validation
					const timing = $('input[name="send_timing"]:checked').val();
					if (timing === 'scheduled') {
						const date = $('input[name="schedule_date"]').val();
						const time = $('input[name="schedule_time"]').val();
						if (!date || !time) {
							alert('Please select date and time for scheduled send');
							return false;
						}
					}
					break;
			}
			return true;
		},

		renderPersonalizationStep: function() {
			const $mapping = $('#wch-variable-mapping');
			$mapping.empty();

			const variables = this.campaignData.template_variables || [];

			if (variables.length === 0) {
				$mapping.html('<p class="wch-placeholder">This template has no variables to personalize</p>');
				return;
			}

			variables.forEach((varNum) => {
				const $row = $(`
					<div class="wch-variable-row">
						<div class="wch-variable-label">Variable {{${varNum}}}</div>
						<div class="wch-variable-input">
							<select name="var_${varNum}_type" data-var="${varNum}">
								<option value="customer_name">Customer Name</option>
								<option value="static">Static Text</option>
								<option value="product_name">Product Name</option>
								<option value="coupon_code">Coupon Code</option>
							</select>
							<input type="text" name="var_${varNum}_value" data-var="${varNum}" placeholder="Enter value" style="margin-top: 8px; display: none;">
						</div>
					</div>
				`);

				$row.find('select').on('change', function() {
					const type = $(this).val();
					const $input = $row.find('input[type="text"]');
					if (type === 'static' || type === 'coupon_code' || type === 'product_name') {
						$input.show();
					} else {
						$input.hide();
					}
				});

				$mapping.append($row);
			});

			this.updatePersonalizationPreview();
		},

		updatePersonalizationPreview: function() {
			const $preview = $('#wch-personalization-preview');
			$preview.empty();

			// Get sample values for preview
			const sampleData = {
				customer_name: 'John Doe',
				product_name: 'Sample Product',
				coupon_code: 'SAVE20',
			};

			let previewText = '';
			if (this.campaignData.template_data.components) {
				this.campaignData.template_data.components.forEach((component) => {
					if (component.type === 'BODY' && component.text) {
						previewText = component.text;
					}
				});
			}

			// Replace variables with sample data
			const variables = this.campaignData.template_variables || [];
			variables.forEach((varNum) => {
				const type = $(`select[name="var_${varNum}_type"]`).val();
				const customValue = $(`input[name="var_${varNum}_value"]`).val();
				const value = customValue || sampleData[type] || `[${type}]`;
				previewText = previewText.replace(new RegExp(`\\{\\{${varNum}\\}\\}`, 'g'), value);
			});

			$preview.html(`<div style="background: #fff; padding: 16px; border-radius: 4px; border: 1px solid #ddd;">${previewText}</div>`);
		},

		renderReviewStep: function() {
			// Update campaign name
			const campaignName = this.campaignData.name || `Campaign ${new Date().toLocaleDateString()}`;
			$('input[name="campaign_name"]').val(campaignName);

			// Update review summary
			$('#review-template').text(this.campaignData.template_name);
			$('#review-audience').text(`${this.campaignData.audience_size.toLocaleString()} recipients`);

			const timing = $('input[name="send_timing"]:checked').val();
			if (timing === 'scheduled') {
				const date = $('input[name="schedule_date"]').val();
				const time = $('input[name="schedule_time"]').val();
				const tz = $('select[name="schedule_timezone"]').val();
				$('#review-schedule').text(`Scheduled: ${date} ${time} (${tz})`);

				this.campaignData.schedule = {
					timing: 'scheduled',
					datetime: `${date} ${time}`,
					timezone: tz,
				};
			} else {
				$('#review-schedule').text('Send immediately');
				this.campaignData.schedule = {
					timing: 'now',
				};
			}

			// Calculate estimated cost (rough estimate: $0.005 per message)
			const estimatedCost = (this.campaignData.audience_size * 0.005).toFixed(2);
			$('#review-cost').text(`$${estimatedCost} USD (estimated)`);

			// Collect personalization data
			const personalization = {};
			const variables = this.campaignData.template_variables || [];
			variables.forEach((varNum) => {
				const type = $(`select[name="var_${varNum}_type"]`).val();
				const value = $(`input[name="var_${varNum}_value"]`).val();
				personalization[varNum] = { type, value };
			});
			this.campaignData.personalization = personalization;

			// Update message preview
			this.renderReviewPreview();
		},

		renderReviewPreview: function() {
			const $preview = $('#review-message-preview');
			$preview.empty();

			if (this.campaignData.template_data.components) {
				this.campaignData.template_data.components.forEach((component) => {
					if (component.type === 'HEADER') {
						$preview.append(`<div style="font-weight: bold; margin-bottom: 8px;">${component.text || ''}</div>`);
					} else if (component.type === 'BODY') {
						let bodyText = component.text || '';
						// Replace variables with placeholders
						const variables = this.campaignData.template_variables || [];
						variables.forEach((varNum) => {
							const mapping = this.campaignData.personalization[varNum];
							if (mapping) {
								const placeholder = mapping.value || `[${mapping.type}]`;
								bodyText = bodyText.replace(new RegExp(`\\{\\{${varNum}\\}\\}`, 'g'), placeholder);
							}
						});
						$preview.append(`<div style="margin-bottom: 8px;">${bodyText}</div>`);
					} else if (component.type === 'FOOTER') {
						$preview.append(`<div style="font-size: 12px; color: #999; margin-top: 8px;">${component.text || ''}</div>`);
					}
				});
			}
		},

		sendTestBroadcast: function() {
			const testPhone = prompt('Enter test phone number (with country code):');
			if (!testPhone) {
				return;
			}

			// Update campaign name
			this.campaignData.name = $('input[name="campaign_name"]').val() || `Campaign ${new Date().toLocaleDateString()}`;

			$.ajax({
				url: wchBroadcasts.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wch_send_test_broadcast',
					nonce: wchBroadcasts.nonce,
					campaign: JSON.stringify(this.campaignData),
					test_phone: testPhone,
				},
				beforeSend: () => {
					$('#wch-send-test').prop('disabled', true).text('Sending...');
				},
				success: (response) => {
					if (response.success) {
						alert(wchBroadcasts.strings.testSent);
					} else {
						alert(response.data.message || wchBroadcasts.strings.errorOccurred);
					}
				},
				error: () => {
					alert(wchBroadcasts.strings.errorOccurred);
				},
				complete: () => {
					$('#wch-send-test').prop('disabled', false).text('Send Test Message');
				},
			});
		},

		confirmSendCampaign: function() {
			if (!confirm(wchBroadcasts.strings.confirmSend)) {
				return;
			}

			// Update campaign name
			this.campaignData.name = $('input[name="campaign_name"]').val() || `Campaign ${new Date().toLocaleDateString()}`;

			// Get or create campaign ID
			if (!this.campaignData.id || this.campaignData.id === 0) {
				this.campaignData.id = Date.now();
			}

			$.ajax({
				url: wchBroadcasts.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wch_send_campaign',
					nonce: wchBroadcasts.nonce,
					campaign: JSON.stringify(this.campaignData),
				},
				beforeSend: () => {
					$('#wch-confirm-send').prop('disabled', true).text(wchBroadcasts.strings.sendingCampaign);
				},
				success: (response) => {
					if (response.success) {
						alert(wchBroadcasts.strings.campaignScheduled);
						window.location.href = 'admin.php?page=wch-broadcasts';
					} else {
						alert(response.data.message || wchBroadcasts.strings.errorOccurred);
						$('#wch-confirm-send').prop('disabled', false).text('Confirm & Schedule Campaign');
					}
				},
				error: () => {
					alert(wchBroadcasts.strings.errorOccurred);
					$('#wch-confirm-send').prop('disabled', false).text('Confirm & Schedule Campaign');
				},
			});
		},

		deleteCampaign: function(e) {
			if (!confirm(wchBroadcasts.strings.confirmDelete)) {
				return;
			}

			const campaignId = $(e.currentTarget).data('campaign-id');

			$.ajax({
				url: wchBroadcasts.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wch_delete_campaign',
					nonce: wchBroadcasts.nonce,
					campaign_id: campaignId,
				},
				beforeSend: () => {
					$(e.currentTarget).prop('disabled', true).text(wchBroadcasts.strings.deletingCampaign);
				},
				success: (response) => {
					if (response.success) {
						$(`tr[data-campaign-id="${campaignId}"]`).fadeOut(300, function() {
							$(this).remove();
						});
					} else {
						alert(response.data.message || wchBroadcasts.strings.errorOccurred);
						$(e.currentTarget).prop('disabled', false).text('Delete');
					}
				},
				error: () => {
					alert(wchBroadcasts.strings.errorOccurred);
					$(e.currentTarget).prop('disabled', false).text('Delete');
				},
			});
		},

		duplicateCampaign: function(e) {
			const campaignId = $(e.currentTarget).data('campaign-id');

			$.ajax({
				url: wchBroadcasts.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wch_duplicate_campaign',
					nonce: wchBroadcasts.nonce,
					campaign_id: campaignId,
				},
				beforeSend: () => {
					$(e.currentTarget).prop('disabled', true).text('Duplicating...');
				},
				success: (response) => {
					if (response.success) {
						window.location.reload();
					} else {
						alert(response.data.message || wchBroadcasts.strings.errorOccurred);
						$(e.currentTarget).prop('disabled', false).text('Duplicate');
					}
				},
				error: () => {
					alert(wchBroadcasts.strings.errorOccurred);
					$(e.currentTarget).prop('disabled', false).text('Duplicate');
				},
			});
		},

		exportReport: function(e) {
			const campaignId = $(e.currentTarget).data('campaign-id');
			alert('Export functionality would be implemented here for campaign ' + campaignId);
		},
	};

	// Initialize on document ready
	$(document).ready(function() {
		if ($('.wch-broadcasts-wrap').length) {
			BroadcastsAdmin.init();
		}
	});

})(jQuery);
