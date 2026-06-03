/**
 * Public CSV Download — per-participant schedule exception (#366).
 *
 * Pops a modal with two TIME inputs pre-filled from the baseline
 * (class_time_* or geofence time_*), validates client-side, hands off to the
 * ffc_public_schedule_exception action. On success the modal swaps to a CTA
 * that opens the form URL in a new tab — the click preserves the user
 * gesture so popup blockers don't fire.
 *
 * Reads shared state/helpers from window.FFCCsv (ffc-csv-download.js) and
 * registers `api.onScheduleExceptionClick`.
 *
 * @since 6.x (split out of ffc-csv-download.js)
 */
(function ($) {
	'use strict';

	var api     = window.FFCCsv;
	var strings = api.strings;
	var esc     = api.esc;

	function onScheduleExceptionClick() {
		var info = api.$container.data('ffc-last-info') || {};
		var status = info.status || {};
		showScheduleExceptionModal({
			baselineStart: status.schedule_baseline_start || '',
			baselineEnd:   status.schedule_baseline_end   || '',
			windowStart:   status.schedule_window_start   || '',
			windowEnd:     status.schedule_window_end     || '',
			defaultMode:   status.schedule_default_mode   || 'now'
		});
	}

	function showScheduleExceptionModal(opts) {
		$('.ffc-schedule-exception-modal').remove();

		var modalHtml = ''
			+ '<div class="ffc-schedule-exception-modal ffc-open-early-modal" role="dialog" aria-modal="true" aria-labelledby="ffc-sched-exc-title">'
			+   '<div class="ffc-open-early-backdrop"></div>'
			+   '<div class="ffc-open-early-container">'
			+     '<div class="ffc-open-early-header">'
			+       '<h2 id="ffc-sched-exc-title">'
			+         '<span aria-hidden="true">⏱️</span> '
			+         esc(strings.scheduleExceptionTitle || 'Schedule exception')
			+       '</h2>'
			+       '<button type="button" class="ffc-open-early-close ffc-sched-exc-close" title="' + esc(strings.cancel || 'Cancel') + '">&times;</button>'
			+     '</div>'
			+     '<div class="ffc-open-early-body ffc-sched-exc-body">'
			+       '<p>' + esc(strings.scheduleExceptionBody || 'Set a different schedule for one participant. The next form submission opened from this modal will record this exception.') + '</p>'
			+       '<p>'
			+         '<label><input type="radio" name="ffc-sched-exc-mode" value="now" ' + (opts.defaultMode === 'now' ? 'checked' : '') + '> '
			+         esc(strings.scheduleExceptionModeNow || 'End now (start stays at baseline)')
			+         '</label><br>'
			+         '<label><input type="radio" name="ffc-sched-exc-mode" value="manual" ' + (opts.defaultMode === 'manual' ? 'checked' : '') + '> '
			+         esc(strings.scheduleExceptionModeManual || 'Edit both ends manually')
			+         '</label>'
			+       '</p>'
			+       '<p>'
			+         '<label for="ffc-sched-exc-start"><strong>' + esc(strings.scheduleExceptionStartLabel || 'New start:') + '</strong></label> '
			+         '<input type="time" id="ffc-sched-exc-start" class="ffc-extend-end-input ffc-sched-exc-start" value="' + esc(opts.baselineStart) + '">'
			+         '&nbsp;&nbsp;'
			+         '<label for="ffc-sched-exc-end"><strong>' + esc(strings.scheduleExceptionEndLabel || 'New end:') + '</strong></label> '
			+         '<input type="time" id="ffc-sched-exc-end" class="ffc-extend-end-input ffc-sched-exc-end" value="' + esc(opts.baselineEnd) + '">'
			+       '</p>'
			+       '<p class="ffc-sched-exc-error" role="alert" style="color:#d63638;margin:6px 0 0;font-size:13px;" hidden></p>'
			+     '</div>'
			+     '<div class="ffc-open-early-actions ffc-sched-exc-actions">'
			+       '<button type="button" class="ffc-info-btn ffc-info-btn-primary ffc-sched-exc-cancel" autofocus>'
			+         esc(strings.cancel || 'Cancel')
			+       '</button>'
			+       '<button type="button" class="ffc-info-btn ffc-info-btn-warning ffc-sched-exc-confirm">'
			+         esc(strings.scheduleExceptionConfirm || 'Create exception')
			+       '</button>'
			+     '</div>'
			+   '</div>'
			+ '</div>';

		var $modal = $(modalHtml).appendTo(document.body);
		var $start = $modal.find('.ffc-sched-exc-start');
		var $end   = $modal.find('.ffc-sched-exc-end');
		var $err   = $modal.find('.ffc-sched-exc-error');

		function setError(msg) {
			$err.text(msg).removeAttr('hidden');
		}
		function clearError() {
			$err.attr('hidden', 'hidden').text('');
		}

		function applyMode() {
			var mode = $modal.find('input[name="ffc-sched-exc-mode"]:checked').val();
			if (mode === 'now') {
				// Start stays baseline (disabled), end gets current local time.
				$start.val(opts.baselineStart).prop('disabled', true);
				var now    = new Date();
				var nowStr = (now.getHours()   < 10 ? '0' : '') + now.getHours()
					+ ':' + (now.getMinutes() < 10 ? '0' : '') + now.getMinutes();
				$end.val(nowStr).prop('disabled', false);
			} else {
				$start.prop('disabled', false);
				$end.prop('disabled', false);
			}
			clearError();
		}
		$modal.find('input[name="ffc-sched-exc-mode"]').on('change', applyMode);
		applyMode();

		$start.add($end).on('input change', clearError);

		var onKey = function (e) { if (e.key === 'Escape') { closeModal(); } };
		$(document).on('keydown.ffcSchedExc', onKey);
		function closeModal() {
			$(document).off('keydown.ffcSchedExc');
			$modal.remove();
		}

		function validate() {
			var s = $start.val();
			var e = $end.val();
			if ((s && !/^([01]\d|2[0-3]):[0-5]\d$/.test(s)) || (e && !/^([01]\d|2[0-3]):[0-5]\d$/.test(e))) {
				return strings.scheduleExceptionBadFormat || 'Please pick valid times (HH:MM).';
			}
			if (s && e && s >= e) {
				return strings.scheduleExceptionRangeInverted || 'Start must be earlier than end.';
			}
			if (opts.windowStart && s && s < opts.windowStart) {
				return (strings.scheduleExceptionOutOfWindow || 'Range must stay within %1$s–%2$s.').replace('%1$s', opts.windowStart).replace('%2$s', opts.windowEnd);
			}
			if (opts.windowEnd && e && e > opts.windowEnd) {
				return (strings.scheduleExceptionOutOfWindow || 'Range must stay within %1$s–%2$s.').replace('%1$s', opts.windowStart).replace('%2$s', opts.windowEnd);
			}
			if (s === opts.baselineStart && e === opts.baselineEnd) {
				return strings.scheduleExceptionNoChange || 'Override matches the baseline — nothing to do.';
			}
			return null;
		}

		$modal.find('.ffc-sched-exc-cancel, .ffc-sched-exc-close, .ffc-open-early-backdrop').on('click', closeModal);
		$modal.find('.ffc-sched-exc-confirm').on('click', function () {
			var err = validate();
			if (err) { setError(err); return; }
			submitScheduleException($start.val(), $end.val(), $modal, opts);
		});

		setTimeout(function () { $modal.find('.ffc-sched-exc-cancel').focus(); }, 0);
	}

	function submitScheduleException(startVal, endVal, $modal, opts) {
		var $confirm = $modal.find('.ffc-sched-exc-confirm');
		$confirm.prop('disabled', true).text(strings.scheduleExceptionSubmitting || 'Creating…');

		// Send empty string when the value matches the baseline — the
		// server treats '' as "leave at baseline", which produces a
		// cleaner audit row than a redundant override repeat.
		var payloadStart = (startVal === opts.baselineStart) ? '' : startVal;
		var payloadEnd   = (endVal   === opts.baselineEnd)   ? '' : endVal;

		var payload = api.$form.serialize().replace(/(^|&)action=[^&]*/, '')
			+ '&start_override=' + encodeURIComponent(payloadStart)
			+ '&end_override='   + encodeURIComponent(payloadEnd);

		FFC.request('ffc_public_schedule_exception', payload)
			.then(function (data) {
				// Swap modal body to the post-create CTA — preserves the
				// click-driven user gesture for the window.open call below
				// so popup blockers don't engage.
				var url = (data && data.form_url) ? data.form_url : '/';
				$modal.find('.ffc-sched-exc-body').html(
					'<p>' + esc(strings.scheduleExceptionStaged || 'Exception staged. Open the participant\'s form in the next tab to consume it.') + '</p>'
				);
				$modal.find('.ffc-sched-exc-actions').html(
					'<a class="ffc-info-btn ffc-info-btn-primary ffc-sched-exc-open" href="' + esc(url) + '" target="_blank" rel="noopener">'
					+ esc(strings.scheduleExceptionOpenForm || 'Open participant form')
					+ '</a>'
				);
				$modal.find('.ffc-sched-exc-open').on('click', function () {
					// Close shortly after the new tab opens — gives the
					// browser a beat to commit the navigation.
					setTimeout(function () { $modal.remove(); }, 100);
				});
			})
			.catch(function (err) {
				$confirm.prop('disabled', false).text(strings.scheduleExceptionConfirm || 'Create exception');
				$modal.find('.ffc-sched-exc-error')
					.text((err && err.fromServer && err.message) || strings.error || 'Action failed.')
					.removeAttr('hidden');
			});
	}

	api.onScheduleExceptionClick = onScheduleExceptionClick;

})(jQuery);
