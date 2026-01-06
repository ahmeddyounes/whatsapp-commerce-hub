/**
 * WhatsApp Commerce Hub - Order Admin JavaScript
 *
 * Handles admin interactions for WhatsApp orders.
 */

(function($) {
	'use strict';

	const WCHOrderAdmin = {
		/**
		 * Initialize the module.
		 */
		init: function() {
			this.bindEvents();
		},

		/**
		 * Bind event listeners.
		 */
		bindEvents: function() {
			// Quick reply button click
			$(document).on('click', '.wch-quick-reply', this.openQuickReplyModal);

			// Close modal
			$(document).on('click', '.wch-close-modal', this.closeQuickReplyModal);

			// Send message
			$(document).on('click', '.wch-send-message', this.sendQuickMessage);

			// Save tracking info
			$(document).on('click', '.wch-save-tracking', this.saveTrackingInfo);

			// Close modal on overlay click
			$(document).on('click', '.wch-modal-overlay', function(e) {
				if (e.target === this) {
					WCHOrderAdmin.closeQuickReplyModal();
				}
			});

			// Close modal on Escape key
			$(document).on('keyup', function(e) {
				if (e.key === 'Escape') {
					WCHOrderAdmin.closeQuickReplyModal();
				}
			});
		},

		/**
		 * Open quick reply modal.
		 */
		openQuickReplyModal: function(e) {
			e.preventDefault();
			const $button = $(this);
			const phone = $button.data('phone');
			const orderId = $button.data('order-id');

			// Store data in modal
			const $modal = $('#wch-quick-reply-modal');
			$modal.data('phone', phone);
			$modal.data('order-id', orderId);

			// Clear previous message
			$('#wch-quick-message').val('');

			// Show modal
			$modal.show();
		},

		/**
		 * Close quick reply modal.
		 */
		closeQuickReplyModal: function() {
			$('#wch-quick-reply-modal').hide();
		},

		/**
		 * Send quick message.
		 */
		sendQuickMessage: function(e) {
			e.preventDefault();
			const $modal = $('#wch-quick-reply-modal');
			const phone = $modal.data('phone');
			const orderId = $modal.data('order-id');
			const message = $('#wch-quick-message').val();

			if (!message.trim()) {
				alert('Please enter a message');
				return;
			}

			const $button = $(this);
			$button.prop('disabled', true).text('Sending...');

			$.ajax({
				url: wchOrderAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wch_send_quick_message',
					nonce: wchOrderAdmin.nonce,
					phone: phone,
					order_id: orderId,
					message: message
				},
				success: function(response) {
					if (response.success) {
						alert('Message sent successfully');
						WCHOrderAdmin.closeQuickReplyModal();
					} else {
						alert('Failed to send message: ' + (response.data || 'Unknown error'));
					}
				},
				error: function() {
					alert('Failed to send message');
				},
				complete: function() {
					$button.prop('disabled', false).text('Send');
				}
			});
		},

		/**
		 * Save tracking information.
		 */
		saveTrackingInfo: function(e) {
			e.preventDefault();
			const $button = $(this);
			const orderId = $button.data('order-id');
			const trackingNumber = $('#wch_tracking_number').val();
			const carrier = $('#wch_carrier').val();

			if (!trackingNumber || !carrier) {
				alert('Please enter both tracking number and carrier');
				return;
			}

			$button.prop('disabled', true).text('Saving...');

			$.ajax({
				url: wchOrderAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wch_save_tracking_info',
					nonce: wchOrderAdmin.nonce,
					order_id: orderId,
					tracking_number: trackingNumber,
					carrier: carrier
				},
				success: function(response) {
					if (response.success) {
						alert('Tracking information saved successfully');
						// Add order note to the order notes section if it exists
						if (response.data && response.data.note) {
							location.reload();
						}
					} else {
						alert('Failed to save tracking information: ' + (response.data || 'Unknown error'));
					}
				},
				error: function() {
					alert('Failed to save tracking information');
				},
				complete: function() {
					$button.prop('disabled', false).text('Save Tracking Info');
				}
			});
		}
	};

	// Initialize on document ready
	$(document).ready(function() {
		WCHOrderAdmin.init();
	});

})(jQuery);
