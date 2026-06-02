/**
 * Public CSV Download — "Postpone close" (extend-end).
 *
 * Time-picker modal with client-side validation then ffc_public_extend_end.
 * Reads shared state/helpers from window.FFCCsv (ffc-csv-download.js) and
 * registers `api.onExtendEndClick`.
 *
 * @since 6.x (split out of ffc-csv-download.js)
 */
(function ($) {
	'use strict';

	var api     = window.FFCCsv;
	var strings = api.strings;
	var esc     = api.esc;

	function onExtendEndClick() {
		var info = api.lastInfo();
		// Compose the "current scheduled close" display from date-only +
		// raw 24h time, so the modal always shows HH:MM regardless of the
		// site's `time_format` setting (#243 Sprint 3 — fixes AM/PM display
		// when site uses `g:i a`). Falls back to `end_date_formatted` for
		// pre-#243 backends that don't return `current_date_end_formatted`.
		var dateOnly    = info && info.status ? (info.status.current_date_end_formatted || '') : '';
		var currentTime = info && info.status ? (info.status.current_time_end || '') : '';
		var currentEnd  = (dateOnly && currentTime)
			? (dateOnly + ' ' + currentTime)
			: (info && info.status ? (info.status.end_date_formatted || '') : '');
		showExtendEndModal(currentEnd, currentTime);
	}

	function showExtendEndModal(currentEnd, currentTime) {
		// Clean up any prior modal.
		$('.ffc-extend-end-modal').remove();

		// Default the time picker to current_time_end + 30 min, clamped
		// to 23:59. The user can edit freely; server validates.
		var defaultNew = currentTime;
		if (currentTime && /^\d{2}:\d{2}$/.test(currentTime)) {
			var parts = currentTime.split(':');
			var mins  = parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10) + 30;
			if (mins > 23 * 60 + 59) { mins = 23 * 60 + 59; }
			var hh = Math.floor(mins / 60);
			var mm = mins % 60;
			defaultNew = (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm;
		}

		var modalHtml = ''
			+ '<div class="ffc-extend-end-modal ffc-open-early-modal" role="dialog" aria-modal="true" aria-labelledby="ffc-extend-end-title">'
			+   '<div class="ffc-open-early-backdrop"></div>'
			+   '<div class="ffc-open-early-container">'
			+     '<div class="ffc-open-early-header">'
			+       '<h2 id="ffc-extend-end-title">'
			+         '<span aria-hidden="true">⏰</span> '
			+         esc(strings.postponeCloseTitle || 'Postpone form close?')
			+       '</h2>'
			+       '<button type="button" class="ffc-open-early-close ffc-extend-end-close" title="' + esc(strings.cancel || 'Cancel') + '">&times;</button>'
			+     '</div>'
			+     '<div class="ffc-open-early-body">'
			+       '<p>' + esc(strings.postponeCloseBody || 'Pick a new close time within the same day. This action is one-shot — once confirmed it cannot be repeated from this page.') + '</p>'
			+       '<ul class="ffc-open-early-times">'
			+         (currentEnd
				? '<li>' + esc(strings.postponeCurrentLabel || 'Current scheduled close:') + ' <strong>' + esc(currentEnd) + '</strong></li>'
				: '')
			+       '</ul>'
			+       '<p>'
			+         '<label for="ffc-extend-end-input"><strong>' + esc(strings.postponeNewLabel || 'New close time:') + '</strong></label> '
			+         '<input type="time" id="ffc-extend-end-input" class="ffc-extend-end-input" value="' + esc(defaultNew) + '" '
			+         (currentTime ? 'min="' + esc(currentTime) + '" ' : '')
			+         'max="23:59" required>'
			+       '</p>'
			+       '<p class="ffc-open-early-warn"><strong>'
			+         esc(strings.postponeIrreversible || 'This action can only be performed once per form.')
			+       '</strong></p>'
			+       '<p class="ffc-open-early-warn-cache">'
			+         esc(strings.openEarlyCacheWarn || 'If your site uses page caching, some visitors may see the old close time until the cache refreshes.')
			+       '</p>'
			+     '</div>'
			+     '<div class="ffc-open-early-actions">'
			+       '<button type="button" class="ffc-info-btn ffc-info-btn-primary ffc-extend-end-cancel" autofocus>'
			+         esc(strings.cancel || 'Cancel')
			+       '</button>'
			+       '<button type="button" class="ffc-info-btn ffc-info-btn-warning ffc-extend-end-confirm">'
			+         esc(strings.postponeConfirm || 'Confirm postponement')
			+       '</button>'
			+     '</div>'
			+   '</div>'
			+ '</div>';

		var $modal = $(modalHtml).appendTo(document.body);
		var onKey = function (e) {
			if (e.key === 'Escape') { closeModal(); }
		};
		$(document).on('keydown.ffcExtendEnd', onKey);

		function closeModal() {
			$(document).off('keydown.ffcExtendEnd');
			$modal.remove();
		}

		// Client-side validation surface: an inline error chip + red border
		// on the time input. Server is the authority — these checks are
		// pure UX so the operator gets feedback before submit (#243 S3).
		var $input    = $modal.find('.ffc-extend-end-input');
		var $errorMsg = $('<p class="ffc-extend-end-error" role="alert" style="color:#d63638;margin:6px 0 0;font-size:13px;" hidden></p>');
		$input.closest('p').append($errorMsg);

		function setError(msg) {
			$input.addClass('ffc-extend-end-input-invalid').css('border-color', '#d63638');
			$errorMsg.text(msg).removeAttr('hidden');
		}
		function clearError() {
			$input.removeClass('ffc-extend-end-input-invalid').css('border-color', '');
			$errorMsg.attr('hidden', 'hidden').text('');
		}
		// Clear the error indication on every keystroke / picker change so the
		// user sees their correction acknowledged immediately.
		$input.on('input change', clearError);

		// Validate the user-supplied HH:MM string against the existing
		// current_time_end and "now" — returns null when OK, or the localized
		// error string when invalid. Mirrors the server-side validation tags
		// in `ExtendEndAction::validate_new_time_end()` for UX parity.
		function validateInput(value) {
			if (!value || !/^([01]\d|2[0-3]):[0-5]\d$/.test(value)) {
				return strings.postponeInvalidFormat
					|| strings.postponeInvalid
					|| 'Please enter a valid time (HH:MM).';
			}
			if (currentTime && value <= currentTime) {
				return (strings.postponeBeforeCurrent || 'Time must be later than the current close (%s).').replace('%s', currentTime);
			}
			// "Now" comparison uses the JS clock — the server clock can drift
			// slightly but the message is still accurate enough for UX.
			var now    = new Date();
			var nowStr = (now.getHours() < 10 ? '0' : '') + now.getHours()
				+ ':' + (now.getMinutes() < 10 ? '0' : '') + now.getMinutes();
			if (value <= nowStr) {
				return strings.postponeBeforeNow || 'Time must be in the future.';
			}
			return null;
		}

		$modal.find('.ffc-extend-end-cancel, .ffc-extend-end-close, .ffc-open-early-backdrop').on('click', closeModal);
		$modal.find('.ffc-extend-end-confirm').on('click', function () {
			var newTime = $input.val();
			var err     = validateInput(newTime);
			if (err) {
				setError(err);
				$input.focus();
				return;
			}
			closeModal();
			submitExtendEnd(newTime);
		});

		setTimeout(function () { $modal.find('.ffc-extend-end-cancel').focus(); }, 0);
	}

	function submitExtendEnd(newTime) {
		var $btn = api.$container.find('.ffc-btn-extend-end');
		$btn.prop('disabled', true).text(strings.postponing || 'Postponing…');

		var payload = api.$form.serialize().replace(/(^|&)action=[^&]*/, '')
			+ '&new_time_end=' + encodeURIComponent(newTime);

		FFC.request('ffc_public_extend_end', payload)
			.then(function (data) {
				window.alert((data && data.message) || (strings.postponeSuccess || 'Close time postponed.'));
				window.location.reload();
			})
			.catch(function (err) {
				window.alert((err && err.fromServer && err.message) || strings.error || 'Action failed.');
				$btn.prop('disabled', false).text(strings.postponeClose || 'Postpone close');
			});
	}

	api.onExtendEndClick = onExtendEndClick;

})(jQuery);
