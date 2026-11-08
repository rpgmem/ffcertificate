/**
 * Self-scheduling admin appointments list — row "Cancel" action, "Delete
 * permanently" (row and bulk, asked through the plugin's dialog) and the
 * batched CSV export.
 *
 * The `.ffc-appointment-cancel` links were extracted from an inline onclick in
 * views/appointments-list.php: each asks for a cancellation reason (min 5
 * chars) in the plugin's dialog and, when given, redirects to its nonce-signed
 * `data-cancel-url` with the reason appended.
 *
 * The Export CSV button (#ffc-appointments-export-btn) drives the shared
 * window.FFCBatchedExport engine (#772) through the unified `ffc_export_*`
 * dispatcher, carrying the current calendar/status filters via data-*. Export
 * order is id-DESC (a stable keyset), not the on-screen sort.
 */
(function () {
	'use strict';

	// The reason is asked in the plugin's dialog (#2535): a labelled field
	// whose Confirm stays off until it holds five characters, so a short
	// reason can no longer be sent and refused (#2244).
	function onCancelClick(e) {
		e.preventDefault();
		var url = this.getAttribute('data-cancel-url');
		if (!url || !window.FFC || typeof window.FFC.confirmDialog !== 'function') {
			return false;
		}
		var link = this;
		window.FFC.confirmDialog({
			message: link.getAttribute('data-question') || '',
			title: link.getAttribute('data-title') || '',
			confirmLabel: link.getAttribute('data-confirm-label') || '',
			category: 'caution',
			icon: 'cross',
			reasonField: {
				label: link.getAttribute('data-prompt') || '',
				hint: link.getAttribute('data-hint') || '',
				min: 5
			}
		}).then(function (reason) {
			if (typeof reason === 'string' && reason.length >= 5) {
				window.location = url + '&reason=' + encodeURIComponent(reason);
			}
		});
		return false;
	}

	// Fill `%d` in order.
	function fill(text, n) {
		return String(text || '').replace('%d', String(n));
	}

	// Delete permanently asks first through the plugin's dialog (#2340): one
	// row's link, or the bulk select's Apply with that action chosen.
	function confirmDelete(count) {
		var d = (window.ffcAppointmentsExport || {}).deleteDialog || {};
		if (!window.FFC || typeof window.FFC.overrideConfirm !== 'function') {
			return Promise.resolve(false);
		}
		return window.FFC.overrideConfirm({
			strings: {
				seconds: 0,
				title: fill(d.title, count),
				intro: d.intro || '',
				logged: d.logged || '',
				agree: fill(d.agree, count),
				cancel: d.cancel || 'Cancel'
			},
			reasons: [],
			agreeCategory: 'danger',
			agreeIcon: 'delete'
		}).then(function (agreed) { return !!agreed; }, function () { return false; });
	}

	function onDeleteClick(e) {
		e.preventDefault();
		var url = this.getAttribute('href');
		confirmDelete(1).then(function (agreed) {
			if (agreed && url) { window.location = url; }
		});
		return false;
	}

	function bindBulkDelete() {
		['doaction', 'doaction2'].forEach(function (id) {
			var btn = document.getElementById(id);
			if (!btn) { return; }
			btn.addEventListener('click', function (e) {
				var select = document.getElementById(id === 'doaction' ? 'bulk-action-selector-top' : 'bulk-action-selector-bottom');
				if (!select || select.value !== 'bulk_delete_permanently') { return; }
				e.preventDefault();
				var form = btn.form;
				var count = form ? form.querySelectorAll('input[name="appointment[]"]:checked').length : 0;
				if (count === 0) {
					var d = (window.ffcAppointmentsExport || {}).deleteDialog || {};
					if (window.FFC && typeof window.FFC.adminNotice === 'function') {
						window.FFC.adminNotice('warning', d.none || '');
					}
					return;
				}
				confirmDelete(count).then(function (agreed) {
					// The select already carries the action; submitting the
					// form sends it, as Apply would have.
					if (agreed && form) { form.submit(); }
				});
			});
		});
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
				// Only the two filters the list offers (#1770): it has no date
				// filter, so the button carries no date attributes to read.
				startData: {
					calendar_id: btn.getAttribute('data-calendar_id') || '',
					status:      btn.getAttribute('data-status') || '',
					start_date:  btn.getAttribute('data-start_date') || '',
					end_date:    btn.getAttribute('data-end_date') || '',
					s:           btn.getAttribute('data-s') || ''
				}
			});
		});
	}

	function init() {
		var links = document.querySelectorAll('.ffc-appointment-cancel');
		Array.prototype.forEach.call(links, function (link) {
			link.addEventListener('click', onCancelClick);
		});
		Array.prototype.forEach.call(document.querySelectorAll('.ffc-appointment-delete'), function (link) {
			link.addEventListener('click', onDeleteClick);
		});
		bindBulkDelete();
		bindExport();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
