/**
 * Self-scheduling admin appointments list — row "Cancel" action.
 *
 * Extracted from an inline onclick in views/appointments-list.php. Each
 * `.ffc-appointment-cancel` link prompts for a cancellation reason (min 5
 * chars) and, when given, redirects to its nonce-signed `data-cancel-url`
 * with the reason appended. The prompt text and URL travel on data-*
 * attributes so the view carries no inline JS.
 */
(function () {
	'use strict';

	function onCancelClick(e) {
		e.preventDefault();
		var url    = this.getAttribute('data-cancel-url');
		var prompt = this.getAttribute('data-prompt') || '';
		var reason = window.prompt(prompt);
		if (reason && reason.length >= 5 && url) {
			window.location = url + '&reason=' + encodeURIComponent(reason);
		}
		return false;
	}

	function init() {
		var links = document.querySelectorAll('.ffc-appointment-cancel');
		Array.prototype.forEach.call(links, function (link) {
			link.addEventListener('click', onCancelClick);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
