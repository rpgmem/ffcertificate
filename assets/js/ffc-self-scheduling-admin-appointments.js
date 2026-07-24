/**
 * Self-scheduling admin appointments list — row "Cancel" action + batched
 * CSV export.
 *
 * The `.ffc-appointment-cancel` links were extracted from an inline onclick in
 * views/appointments-list.php: each prompts for a cancellation reason (min 5
 * chars) and, when given, redirects to its nonce-signed `data-cancel-url` with
 * the reason appended.
 *
 * The Export CSV button (#ffc-appointments-export-btn) drives the shared
 * window.FFCBatchedExport engine (#772) through the unified `ffc_export_*`
 * dispatcher, carrying the current calendar/status filters via data-*. Export
 * order is id-DESC (a stable keyset), not the on-screen sort.
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

	function bindExport() {
		var btn = document.getElementById('ffc-appointments-export-btn');
		if (!btn) { return; }

		btn.addEventListener('click', function () {
			if (!window.FFCBatchedExport) { return; }
			var cfg = window.ffcAppointmentsExport || {};
			var s = cfg.strings || {};
			var exportNonce = cfg.exportNonce || '';
			if (!exportNonce) { return; }

			// Progress is shown through the shared FFCProgressOverlay modal,
			// driven by the driver itself (overlay: true) — same UI as the
			// public download (#786). The raw button element is accepted by the
			// driver (it wraps it in jQuery).
			window.FFCBatchedExport.run({
				type: 'appointments',
				ajaxUrl: cfg.ajaxUrl,
				nonce: exportNonce,
				button: btn,
				overlay: true,
				strings: {
					preparing: s.exportPreparing,
					exporting: s.exportProgress,
					downloading: s.exportDone,
					error: s.error
				},
				startData: {
					calendar_id: btn.getAttribute('data-calendar_id') || '',
					status:      btn.getAttribute('data-status') || '',
					start_date:  btn.getAttribute('data-start_date') || '',
					end_date:    btn.getAttribute('data-end_date') || ''
				}
			});
		});
	}

	function init() {
		var links = document.querySelectorAll('.ffc-appointment-cancel');
		Array.prototype.forEach.call(links, function (link) {
			link.addEventListener('click', onCancelClick);
		});
		bindExport();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
