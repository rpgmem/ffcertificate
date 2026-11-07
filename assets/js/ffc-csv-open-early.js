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
		$('.ffc-csv-dialog-host').remove();

		// The site's date and time in the plugin's formats, not the
		// browser's clock and a fixed dd/mm/yyyy (#2341).
		var siteNow      = api.siteNow();
		var nowFormatted = (siteNow.today ? siteNow.today + ' ' : '') + api.formatTime(siteNow.hm);

		var modalHtml = ''
			+ '<div class="ffc-open-early-modal" role="dialog" aria-modal="true" aria-labelledby="ffc-open-early-title">'
			+   '<div class="ffc-open-early-backdrop"></div>'
			+   '<div class="ffc-open-early-container">'
			+     '<div class="ffc-open-early-header">'
			+       '<h2 id="ffc-open-early-title">'
			+         '<span class="ffc-icon-warning" aria-hidden="true"></span>'
			+         esc(strings.openEarlyTitle || 'Start form now?')
			+       '</h2>'
			+       FFC.buttonHtml({ label: strings.close || 'Close', category: 'neutral', icon: 'cross', iconOnly: true, className: 'ffc-open-early-close' })
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
			// Cancel is neutral and takes the focus; Confirm is caution,
			// because it moves the start the participants see (#1881).
			+     '<div class="ffc-csv-action-security"></div>'
			+     '<div class="ffc-open-early-actions">'
			+       FFC.buttonHtml({ label: strings.cancel || 'Cancel', category: 'neutral', icon: 'cross', className: 'ffc-open-early-cancel', attrs: { autofocus: true } })
			+       FFC.buttonHtml({ label: strings.openEarlyConfirm || 'Confirm and start form', category: 'caution', icon: 'clock', className: 'ffc-open-early-confirm' })
			+     '</div>'
			+   '</div>'
			+ '</div>';

		var $modal = $(modalHtml);
		var close  = api.openDialog($modal);
		api.mountActionSecurity($modal.find('.ffc-csv-action-security'));
		// Trap Esc to cancel.
		var onKey = function (e) {
			if (e.key === 'Escape') {
				closeModal();
			}
		};
		$(document).on('keydown.ffcOpenEarly', onKey);

		function closeModal() {
			$(document).off('keydown.ffcOpenEarly');
			close();
		}

		$modal.find('.ffc-open-early-cancel, .ffc-open-early-close, .ffc-open-early-backdrop').on('click', closeModal);
		$modal.find('.ffc-open-early-confirm').on('click', function () {
			var payload = api.actionPayload($modal);
			closeModal();
			submitOpenEarly(payload);
		});

		// Ensure the Cancel button has focus on open.
		setTimeout(function () { $modal.find('.ffc-open-early-cancel').focus(); }, 0);
	}

	function submitOpenEarly(payload) {
		var $btnEarly = api.$container.find('.ffc-btn-open-early');
		// Busy is a state, not a label (#2341): the button keeps its text and icon.
		$btnEarly.prop('disabled', true).addClass('ffc-btn-loading').attr('aria-busy', 'true');


		FFC.request('ffc_public_open_early', payload)
			.then(function (data) {
				// The challenge is spent; the next action needs the fresh
				// one the answer carries (#2341).
				api.refreshActionSecurity({ data: data });
				// The screen is drawn again with the state the action left,
				// and says so on the page (#2341).
				return api.reportResult('success', (data && data.message) || (strings.openEarlySuccess || 'Form is now open.'), true);
			})
			.catch(function (err) {
				api.refreshActionSecurity(err);
				$btnEarly.prop('disabled', false).removeClass('ffc-btn-loading').removeAttr('aria-busy');
				return api.reportResult('error', (err && err.fromServer && err.message) || strings.error || 'Action failed.', false);
			});
	}

	api.onOpenEarlyClick = onOpenEarlyClick;

})(jQuery);
