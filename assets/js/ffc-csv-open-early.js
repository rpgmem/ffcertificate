/**
 * Public CSV Download — "Start Form Now" (early open).
 *
 * Confirmation modal then ffc_public_open_early. Reads shared state/helpers
 * from window.FFCCsv (ffc-csv-download.js) and registers
 * `api.onOpenEarlyClick`.
 *
 * @since 6.x (split out of ffc-csv-download.js)
 */
(function ($) {
	'use strict';

	var api     = window.FFCCsv;
	var strings = api.strings;
	var esc     = api.esc;

	// Click handler for the .ffc-btn-open-early button. Pops the
	// confirmation modal first — defensive UX since this is irreversible
	// from the operator's phone (admin can still walk it back in the
	// editor, but the operator on stage shouldn't trip it).
	function onOpenEarlyClick() {
		var info = api.lastInfo();
		var origStart = info && info.status ? (info.status.start_date_formatted || '') : '';
		showOpenEarlyModal(origStart);
	}

	function showOpenEarlyModal(origStart) {
		// Clean up any prior modal.
		$('.ffc-open-early-modal').remove();

		var nowDate = new Date();
		var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
		var nowFormatted = pad(nowDate.getDate()) + '/' + pad(nowDate.getMonth() + 1) + '/' + nowDate.getFullYear()
			+ ' ' + pad(nowDate.getHours()) + ':' + pad(nowDate.getMinutes());

		var modalHtml = ''
			+ '<div class="ffc-open-early-modal" role="dialog" aria-modal="true" aria-labelledby="ffc-open-early-title">'
			+   '<div class="ffc-open-early-backdrop"></div>'
			+   '<div class="ffc-open-early-container">'
			+     '<div class="ffc-open-early-header">'
			+       '<h2 id="ffc-open-early-title">'
			+         '<span aria-hidden="true">⚠️</span> '
			+         esc(strings.openEarlyTitle || 'Start form now?')
			+       '</h2>'
			+       '<button type="button" class="ffc-open-early-close" title="' + esc(strings.cancel || 'Cancel') + '">&times;</button>'
			+     '</div>'
			+     '<div class="ffc-open-early-body">'
			+       '<p>' + esc(strings.openEarlyBody1 || 'This will override the scheduled start time. The form will open immediately for all users.') + '</p>'
			+       '<ul class="ffc-open-early-times">'
			+         (origStart
				? '<li>' + esc(strings.openEarlyOrigLabel || 'Scheduled start:') + ' <strong>' + esc(origStart) + '</strong></li>'
				: '')
			+         '<li>' + esc(strings.openEarlyNewLabel || 'New start will be:') + ' <strong>' + esc(strings.openEarlyNewNow || 'now') + ' (' + esc(nowFormatted) + ')</strong></li>'
			+       '</ul>'
			+       '<p class="ffc-open-early-warn"><strong>'
			+         esc(strings.openEarlyIrreversible || 'This cannot be undone from this page.')
			+       '</strong></p>'
			+       '<p class="ffc-open-early-warn-cache">'
			+         esc(strings.openEarlyCacheWarn || 'If your site uses page caching (Cloudflare, W3 Total Cache, etc.), some visitors may see the old "not yet started" state until the cache refreshes. Ask them to reload if needed.')
			+       '</p>'
			+     '</div>'
			+     '<div class="ffc-open-early-actions">'
			+       '<button type="button" class="ffc-info-btn ffc-info-btn-primary ffc-open-early-cancel" autofocus>'
			+         esc(strings.cancel || 'Cancel')
			+       '</button>'
			+       '<button type="button" class="ffc-info-btn ffc-info-btn-warning ffc-open-early-confirm">'
			+         esc(strings.openEarlyConfirm || 'Confirm and start form')
			+       '</button>'
			+     '</div>'
			+   '</div>'
			+ '</div>';

		var $modal = $(modalHtml).appendTo(document.body);
		// Trap Esc to cancel.
		var onKey = function (e) {
			if (e.key === 'Escape') {
				closeModal();
			}
		};
		$(document).on('keydown.ffcOpenEarly', onKey);

		function closeModal() {
			$(document).off('keydown.ffcOpenEarly');
			$modal.remove();
		}

		$modal.find('.ffc-open-early-cancel, .ffc-open-early-close, .ffc-open-early-backdrop').on('click', closeModal);
		$modal.find('.ffc-open-early-confirm').on('click', function () {
			closeModal();
			submitOpenEarly();
		});

		// Ensure the Cancel button has focus on open.
		setTimeout(function () { $modal.find('.ffc-open-early-cancel').focus(); }, 0);
	}

	function submitOpenEarly() {
		var $btnEarly = api.$container.find('.ffc-btn-open-early');
		$btnEarly.prop('disabled', true).text(strings.starting || 'Starting…');

		// Page's existing form carries form_id + hash + nonce; strip its
		// `action` so FFC.request can inject ours.
		var payload = api.$form.serialize().replace(/(^|&)action=[^&]*/, '');

		FFC.request('ffc_public_open_early', payload)
			.then(function (data) {
				window.alert((data && data.message) || (strings.openEarlySuccess || 'Form is now open.'));
				// Full reload — the original form was replaced by the
				// info screen, so re-running the validation flow from
				// scratch is the cleanest way to repaint with the new
				// state (before_start is now false, button is gone).
				window.location.reload();
			})
			.catch(function (err) {
				window.alert((err && err.fromServer && err.message) || strings.error || 'Action failed.');
				$btnEarly.prop('disabled', false).text(strings.startFormNow || 'Start Form Now');
			});
	}

	api.onOpenEarlyClick = onOpenEarlyClick;

})(jQuery);
