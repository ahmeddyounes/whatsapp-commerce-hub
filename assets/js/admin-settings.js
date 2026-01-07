/**
 * Admin Settings Page JavaScript
 */

jQuery(document).ready(function($) {
	'use strict';

	// Save settings via AJAX
	$('#wch-settings-form').on('submit', function(e) {
		e.preventDefault();

		const $form = $(this);
		const $button = $('#wch-save-settings');
		const $spinner = $button.siblings('.spinner');
		const $message = $('.wch-save-message');

		$button.prop('disabled', true);
		$spinner.addClass('is-active');
		$message.removeClass('success error').text('');

		$.ajax({
			url: wchSettings.ajax_url,
			type: 'POST',
			data: $form.serialize() + '&action=wch_save_settings_ajax&nonce=' + wchSettings.nonce,
			dataType: 'json',
			success: function(response) {
				if (response.success) {
					$message.addClass('success').text(wchSettings.strings.settings_saved);

					// Show WordPress admin notice
					showNotice('success', wchSettings.strings.settings_saved);
				} else {
					$message.addClass('error').text(response.data.message || wchSettings.strings.settings_error);
					showNotice('error', response.data.message || wchSettings.strings.settings_error);
				}
			},
			error: function() {
				$message.addClass('error').text(wchSettings.strings.settings_error);
				showNotice('error', wchSettings.strings.settings_error);
			},
			complete: function() {
				$button.prop('disabled', false);
				$spinner.removeClass('is-active');

				// Clear message after 3 seconds
				setTimeout(function() {
					$message.fadeOut(function() {
						$(this).removeClass('success error').text('').show();
					});
				}, 3000);
			}
		});
	});

	// Test WhatsApp connection
	$('#test-connection').on('click', function() {
		const $button = $(this);
		const $spinner = $button.siblings('.spinner');
		const $status = $('#connection-status');

		$button.prop('disabled', true);
		$spinner.addClass('is-active');
		$status.removeClass('success error').html('');

		$.ajax({
			url: wchSettings.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_test_connection',
				nonce: wchSettings.nonce
			},
			dataType: 'json',
			success: function(response) {
				if (response.success) {
					$status.addClass('success').html(
						'<strong>' + response.data.message + '</strong><br>' +
						(response.data.profile ? formatBusinessProfile(response.data.profile) : '')
					);
				} else {
					$status.addClass('error').html('<strong>' + response.data.message + '</strong>');
				}
			},
			error: function() {
				$status.addClass('error').html('<strong>' + wchSettings.strings.error + '</strong>');
			},
			complete: function() {
				$button.prop('disabled', false);
				$spinner.removeClass('is-active');
			}
		});
	});

	// Format business profile data
	function formatBusinessProfile(profile) {
		let html = '<ul style="margin-top: 10px; margin-left: 20px;">';
		if (profile.about) {
			html += '<li>About: ' + escapeHtml(profile.about) + '</li>';
		}
		if (profile.address) {
			html += '<li>Address: ' + escapeHtml(profile.address) + '</li>';
		}
		if (profile.description) {
			html += '<li>Description: ' + escapeHtml(profile.description) + '</li>';
		}
		if (profile.vertical) {
			html += '<li>Category: ' + escapeHtml(profile.vertical) + '</li>';
		}
		html += '</ul>';
		return html;
	}

	// Regenerate verify token
	$('#regenerate-verify-token').on('click', function() {
		if (!confirm('Are you sure you want to regenerate the verify token? You will need to update your webhook configuration.')) {
			return;
		}

		const $button = $(this);

		$.ajax({
			url: wchSettings.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_regenerate_verify_token',
				nonce: wchSettings.nonce
			},
			dataType: 'json',
			success: function(response) {
				if (response.success) {
					$('#verify_token').val(response.data.token);
					showNotice('success', 'Verify token regenerated successfully');
				} else {
					showNotice('error', response.data.message);
				}
			},
			error: function() {
				showNotice('error', wchSettings.strings.error);
			}
		});
	});

	// Copy webhook URL
	$('#copy-webhook-url').on('click', function() {
		const $input = $('#webhook_url');
		const $button = $(this);

		$input.select();
		document.execCommand('copy');

		$button.text(wchSettings.strings.copied);
		setTimeout(function() {
			$button.text('Copy');
		}, 2000);
	});

	// Product selection mode toggle
	$('input[name="catalog[product_selection]"]').on('change', function() {
		const mode = $(this).val();

		$('.wch-product-categories').toggle(mode === 'categories');
		$('.wch-product-products').toggle(mode === 'products');
	});

	// Product search
	let searchTimeout;
	$('#catalog_products_search').on('keyup', function() {
		clearTimeout(searchTimeout);
		const query = $(this).val();

		if (query.length < 3) {
			return;
		}

		searchTimeout = setTimeout(function() {
			searchProducts(query);
		}, 500);
	});

	// Search products
	function searchProducts(query) {
		$.ajax({
			url: wchSettings.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_search_products',
				nonce: wchSettings.nonce,
				query: query
			},
			dataType: 'json',
			success: function(response) {
				if (response.success && response.data.products) {
					showProductSearchResults(response.data.products);
				}
			}
		});
	}

	// Show product search results
	function showProductSearchResults(products) {
		// Implementation would show a dropdown of search results
		// For now, this is a placeholder
	}

	// Remove product from selection
	$(document).on('click', '.wch-remove-product', function() {
		$(this).closest('.wch-product-item').fadeOut(function() {
			$(this).remove();
		});
	});

	// Sync catalog
	$('#sync-catalog-now').on('click', function() {
		const $button = $(this);
		const $spinner = $button.siblings('.spinner');
		const $progress = $('#sync-progress');
		const $fill = $('.wch-progress-fill');
		const $status = $('#sync-status');

		$button.prop('disabled', true);
		$spinner.addClass('is-active');
		$progress.show();
		$fill.css('width', '0%');
		$status.removeClass('success error').text('');

		// Simulate progress
		let progress = 0;
		const progressInterval = setInterval(function() {
			progress += Math.random() * 30;
			if (progress > 90) {
				progress = 90;
				clearInterval(progressInterval);
			}
			$fill.css('width', progress + '%');
		}, 500);

		$.ajax({
			url: wchSettings.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_sync_catalog',
				nonce: wchSettings.nonce
			},
			dataType: 'json',
			success: function(response) {
				clearInterval(progressInterval);
				$fill.css('width', '100%');

				setTimeout(function() {
					if (response.success) {
						$status.addClass('success').text(response.data.message);
						showNotice('success', response.data.message);

						// Update last sync time
						if (response.data.result && response.data.result.timestamp) {
							updateLastSyncTime(response.data.result.timestamp);
						}
					} else {
						$status.addClass('error').text(response.data.message);
						showNotice('error', response.data.message);
					}

					$progress.fadeOut();
				}, 500);
			},
			error: function() {
				clearInterval(progressInterval);
				$status.addClass('error').text(wchSettings.strings.error);
				$progress.fadeOut();
				showNotice('error', wchSettings.strings.error);
			},
			complete: function() {
				$button.prop('disabled', false);
				$spinner.removeClass('is-active');
			}
		});
	});

	// Update last sync time
	function updateLastSyncTime(timestamp) {
		// Update the UI with the new sync time
		// This would need to format the timestamp properly
	}

	// COD settings toggle
	$('input[name="checkout[cod_enabled]"]').on('change', function() {
		$('.wch-cod-settings').toggle($(this).is(':checked'));
	});

	// Test notification
	$('.wch-test-notification').on('click', function() {
		const $button = $(this);
		const $spinner = $button.siblings('.spinner');
		const $result = $button.siblings('.wch-test-result');
		const type = $button.data('type');

		$button.prop('disabled', true);
		$spinner.addClass('is-active');
		$result.removeClass('success error').text('');

		$.ajax({
			url: wchSettings.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_test_notification',
				nonce: wchSettings.nonce,
				type: type
			},
			dataType: 'json',
			success: function(response) {
				if (response.success) {
					$result.addClass('success').text('✓ ' + response.data.message);
				} else {
					$result.addClass('error').text('✗ ' + response.data.message);
				}
			},
			error: function() {
				$result.addClass('error').text('✗ ' + wchSettings.strings.error);
			},
			complete: function() {
				$button.prop('disabled', false);
				$spinner.removeClass('is-active');

				// Clear result after 5 seconds
				setTimeout(function() {
					$result.fadeOut(function() {
						$(this).removeClass('success error').text('').show();
					});
				}, 5000);
			}
		});
	});

	// AI settings toggle
	$('input[name="ai[enabled]"]').on('change', function() {
		$('.wch-ai-settings').toggle($(this).is(':checked'));
	});

	// Temperature slider
	$('#ai_temperature').on('input', function() {
		$('#temperature-value').text($(this).val());
	});

	// Clear logs
	$('#clear-logs').on('click', function() {
		if (!confirm(wchSettings.strings.confirm_clear)) {
			return;
		}

		const $button = $(this);
		const $spinner = $button.siblings('.spinner');

		$button.prop('disabled', true);
		$spinner.addClass('is-active');

		$.ajax({
			url: wchSettings.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_clear_logs',
				nonce: wchSettings.nonce
			},
			dataType: 'json',
			success: function(response) {
				if (response.success) {
					showNotice('success', response.data.message);
				} else {
					showNotice('error', response.data.message);
				}
			},
			error: function() {
				showNotice('error', wchSettings.strings.error);
			},
			complete: function() {
				$button.prop('disabled', false);
				$spinner.removeClass('is-active');
			}
		});
	});

	// Export settings
	$('#export-settings').on('click', function() {
		$.ajax({
			url: wchSettings.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_export_settings',
				nonce: wchSettings.nonce
			},
			dataType: 'json',
			success: function(response) {
				if (response.success) {
					downloadJSON(response.data.settings, response.data.filename);
					showNotice('success', 'Settings exported successfully');
				} else {
					showNotice('error', response.data.message);
				}
			},
			error: function() {
				showNotice('error', wchSettings.strings.error);
			}
		});
	});

	// Download JSON file
	function downloadJSON(data, filename) {
		const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = filename;
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(url);
	}

	// Import settings
	$('#import-settings').on('click', function() {
		const fileInput = document.getElementById('import-settings-file');
		const file = fileInput.files[0];

		if (!file) {
			showNotice('error', 'Please select a file to import');
			return;
		}

		const $button = $(this);
		const $spinner = $button.siblings('.spinner');
		const reader = new FileReader();

		reader.onload = function(e) {
			try {
				const settings = JSON.parse(e.target.result);

				$button.prop('disabled', true);
				$spinner.addClass('is-active');

				$.ajax({
					url: wchSettings.ajax_url,
					type: 'POST',
					data: {
						action: 'wch_import_settings',
						nonce: wchSettings.nonce,
						settings: JSON.stringify(settings)
					},
					dataType: 'json',
					success: function(response) {
						if (response.success) {
							showNotice('success', response.data.message);
							setTimeout(function() {
								location.reload();
							}, 1500);
						} else {
							showNotice('error', response.data.message);
						}
					},
					error: function() {
						showNotice('error', wchSettings.strings.error);
					},
					complete: function() {
						$button.prop('disabled', false);
						$spinner.removeClass('is-active');
					}
				});
			} catch (error) {
				showNotice('error', 'Invalid JSON file');
			}
		};

		reader.readAsText(file);
	});

	// Reset settings
	$('#reset-settings').on('click', function() {
		if (!confirm(wchSettings.strings.confirm_reset)) {
			return;
		}

		const $button = $(this);
		const $spinner = $button.siblings('.spinner');

		$button.prop('disabled', true);
		$spinner.addClass('is-active');

		$.ajax({
			url: wchSettings.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_reset_settings',
				nonce: wchSettings.nonce
			},
			dataType: 'json',
			success: function(response) {
				if (response.success) {
					showNotice('success', response.data.message);
					setTimeout(function() {
						location.reload();
					}, 1500);
				} else {
					showNotice('error', response.data.message);
				}
			},
			error: function() {
				showNotice('error', wchSettings.strings.error);
			},
			complete: function() {
				$button.prop('disabled', false);
				$spinner.removeClass('is-active');
			}
		});
	});

	// Helper: Show admin notice
	function showNotice(type, message) {
		const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
		const $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + escapeHtml(message) + '</p></div>');

		$('.wch-settings-wrap h1').after($notice);

		// Make dismissible
		$notice.on('click', '.notice-dismiss', function() {
			$notice.fadeOut(function() {
				$(this).remove();
			});
		});

		// Add dismiss button
		$notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');

		// Auto-dismiss after 5 seconds
		setTimeout(function() {
			$notice.fadeOut(function() {
				$(this).remove();
			});
		}, 5000);
	}

	// Helper: Escape HTML
	function escapeHtml(text) {
		const map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};
		return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
	}

	// Initialize conditional fields visibility
	$('.wch-cod-settings').toggle($('input[name="checkout[cod_enabled]"]').is(':checked'));
	$('.wch-ai-settings').toggle($('input[name="ai[enabled]"]').is(':checked'));

	const productMode = $('input[name="catalog[product_selection]"]:checked').val();
	$('.wch-product-categories').toggle(productMode === 'categories');
	$('.wch-product-products').toggle(productMode === 'products');
});
