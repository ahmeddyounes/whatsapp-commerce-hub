/**
 * Admin Templates Page JavaScript
 */

(function ($) {
	'use strict';

	$(document).ready(function () {
		// Handle template preview toggle
		$('.wch-preview-template').on('click', function (e) {
			e.preventDefault();

			var button = $(this);
			var templateName = button.data('template');
			var previewRow = $('#preview-' + templateName);

			// Toggle the preview row
			if (previewRow.is(':visible')) {
				previewRow.slideUp(300);
				button.text(button.data('text-show') || 'Preview');
			} else {
				// Hide all other preview rows
				$('.wch-template-preview-row').slideUp(300);
				$('.wch-preview-template').text('Preview');

				// Show this preview row
				previewRow.slideDown(300);
				button.text(button.data('text-hide') || 'Hide Preview');
			}
		});

		// Handle template preview with variables (AJAX)
		$('.wch-render-template').on('click', function (e) {
			e.preventDefault();

			var button = $(this);
			var templateName = button.data('template');
			var variablesInput = $('#variables-' + templateName);
			var variables = [];

			// Parse variables from input (comma-separated)
			if (variablesInput.length && variablesInput.val()) {
				variables = variablesInput.val().split(',').map(function (v) {
					return v.trim();
				});
			}

			// Show loading state
			button.prop('disabled', true).text('Rendering...');

			// Make AJAX request
			$.ajax({
				url: wchTemplates.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wch_preview_template',
					nonce: wchTemplates.nonce,
					template_name: templateName,
					variables: variables,
				},
				success: function (response) {
					if (response.success && response.data.template) {
						// Update preview with rendered template
						var previewContainer = $('#preview-' + templateName + ' .wch-template-content');
						previewContainer.html(renderTemplatePreview(response.data.template));
					} else {
						alert('Error: ' + (response.data.message || 'Unknown error'));
					}
				},
				error: function () {
					alert('Failed to render template. Please try again.');
				},
				complete: function () {
					button.prop('disabled', false).text('Render');
				},
			});
		});

		/**
		 * Render template preview HTML
		 *
		 * @param {Object} template Template data
		 * @return {string} HTML string
		 */
		function renderTemplatePreview(template) {
			var html = '';

			if (!template.components || !template.components.length) {
				return '<p>No preview available</p>';
			}

			template.components.forEach(function (component) {
				var type = component.type || '';
				var text = component.text || '';

				if (type === 'HEADER') {
					html += '<div class="wch-preview-header"><strong>' + escapeHtml(text) + '</strong></div>';
				} else if (type === 'BODY') {
					html += '<div class="wch-preview-body">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div>';
				} else if (type === 'FOOTER') {
					html += '<div class="wch-preview-footer"><small>' + escapeHtml(text) + '</small></div>';
				} else if (type === 'BUTTONS' && component.buttons && component.buttons.length) {
					html += '<div class="wch-preview-buttons">';
					component.buttons.forEach(function (button) {
						html += '<button type="button" class="wch-preview-button" disabled>' + escapeHtml(button.text || '') + '</button>';
					});
					html += '</div>';
				}
			});

			return html;
		}

		/**
		 * Escape HTML to prevent XSS
		 *
		 * @param {string} text Text to escape
		 * @return {string} Escaped text
		 */
		function escapeHtml(text) {
			var map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;',
			};
			return text.replace(/[&<>"']/g, function (m) {
				return map[m];
			});
		}
	});
})(jQuery);
