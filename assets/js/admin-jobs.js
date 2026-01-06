/**
 * Admin Jobs UI Scripts
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Auto-refresh pending counts every 30 seconds
		var autoRefreshInterval = setInterval(function() {
			// Only refresh if the page is visible
			if (!document.hidden) {
				// Reload the page to get updated counts
				// In a production environment, this could be replaced with AJAX
				location.reload();
			}
		}, 30000);

		// Clear interval when page is hidden to save resources
		$(window).on('beforeunload', function() {
			clearInterval(autoRefreshInterval);
		});

		// Confirm before triggering cleanup
		$('form[action*="wch_trigger_cart_cleanup"]').on('submit', function(e) {
			if (!confirm('Are you sure you want to trigger cart cleanup now?')) {
				e.preventDefault();
				return false;
			}
		});
	});

})(jQuery);
