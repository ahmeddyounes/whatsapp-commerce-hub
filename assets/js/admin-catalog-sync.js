/**
 * Admin Catalog Sync Page JavaScript
 *
 * @package WhatsApp_Commerce_Hub
 */

(function ($) {
	'use strict';

	let currentPage = 1;
	let currentHistoryPage = 1;
	let syncInProgress = false;
	let syncCancelled = false;

	/**
	 * Initialize the page
	 */
	function init() {
		// Load initial data
		loadProducts();
		loadSyncHistory();

		// Bind event handlers
		bindEventHandlers();

		// Show/hide schedule row based on sync mode
		toggleScheduleRow();
	}

	/**
	 * Bind event handlers
	 */
	function bindEventHandlers() {
		// Filters
		$('#filter-category, #filter-stock, #filter-sync-status, #search-products').on('change keyup', function () {
			currentPage = 1;
			loadProducts();
		});

		// Select all checkbox
		$('#select-all-products').on('change', function () {
			$('.wch-products-table tbody input[type="checkbox"]').prop('checked', this.checked);
		});

		// Pagination
		$('#prev-page').on('click', function () {
			if (currentPage > 1) {
				currentPage--;
				loadProducts();
			}
		});

		$('#next-page').on('click', function () {
			currentPage++;
			loadProducts();
		});

		// History pagination
		$('#history-prev-page').on('click', function () {
			if (currentHistoryPage > 1) {
				currentHistoryPage--;
				loadSyncHistory();
			}
		});

		$('#history-next-page').on('click', function () {
			currentHistoryPage++;
			loadSyncHistory();
		});

		// Bulk actions
		$('#apply-bulk-action').on('click', handleBulkAction);

		// Sync actions
		$('#sync-all-now').on('click', function () {
			syncProducts(true);
		});

		$('#sync-selected').on('click', function () {
			syncProducts(false);
		});

		$('#refresh-status').on('click', function () {
			loadProducts();
			loadSyncHistory();
			refreshSyncStatus();
		});

		$('#dry-run').on('click', handleDryRun);

		// Settings form
		$('#sync-settings-form').on('submit', handleSaveSettings);

		// Sync mode change
		$('#sync-mode').on('change', toggleScheduleRow);

		// Modal close
		$('.wch-modal-close, #cancel-sync').on('click', function () {
			syncCancelled = true;
			$('.wch-modal').hide();
		});

		// Show sync errors
		$('#show-sync-errors').on('click', function (e) {
			e.preventDefault();
			showSyncErrors();
		});

		// Individual product actions
		$(document).on('click', '.sync-product-btn', function () {
			const productId = $(this).data('product-id');
			syncSingleProduct(productId);
		});

		$(document).on('click', '.remove-product-btn', function () {
			const productId = $(this).data('product-id');
			removeFromCatalog([productId]);
		});

		$(document).on('click', '.view-error-btn', function () {
			const error = $(this).data('error');
			showErrorDetails(error);
		});
	}

	/**
	 * Load products table
	 */
	function loadProducts() {
		const $tbody = $('#products-table-body');
		$tbody.html('<tr><td colspan="9" class="wch-loading"><span class="spinner is-active"></span> ' + wchCatalogSync.strings.processing + '</td></tr>');

		const filters = {
			page: currentPage,
			per_page: 20,
			search: $('#search-products').val(),
			category: $('#filter-category').val(),
			stock: $('#filter-stock').val(),
			sync_status: $('#filter-sync-status').val(),
		};

		$.ajax({
			url: wchCatalogSync.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_get_products',
				nonce: wchCatalogSync.nonce,
				...filters,
			},
			success: function (response) {
				if (response.success) {
					renderProductsTable(response.data.products);
					updatePagination(response.data.total, response.data.total_pages);
				} else {
					showError(response.data.message);
				}
			},
			error: function () {
				showError('Failed to load products');
			},
		});
	}

	/**
	 * Render products table
	 */
	function renderProductsTable(products) {
		const $tbody = $('#products-table-body');
		$tbody.empty();

		if (products.length === 0) {
			$tbody.html('<tr><td colspan="9" class="wch-loading">' + wchCatalogSync.strings.no_products + '</td></tr>');
			return;
		}

		products.forEach(function (product) {
			const syncBadgeClass = 'wch-sync-badge ' + product.sync_status;
			const syncBadgeText = product.sync_status.replace('_', ' ');
			const stockBadgeClass = 'wch-stock-badge ' + product.stock;
			const stockBadgeText = product.stock.replace('_', ' ');

			let actions = '<div class="wch-product-actions">';
			actions += '<button type="button" class="button button-small sync-product-btn" data-product-id="' + product.id + '">Sync</button>';
			if (product.sync_status === 'synced') {
				actions += '<a href="#" class="button button-small" target="_blank">View</a>';
			}
			if (product.sync_status !== 'not_selected') {
				actions += '<button type="button" class="button button-small remove-product-btn" data-product-id="' + product.id + '">Remove</button>';
			}
			if (product.sync_status === 'error' && product.error) {
				actions += '<button type="button" class="button button-small view-error-btn" data-error="' + escapeHtml(product.error) + '">Error</button>';
			}
			actions += '</div>';

			const imageHtml = product.image_url
				? '<img src="' + product.image_url + '" alt="' + escapeHtml(product.name) + '">'
				: '<div style="width:50px;height:50px;background:#f0f0f1;"></div>';

			const row = '<tr>' +
				'<td class="check-column"><input type="checkbox" value="' + product.id + '"></td>' +
				'<td>' + imageHtml + '</td>' +
				'<td>' + escapeHtml(product.name) + '</td>' +
				'<td>' + escapeHtml(product.sku || '-') + '</td>' +
				'<td>' + product.price + '</td>' +
				'<td><span class="' + stockBadgeClass + '">' + stockBadgeText + '</span></td>' +
				'<td><span class="' + syncBadgeClass + '" ' + (product.error ? 'data-tooltip="' + escapeHtml(product.error) + '"' : '') + '>' + syncBadgeText + '</span></td>' +
				'<td>' + product.last_synced + '</td>' +
				'<td>' + actions + '</td>' +
				'</tr>';

			$tbody.append(row);
		});
	}

	/**
	 * Update pagination
	 */
	function updatePagination(total, totalPages) {
		$('.wch-pagination-info').text('Showing page ' + currentPage + ' of ' + totalPages + ' (' + total + ' total products)');
		$('.wch-page-number').text('Page ' + currentPage + ' of ' + totalPages);

		$('#prev-page').prop('disabled', currentPage === 1);
		$('#next-page').prop('disabled', currentPage >= totalPages);
	}

	/**
	 * Load sync history
	 */
	function loadSyncHistory() {
		const $tbody = $('#history-table-body');
		$tbody.html('<tr><td colspan="7" class="wch-loading"><span class="spinner is-active"></span> ' + wchCatalogSync.strings.processing + '</td></tr>');

		$.ajax({
			url: wchCatalogSync.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_get_sync_history',
				nonce: wchCatalogSync.nonce,
				page: currentHistoryPage,
				per_page: 20,
			},
			success: function (response) {
				if (response.success) {
					renderHistoryTable(response.data.history);
					updateHistoryPagination(response.data.total_pages);
				}
			},
		});
	}

	/**
	 * Render history table
	 */
	function renderHistoryTable(history) {
		const $tbody = $('#history-table-body');
		$tbody.empty();

		if (history.length === 0) {
			$tbody.html('<tr><td colspan="7" class="wch-loading">No sync history available</td></tr>');
			return;
		}

		history.forEach(function (entry) {
			const statusBadge = '<span class="wch-history-badge ' + entry.status + '">' + entry.status + '</span>';
			const errors = entry.error_count > 0
				? '<a href="#" class="wch-history-error-details" data-errors="' + escapeHtml(JSON.stringify(entry.errors)) + '">' + entry.error_count + '</a>'
				: '0';

			const row = '<tr>' +
				'<td>' + entry.timestamp + '</td>' +
				'<td>' + entry.products_count + '</td>' +
				'<td>' + statusBadge + '</td>' +
				'<td>' + (entry.duration > 0 ? entry.duration + 's' : '-') + '</td>' +
				'<td>' + errors + '</td>' +
				'<td>' + entry.triggered_by + '</td>' +
				'<td><button type="button" class="button button-small">View Details</button></td>' +
				'</tr>';

			$tbody.append(row);
		});
	}

	/**
	 * Update history pagination
	 */
	function updateHistoryPagination(totalPages) {
		$('.wch-history-page-number').text('Page ' + currentHistoryPage + ' of ' + totalPages);
		$('#history-prev-page').prop('disabled', currentHistoryPage === 1);
		$('#history-next-page').prop('disabled', currentHistoryPage >= totalPages);
	}

	/**
	 * Handle bulk action
	 */
	function handleBulkAction() {
		const action = $('#bulk-action').val();
		const selectedIds = getSelectedProductIds();

		if (!action) {
			alert('Please select an action');
			return;
		}

		if (selectedIds.length === 0) {
			alert(wchCatalogSync.strings.no_products);
			return;
		}

		switch (action) {
			case 'add':
				syncProducts(false, selectedIds);
				break;
			case 'remove':
				if (confirm(wchCatalogSync.strings.confirm_remove)) {
					removeFromCatalog(selectedIds);
				}
				break;
			case 'retry':
				if (confirm(wchCatalogSync.strings.confirm_retry)) {
					retryFailed();
				}
				break;
		}
	}

	/**
	 * Get selected product IDs
	 */
	function getSelectedProductIds() {
		const ids = [];
		$('.wch-products-table tbody input[type="checkbox"]:checked').each(function () {
			ids.push($(this).val());
		});
		return ids;
	}

	/**
	 * Sync products
	 */
	function syncProducts(syncAll, productIds) {
		if (syncInProgress) {
			alert('Sync already in progress');
			return;
		}

		if (!syncAll && (!productIds || productIds.length === 0)) {
			const selectedIds = getSelectedProductIds();
			if (selectedIds.length === 0) {
				alert(wchCatalogSync.strings.no_products);
				return;
			}
			productIds = selectedIds;
		}

		syncInProgress = true;
		syncCancelled = false;

		// Show progress modal
		showProgressModal(syncAll ? 'all' : productIds.length);

		// Start sync
		$.ajax({
			url: wchCatalogSync.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_bulk_sync',
				nonce: wchCatalogSync.nonce,
				sync_all: syncAll,
				product_ids: productIds,
			},
			success: function (response) {
				syncInProgress = false;
				hideProgressModal();

				if (response.success) {
					showSuccess(response.data.message);
					loadProducts();
					loadSyncHistory();
					refreshSyncStatus();
				} else {
					showError(response.data.message);
				}
			},
			error: function () {
				syncInProgress = false;
				hideProgressModal();
				showError('Sync failed');
			},
		});
	}

	/**
	 * Sync single product
	 */
	function syncSingleProduct(productId) {
		const $btn = $('.sync-product-btn[data-product-id="' + productId + '"]');
		$btn.prop('disabled', true).text(wchCatalogSync.strings.syncing);

		$.ajax({
			url: wchCatalogSync.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_sync_product',
				nonce: wchCatalogSync.nonce,
				product_id: productId,
			},
			success: function (response) {
				$btn.prop('disabled', false).text('Sync');
				if (response.success) {
					showSuccess(response.data.message);
					loadProducts();
				} else {
					showError(response.data.message);
				}
			},
			error: function () {
				$btn.prop('disabled', false).text('Sync');
				showError('Sync failed');
			},
		});
	}

	/**
	 * Remove from catalog
	 */
	function removeFromCatalog(productIds) {
		$.ajax({
			url: wchCatalogSync.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_remove_from_catalog',
				nonce: wchCatalogSync.nonce,
				product_ids: productIds,
			},
			success: function (response) {
				if (response.success) {
					showSuccess(response.data.message);
					loadProducts();
					refreshSyncStatus();
				} else {
					showError(response.data.message);
				}
			},
			error: function () {
				showError('Failed to remove products');
			},
		});
	}

	/**
	 * Retry failed products
	 */
	function retryFailed() {
		$.ajax({
			url: wchCatalogSync.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_retry_failed',
				nonce: wchCatalogSync.nonce,
			},
			success: function (response) {
				if (response.success) {
					showSuccess(response.data.message);
					loadProducts();
				} else {
					showError(response.data.message);
				}
			},
			error: function () {
				showError('Failed to retry products');
			},
		});
	}

	/**
	 * Handle dry run
	 */
	function handleDryRun() {
		$.ajax({
			url: wchCatalogSync.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_dry_run_sync',
				nonce: wchCatalogSync.nonce,
			},
			success: function (response) {
				if (response.success) {
					const products = response.data.products;
					let message = 'Dry Run Results:\n\n';
					message += 'Total products to sync: ' + response.data.count + '\n\n';
					message += 'Products:\n';
					products.slice(0, 10).forEach(function (product) {
						message += '- ' + product.name + ' (ID: ' + product.id + ', SKU: ' + product.sku + ')\n';
					});
					if (products.length > 10) {
						message += '\n... and ' + (products.length - 10) + ' more';
					}
					alert(message);
				} else {
					showError(response.data.message);
				}
			},
			error: function () {
				showError('Dry run failed');
			},
		});
	}

	/**
	 * Refresh sync status
	 */
	function refreshSyncStatus() {
		$.ajax({
			url: wchCatalogSync.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_get_sync_status',
				nonce: wchCatalogSync.nonce,
			},
			success: function (response) {
				if (response.success) {
					// Update status cards
					$('.wch-status-card').eq(0).find('.wch-status-card-value').text(response.data.total_synced);
					$('.wch-status-card').eq(2).find('.wch-status-card-value').text(response.data.error_count);
				}
			},
		});
	}

	/**
	 * Handle save settings
	 */
	function handleSaveSettings(e) {
		e.preventDefault();

		const formData = $(this).serialize();
		const $btn = $(this).find('button[type="submit"]');
		$btn.prop('disabled', true).text(wchCatalogSync.strings.processing);

		$.ajax({
			url: wchCatalogSync.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_save_sync_settings',
				nonce: wchCatalogSync.nonce,
				...Object.fromEntries(new URLSearchParams(formData)),
			},
			success: function (response) {
				$btn.prop('disabled', false).text('Save Settings');
				if (response.success) {
					showSuccess(response.data.message);
				} else {
					showError(response.data.message);
				}
			},
			error: function () {
				$btn.prop('disabled', false).text('Save Settings');
				showError('Failed to save settings');
			},
		});
	}

	/**
	 * Toggle schedule row
	 */
	function toggleScheduleRow() {
		const syncMode = $('#sync-mode').val();
		$('.sync-schedule-row').toggle(syncMode === 'scheduled');
	}

	/**
	 * Show progress modal
	 */
	function showProgressModal(total) {
		$('#progress-total').text(total);
		$('#progress-processed').text(0);
		$('#progress-current-product').text('-');
		$('#progress-errors').text(0);
		$('#progress-eta').text('-');
		$('.wch-progress-fill').css('width', '0%');
		$('#sync-progress-modal').fadeIn();
	}

	/**
	 * Hide progress modal
	 */
	function hideProgressModal() {
		$('#sync-progress-modal').fadeOut();
	}

	/**
	 * Show sync errors
	 */
	function showSyncErrors() {
		$.ajax({
			url: wchCatalogSync.ajax_url,
			type: 'POST',
			data: {
				action: 'wch_get_products',
				nonce: wchCatalogSync.nonce,
				sync_status: 'error',
				per_page: 100,
			},
			success: function (response) {
				if (response.success) {
					const products = response.data.products;
					let content = '';

					if (products.length === 0) {
						content = '<p>No sync errors found.</p>';
					} else {
						products.forEach(function (product) {
							content += '<div class="wch-error-item">';
							content += '<h4>' + escapeHtml(product.name) + '</h4>';
							content += '<p>' + escapeHtml(product.error || 'Unknown error') + '</p>';
							content += '</div>';
						});
					}

					$('#error-details-content').html(content);
					$('#error-details-modal').fadeIn();
				}
			},
		});
	}

	/**
	 * Show error details
	 */
	function showErrorDetails(error) {
		const content = '<div class="wch-error-item"><p>' + escapeHtml(error) + '</p></div>';
		$('#error-details-content').html(content);
		$('#error-details-modal').fadeIn();
	}

	/**
	 * Show success message
	 */
	function showSuccess(message) {
		const notice = $('<div class="notice notice-success is-dismissible"><p>' + escapeHtml(message) + '</p></div>');
		$('.wch-catalog-sync-wrap h1').after(notice);
		setTimeout(function () {
			notice.fadeOut(function () {
				$(this).remove();
			});
		}, 5000);
	}

	/**
	 * Show error message
	 */
	function showError(message) {
		const notice = $('<div class="notice notice-error is-dismissible"><p>' + escapeHtml(message) + '</p></div>');
		$('.wch-catalog-sync-wrap h1').after(notice);
		setTimeout(function () {
			notice.fadeOut(function () {
				$(this).remove();
			});
		}, 5000);
	}

	/**
	 * Escape HTML
	 */
	function escapeHtml(text) {
		const map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;',
		};
		return String(text).replace(/[&<>"']/g, function (m) {
			return map[m];
		});
	}

	// Initialize when document is ready
	$(document).ready(init);

})(jQuery);
