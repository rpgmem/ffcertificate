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

			var progress = document.getElementById('ffc-appointments-export-progress');
			var originalText = btn.textContent;
			btn.disabled = true;
			btn.textContent = s.exportPreparing || 'Preparing…';
			if (progress) { progress.style.display = ''; progress.textContent = ''; }

			var exportingTpl = s.exportProgress || 'Exporting %1$d/%2$d…';
			var total = 0;

			window.FFCBatchedExport.run({
				type: 'appointments',
				ajaxUrl: cfg.ajaxUrl,
				nonce: exportNonce,
				startData: {
					calendar_id: btn.getAttribute('data-calendar_id') || '',
					status:      btn.getAttribute('data-status') || '',
					start_date:  btn.getAttribute('data-start_date') || '',
					end_date:    btn.getAttribute('data-end_date') || ''
				},
				callbacks: {
					onStart: function (t) { total = t; },
					onProgress: function (processed) {
						if (progress) {
							progress.textContent = exportingTpl.replace('%1$d', processed).replace('%2$d', total);
						}
					},
					onComplete: function (downloadUrl, ctx) {
						var iframe = document.createElement('iframe');
						iframe.style.display = 'none';
						iframe.src = downloadUrl;
						document.body.appendChild(iframe);
						setTimeout(function () {
							btn.disabled = false;
							btn.textContent = originalText;
							if (progress) {
								progress.textContent = '✓ ' + ctx.processed + '/' + total + ' — ' + (s.exportDone || 'Done!');
								setTimeout(function () { progress.style.display = 'none'; }, 5000);
							}
							if (iframe.parentNode) { iframe.parentNode.removeChild(iframe); }
						}, 2000);
					},
					onError: function (err) {
						btn.disabled = false;
						btn.textContent = originalText;
						if (progress) {
							progress.textContent = (err && err.fromServer && err.message) || (s.error || 'An error occurred.');
						}
					}
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
