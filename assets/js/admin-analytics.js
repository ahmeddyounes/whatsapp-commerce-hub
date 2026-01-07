/**
 * WhatsApp Commerce Hub - Analytics Dashboard JavaScript
 */

(function ($) {
	'use strict';

	const WCHAnalytics = {
		charts: {},
		currentPeriod: 'week',
		refreshInterval: null,

		init: function () {
			this.bindEvents();
			this.loadAllData();
			this.startAutoRefresh();
		},

		bindEvents: function () {
			$('#wch-period-select').on('change', this.handlePeriodChange.bind(this));
			$('#wch-refresh-analytics').on('click', this.handleRefresh.bind(this));
			$('#wch-export-analytics').on('click', this.handleExport.bind(this));
		},

		handlePeriodChange: function (e) {
			this.currentPeriod = $(e.target).val();
			this.loadAllData();
		},

		handleRefresh: function (e) {
			e.preventDefault();
			const $btn = $(e.target);
			$btn.prop('disabled', true).text(wchAnalytics.strings.loading);

			$.ajax({
				url: wchAnalytics.ajax_url,
				type: 'POST',
				data: {
					action: 'wch_refresh_analytics',
					nonce: wchAnalytics.nonce,
				},
				success: () => {
					this.loadAllData();
					$btn.prop('disabled', false).text('Refresh');
				},
				error: () => {
					alert(wchAnalytics.strings.error);
					$btn.prop('disabled', false).text('Refresh');
				},
			});
		},

		handleExport: function (e) {
			e.preventDefault();
			const activeTab = $('.wch-analytics-tab').data('tab') || 'overview';
			let exportType = 'orders';

			switch (activeTab) {
				case 'orders':
					exportType = 'orders';
					break;
				case 'conversations':
					exportType = 'metrics';
					break;
				case 'customers':
					exportType = 'funnel';
					break;
				case 'products':
					exportType = 'products';
					break;
				default:
					exportType = 'orders';
			}

			const days = this.getPeriodDays();

			$.ajax({
				url: wchAnalytics.rest_url + 'export',
				type: 'POST',
				beforeSend: function (xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wchAnalytics.nonce);
				},
				data: JSON.stringify({
					type: exportType,
					days: days,
				}),
				contentType: 'application/json',
				success: (response) => {
					if (response.success && response.data.file_url) {
						window.location.href = response.data.file_url;
						alert(wchAnalytics.strings.export_success);
					} else {
						alert(wchAnalytics.strings.export_error);
					}
				},
				error: () => {
					alert(wchAnalytics.strings.export_error);
				},
			});
		},

		loadAllData: function () {
			const activeTab = $('.wch-analytics-tab').data('tab');

			switch (activeTab) {
				case 'overview':
					this.loadOverviewData();
					break;
				case 'orders':
					this.loadOrdersData();
					break;
				case 'conversations':
					this.loadConversationsData();
					break;
				case 'customers':
					this.loadCustomersData();
					break;
				case 'products':
					this.loadProductsData();
					break;
				default:
					this.loadOverviewData();
			}
		},

		loadOverviewData: function () {
			this.loadSummary();
			this.loadOrdersChart();
			this.loadRevenueChart();
			this.loadFunnelChart();
		},

		loadOrdersData: function () {
			this.loadDetailedMetrics();
			this.loadOrdersRevenueChart();
		},

		loadConversationsData: function () {
			this.loadDetailedMetrics();
			this.loadConversationHeatmap();
		},

		loadCustomersData: function () {
			this.loadCustomerInsights();
			this.loadCustomerSplitChart();
			this.loadTopCustomers();
		},

		loadProductsData: function () {
			this.loadTopProductsChart();
		},

		loadSummary: function () {
			$.get(wchAnalytics.rest_url + 'summary', { period: this.currentPeriod }, (response) => {
				if (response.success && response.data) {
					const data = response.data;
					$('[data-metric="total_orders"]').text(data.total_orders || 0);
					$('[data-metric="total_revenue"]').text(
						wchAnalytics.currency + this.formatNumber(data.total_revenue || 0, 2)
					);
					$('[data-metric="active_conversations"]').text(data.active_conversations || 0);
					$('[data-metric="conversion_rate"]').text(
						this.formatNumber(data.conversion_rate || 0, 1) + '%'
					);
				}
			});
		},

		loadOrdersChart: function () {
			const days = this.getPeriodDays();

			$.get(wchAnalytics.rest_url + 'orders', { days: days }, (response) => {
				if (response.success && response.data) {
					const data = response.data;
					const labels = Object.keys(data);
					const values = Object.values(data);

					this.renderLineChart('chart-orders-over-time', {
						labels: labels,
						datasets: [
							{
								label: 'Orders',
								data: values,
								borderColor: '#2271b1',
								backgroundColor: 'rgba(34, 113, 177, 0.1)',
								tension: 0.4,
								fill: true,
							},
						],
					});
				}
			});
		},

		loadRevenueChart: function () {
			const days = this.getPeriodDays();

			$.get(wchAnalytics.rest_url + 'revenue', { days: days }, (response) => {
				if (response.success && response.data) {
					const data = response.data;
					const labels = Object.keys(data);
					const values = Object.values(data);

					this.renderBarChart('chart-revenue-by-day', {
						labels: labels,
						datasets: [
							{
								label: 'Revenue (' + wchAnalytics.currency + ')',
								data: values,
								backgroundColor: '#28a745',
								borderColor: '#28a745',
								borderWidth: 1,
							},
						],
					});
				}
			});
		},

		loadFunnelChart: function () {
			const days = this.getPeriodDays();

			$.get(wchAnalytics.rest_url + 'funnel', { days: days }, (response) => {
				if (response.success && response.data) {
					const data = response.data;

					this.renderBarChart('chart-funnel', {
						labels: [
							'Conversations Started',
							'Product Viewed',
							'Added to Cart',
							'Checkout Started',
							'Order Completed',
						],
						datasets: [
							{
								label: 'Count',
								data: [
									data.conversations_started || 0,
									data.product_viewed || 0,
									data.added_to_cart || 0,
									data.checkout_started || 0,
									data.order_completed || 0,
								],
								backgroundColor: [
									'rgba(34, 113, 177, 0.8)',
									'rgba(40, 167, 69, 0.8)',
									'rgba(255, 193, 7, 0.8)',
									'rgba(220, 53, 69, 0.8)',
									'rgba(23, 162, 184, 0.8)',
								],
							},
						],
					});
				}
			});
		},

		loadDetailedMetrics: function () {
			const days = this.getPeriodDays();

			$.get(wchAnalytics.rest_url + 'metrics', { days: days }, (response) => {
				if (response.success && response.data) {
					const data = response.data;
					$('[data-metric="avg_order_value"]').text(
						wchAnalytics.currency + this.formatNumber(data.avg_order_value || 0, 2)
					);
					$('[data-metric="avg_order_value_web"]').text(
						wchAnalytics.currency + this.formatNumber(data.avg_order_value_web || 0, 2)
					);
					$('[data-metric="cart_abandonment_rate"]').text(
						this.formatNumber(data.cart_abandonment_rate || 0, 1) + '%'
					);
					$('[data-metric="avg_response_time"]').text(
						this.formatNumber(data.avg_response_time || 0, 0)
					);
					$('[data-metric="message_volume_inbound"]').text(data.message_volume_inbound || 0);
					$('[data-metric="message_volume_outbound"]').text(data.message_volume_outbound || 0);
				}
			});
		},

		loadOrdersRevenueChart: function () {
			const days = this.getPeriodDays();

			Promise.all([
				$.get(wchAnalytics.rest_url + 'orders', { days: days }),
				$.get(wchAnalytics.rest_url + 'revenue', { days: days }),
			]).then(([ordersResponse, revenueResponse]) => {
				if (ordersResponse.success && revenueResponse.success) {
					const labels = Object.keys(ordersResponse.data);

					this.renderLineChart('chart-orders-revenue-trend', {
						labels: labels,
						datasets: [
							{
								label: 'Orders',
								data: Object.values(ordersResponse.data),
								borderColor: '#2271b1',
								backgroundColor: 'rgba(34, 113, 177, 0.1)',
								yAxisID: 'y',
							},
							{
								label: 'Revenue (' + wchAnalytics.currency + ')',
								data: Object.values(revenueResponse.data),
								borderColor: '#28a745',
								backgroundColor: 'rgba(40, 167, 69, 0.1)',
								yAxisID: 'y1',
							},
						],
					}, {
						y: { type: 'linear', display: true, position: 'left' },
						y1: { type: 'linear', display: true, position: 'right', grid: { drawOnChartArea: false } },
					});
				}
			});
		},

		loadConversationHeatmap: function () {
			const days = 7;

			$.get(wchAnalytics.rest_url + 'conversations', { days: days }, (response) => {
				if (response.success && response.data) {
					this.renderHeatmap('heatmap-conversations', response.data);
				}
			});
		},

		loadCustomerInsights: function () {
			const days = this.getPeriodDays();

			$.get(wchAnalytics.rest_url + 'customers', { days: days }, (response) => {
				if (response.success && response.data) {
					const data = response.data;
					$('[data-metric="new_customers"]').text(data.new_customers || 0);
					$('[data-metric="returning_customers"]').text(data.returning_customers || 0);
				}
			});
		},

		loadCustomerSplitChart: function () {
			const days = this.getPeriodDays();

			$.get(wchAnalytics.rest_url + 'customers', { days: days }, (response) => {
				if (response.success && response.data) {
					const data = response.data;

					this.renderPieChart('chart-customer-split', {
						labels: ['New Customers', 'Returning Customers'],
						datasets: [
							{
								data: [data.new_customers || 0, data.returning_customers || 0],
								backgroundColor: ['#2271b1', '#28a745'],
							},
						],
					});
				}
			});
		},

		loadTopCustomers: function () {
			const days = this.getPeriodDays();

			$.get(wchAnalytics.rest_url + 'customers', { days: days }, (response) => {
				if (response.success && response.data && response.data.top_customers) {
					const customers = response.data.top_customers;
					let html = '<table class="wch-table"><thead><tr>';
					html += '<th>Customer</th>';
					html += '<th>Phone</th>';
					html += '<th>Orders</th>';
					html += '<th>Total Value</th>';
					html += '</tr></thead><tbody>';

					customers.forEach((customer) => {
						html += '<tr>';
						html += '<td>' + (customer.name || 'N/A') + '</td>';
						html += '<td>' + customer.phone + '</td>';
						html += '<td>' + customer.order_count + '</td>';
						html +=
							'<td>' + wchAnalytics.currency + this.formatNumber(customer.total_value, 2) + '</td>';
						html += '</tr>';
					});

					html += '</tbody></table>';
					$('#top-customers-list').html(html);
				}
			});
		},

		loadTopProductsChart: function () {
			const days = this.getPeriodDays();

			$.get(wchAnalytics.rest_url + 'products', { days: days, limit: 10 }, (response) => {
				if (response.success && response.data) {
					const products = response.data;
					const labels = products.map((p) => p.product_name);
					const values = products.map((p) => parseInt(p.quantity));

					this.renderBarChart(
						'chart-top-products',
						{
							labels: labels,
							datasets: [
								{
									label: 'Quantity Sold',
									data: values,
									backgroundColor: '#2271b1',
								},
							],
						},
						{ indexAxis: 'y' }
					);
				}
			});
		},

		renderLineChart: function (canvasId, data, extraScales = {}) {
			const ctx = document.getElementById(canvasId);
			if (!ctx) return;

			if (this.charts[canvasId]) {
				this.charts[canvasId].destroy();
			}

			this.charts[canvasId] = new Chart(ctx, {
				type: 'line',
				data: data,
				options: {
					responsive: true,
					maintainAspectRatio: false,
					scales: {
						...extraScales,
						x: extraScales.x || { display: true },
						y: extraScales.y || { beginAtZero: true },
					},
					plugins: {
						legend: {
							display: true,
							position: 'top',
						},
					},
				},
			});
		},

		renderBarChart: function (canvasId, data, extraOptions = {}) {
			const ctx = document.getElementById(canvasId);
			if (!ctx) return;

			if (this.charts[canvasId]) {
				this.charts[canvasId].destroy();
			}

			this.charts[canvasId] = new Chart(ctx, {
				type: 'bar',
				data: data,
				options: {
					...extraOptions,
					responsive: true,
					maintainAspectRatio: false,
					scales: {
						y: {
							beginAtZero: true,
						},
					},
					plugins: {
						legend: {
							display: true,
							position: 'top',
						},
					},
				},
			});
		},

		renderPieChart: function (canvasId, data) {
			const ctx = document.getElementById(canvasId);
			if (!ctx) return;

			if (this.charts[canvasId]) {
				this.charts[canvasId].destroy();
			}

			this.charts[canvasId] = new Chart(ctx, {
				type: 'pie',
				data: data,
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							display: true,
							position: 'right',
						},
					},
				},
			});
		},

		renderHeatmap: function (containerId, data) {
			const $container = $('#' + containerId);
			if (!$container.length) return;

			const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
			let html = '<div class="wch-heatmap-grid">';

			html += '<div class="wch-heatmap-cell header"></div>';
			for (let hour = 0; hour < 24; hour++) {
				html += '<div class="wch-heatmap-cell header">' + hour + '</div>';
			}

			for (let day = 1; day <= 7; day++) {
				html += '<div class="wch-heatmap-cell header">' + days[day - 1] + '</div>';
				for (let hour = 0; hour < 24; hour++) {
					const value = data[day] && data[day][hour] ? data[day][hour] : 0;
					const heatLevel = this.getHeatLevel(value);
					html += '<div class="wch-heatmap-cell data heat-' + heatLevel + '">' + value + '</div>';
				}
			}

			html += '</div>';
			$container.html(html);
		},

		getHeatLevel: function (value) {
			if (value === 0) return 0;
			if (value <= 5) return 1;
			if (value <= 10) return 2;
			if (value <= 20) return 3;
			if (value <= 50) return 4;
			return 5;
		},

		getPeriodDays: function () {
			switch (this.currentPeriod) {
				case 'today':
					return 1;
				case 'week':
					return 7;
				case 'month':
					return 30;
				default:
					return 7;
			}
		},

		formatNumber: function (value, decimals = 0) {
			return parseFloat(value).toFixed(decimals);
		},

		startAutoRefresh: function () {
			this.refreshInterval = setInterval(() => {
				this.loadAllData();
			}, 300000); // 5 minutes
		},

		stopAutoRefresh: function () {
			if (this.refreshInterval) {
				clearInterval(this.refreshInterval);
			}
		},
	};

	$(document).ready(function () {
		if ($('.wch-analytics-wrap').length) {
			WCHAnalytics.init();
		}
	});
})(jQuery);
