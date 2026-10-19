/**
 * Public CSV Download — step 1 (info screen) + section builders.
 *
 * Posts ffc_public_csv_info, then renders the form-details screen with the
 * action buttons. Reads the shared state/helpers from window.FFCCsv
 * (ffc-csv-download.js) and registers `api.onSubmitInfo` so the core's init
 * can wire the form submit. Button handlers are owned by the sibling flow
 * modules and referenced here when binding after render.
 *
 * @since 5.2.0 (split out of ffc-csv-download.js)
 */
(function () {
	'use strict';

	// This module builds HTML strings only — no direct jQuery calls — so it
	// doesn't take the $ alias the sibling modules do.
	var api     = window.FFCCsv;
	var strings = api.strings;
	var esc     = api.esc;

	/**
	 * A system notice as markup, for the screen's string builder (#1842).
	 * `FFC.notice()` sets every string through `textContent`, and serialising
	 * the element escapes it again, so the message never becomes markup.
	 * Read with the screen, so it takes the `note` role and is not announced.
	 */
	function notice(tone, icon, text) {
		return window.FFC.notice(tone, icon, text, '', { role: 'note' }).outerHTML;
	}

	/**
	 * One of the screen's action buttons, in the `.ffc-btn` family (#1881).
	 *
	 * @param {string}  label     Visible text.
	 * @param {string}  category  primary | neutral | caution.
	 * @param {string}  icon      A `Core\Icons` class name without the prefix.
	 * @param {string}  hook      The class the flow modules bind to.
	 * @param {boolean} disabled  Drawn but not pressable.
	 * @param {string}  title     Tooltip; empty for none.
	 * @return {string}
	 */
	function actionButton(label, category, icon, hook, disabled, title) {
		var attrs = {};
		if (title) {
			attrs.title = title;
		}
		return window.FFC.buttonHtml({
			label: label,
			category: category,
			icon: icon,
			className: hook,
			disabled: !!disabled,
			attrs: attrs
		});
	}

	// ── Step 1: Request form info ───────────────────────────────

	function onSubmitInfo(e) {
		e.preventDefault();
		api.disableBtn();
		api.showOverlay(strings.validating || 'Validating…');

		api.formData = api.$form.serialize();

		FFC.request('ffc_public_csv_info', api.formData)
			.then(function (data) {
				api.hideOverlay();
				renderInfoScreen(data);
			})
			.catch(function (err) {
				api.hideOverlay();
				if (err && err.fromServer) {
					api.showFlash(err.message || strings.error || 'Error', 'error');
					api.enableBtn();
				} else {
					api.showFlash(strings.connError || 'Connection error.', 'error');
					api.enableBtn();
				}
			});
	}

	// ── Render info screen ──────────────────────────────────────

	function renderInfoScreen(info) {
		var html = '';

		// Header with back button.
		html += '<div class="ffc-info-header">';
		// Back navigates, so it is neutral (#1881); `ffc-info-back` is its hook.
		html += actionButton(strings.backToForm || 'Back', 'neutral', 'chevron-left', 'ffc-info-back', false, '');
		html += '<h2>' + esc(strings.formDetails || 'Form Details') + '</h2>';
		html += '</div>';

		// Form title + submissions.
		html += '<div class="ffc-info-section ffc-info-summary">';
		html += '<div class="ffc-info-row">';
		html += '<span class="ffc-info-label">' + esc(strings.formTitle || 'Form') + '</span>';
		html += '<span class="ffc-info-value">' + esc(info.form_title) + '</span>';
		html += '</div>';
		html += '<div class="ffc-info-row">';
		html += '<span class="ffc-info-label">' + esc(strings.totalSubmissions || 'Total submissions') + '</span>';
		html += '<span class="ffc-info-value">' + info.submission_count + '</span>';
		html += '</div>';
		// Download quota — relocated from the former standalone CSV card so
		// the at-a-glance numbers live together in the summary.
		html += '<div class="ffc-info-row">';
		html += '<span class="ffc-info-label">' + esc(strings.downloadQuota || 'Download quota') + '</span>';
		var quotaText = (strings.quotaUsed || '%1$d of %2$d used')
			.replace('%1$d', info.csv.count)
			.replace('%2$d', info.csv.limit);
		html += '<span class="ffc-info-value">' + esc(quotaText) + '</span>';
		html += '</div>';
		// Participant-form page link (#366 Sprint 5, generalised). A short
		// "open" link instead of the raw URL so it fits the row layout;
		// opens the public form in a new tab. Shown whenever a page embeds
		// the form. Opening it does NOT stage or consume an exception token
		// (that happens solely via the exception modal's confirm).
		if (info.status.schedule_form_url) {
			html += '<div class="ffc-info-row">';
			html += '<span class="ffc-info-label">' + esc(strings.participantForm || 'Participant form') + '</span>';
			html += '<span class="ffc-info-value">'
				+ '<a class="ffc-info-form-link" href="' + esc(info.status.schedule_form_url) + '" target="_blank" rel="noopener">'
				+ esc(strings.openForm || 'Open form') + '</a>'
				+ '</span>';
			html += '</div>';
		}
		html += '</div>';

		// Restrictions (only if any are active).
		html += buildRestrictionsSection(info.restrictions);

		// Availability (access window) + event-schedule reference, merged
		// into one Access-vs-Reference comparison table.
		html += buildAvailabilityScheduleSection(info.datetime, info.status);

		// Geolocation.
		html += buildGeolocationSection(info.geolocation);

		// Quiz.
		html += buildQuizSection(info.quiz);

		// Status message.
		html += buildStatusMessage(info.status);

		// Action buttons — the `.ffc-btn` family (#1881). The category says
		// what each action does: Download CSV completes the screen's work
		// (primary); Preview only looks (neutral); Start now, Postpone close
		// and the schedule exception move a deadline the participants see
		// (caution). The `ffc-btn-*` hook classes are what the handlers
		// below bind to.
		html += '<div class="ffc-info-actions">';
		var previewLabel = strings.previewCertificate || 'Preview Certificate';
		if (info.status.can_preview_cert) {
			html += actionButton(previewLabel, 'neutral', 'eye', 'ffc-btn-cert-preview', false, '');
		} else if (info.status.cert_preview_disabled_by_admin) {
			html += actionButton(previewLabel, 'neutral', 'eye', 'ffc-btn-cert-preview', true,
				strings.certPreviewDisabledTip || 'Certificate Preview disabled');
		}
		// Start Form Early: enabled when can_open_early; disabled-visible
		// when admin turned it off (so the operator sees the feature exists
		// but is blocked); hidden otherwise. Same shape for Postpone Close.
		var startLabel = strings.startFormNow || 'Start Form Now';
		if (info.status.can_open_early) {
			html += actionButton(startLabel, 'caution', 'clock', 'ffc-btn-open-early', false,
				strings.openEarlyTooltip || 'Overrides the scheduled start time. Form opens immediately.');
		} else if (info.status.start_early_disabled_by_admin) {
			html += actionButton(startLabel, 'caution', 'clock', 'ffc-btn-open-early', true,
				strings.startEarlyDisabledTip || 'Start Form Early disabled');
		}
		var postponeLabel = strings.postponeClose || 'Postpone close';
		if (info.status.can_extend_end) {
			html += actionButton(postponeLabel, 'caution', 'calendar-check', 'ffc-btn-extend-end', false,
				strings.postponeCloseTooltip || 'Move the form\'s close time later within the same day. One-shot per form.');
		} else if (info.status.extend_end_disabled_by_admin) {
			html += actionButton(postponeLabel, 'caution', 'calendar-check', 'ffc-btn-extend-end', true,
				strings.extendEndDisabledTip || 'Postpone Close disabled');
		}
		// Schedule exception button (#366). Gated server-side by
		// `can_schedule_exception` which mirrors the action's
		// is_eligible(); no `*_disabled_by_admin` variant since admins
		// who opt out simply don't show the button to operators (the
		// flow itself stays hidden, no "feature exists but unavailable"
		// surface).
		if (info.status.can_schedule_exception) {
			html += actionButton(strings.scheduleException || 'Entry/exit exception', 'caution', 'user-clock',
				'ffc-btn-schedule-exception', false,
				strings.scheduleExceptionTooltip || 'Create a one-use schedule exception for a single participant submission.');
		}
		// Download CSV button is always rendered — toggle disabled state.
		// Admin-disabled gets a specific tooltip so the operator knows the
		// feature was turned off intentionally vs. just temporarily blocked
		// (form still active, quota exhausted, etc., which the info alert
		// below explains).
		var downloadLabel = strings.downloadCsv || 'Download CSV';
		if (info.status.can_download) {
			html += actionButton(downloadLabel, 'primary', 'download', 'ffc-btn-download-csv', false, '');
		} else if (info.status.csv_download_disabled_by_admin) {
			html += actionButton(downloadLabel, 'primary', 'download', 'ffc-btn-download-csv', true,
				strings.csvDownloadDisabledTip || 'CSV download disabled');
		} else {
			html += actionButton(downloadLabel, 'primary', 'download', 'ffc-btn-download-csv', true, '');
		}
		html += '</div>';

		// Replace container content.
		api.$container.html('<div class="ffc-info-screen">' + html + '</div>');
		// Stash for the open-early modal copy (needs original start
		// formatted for the warning text).
		api.$container.data('ffc-last-info', info);

		// Bind events. Handlers are owned by the sibling flow modules and
		// exposed on the namespace; they are defined by the time a click can
		// happen (all sibling scripts parse before this renders).
		api.$container.find('.ffc-info-back').on('click', api.goBack);
		api.$container.find('.ffc-btn-download-csv').not('[disabled]').on('click', api.onDownloadClick);
		api.$container.find('.ffc-btn-cert-preview').on('click', api.onCertPreviewClick);
		api.$container.find('.ffc-btn-open-early').on('click', api.onOpenEarlyClick);
		api.$container.find('.ffc-btn-extend-end').on('click', api.onExtendEndClick);
		api.$container.find('.ffc-btn-schedule-exception').on('click', api.onScheduleExceptionClick);
	}

	// ── Section builders ────────────────────────────────────────

	function buildRestrictionsSection(restrictions) {
		if (!restrictions) return '';
		var items = [];
		if (restrictions.password)  items.push(strings.passwordRequired  || 'Password required');
		if (restrictions.allowlist) items.push(strings.approvedUsersOnly || 'Restricted to approved users');
		if (restrictions.denylist)  items.push(strings.blockedUsers      || 'Blocked users list active');
		if (restrictions.ticket)   items.push(strings.accessCodeRequired || 'Access code (ticket) required');

		if (!items.length) return '';

		var html = '<div class="ffc-info-section">';
		html += '<h3>' + esc(strings.accessRestrictions || 'Access Restrictions') + '</h3>';
		html += '<ul class="ffc-info-list ffc-info-list-restrictions">';
		for (var i = 0; i < items.length; i++) {
			html += '<li>' + esc(items[i]) + '</li>';
		}
		html += '</ul>';
		html += '</div>';
		return html;
	}

	// Merged availability (access window) + event-schedule (reference)
	// comparison table. Up to two value columns:
	//   - Access: the form's open window (dates + times, from time_*).
	//   - Reference: the {schedule} baseline (class_time_*), times only,
	//     shown only when it differs from the access window (so forms
	//     without a separate event schedule don't show a redundant copy).
	// Date rows carry no reference value (em-dash placeholder). Collapses
	// to a single column when only one side is present; renders nothing
	// when neither is.
	function buildAvailabilityScheduleSection(dt, status) {
		var hasWindow = dt.has_dates || dt.has_times;

		var bStart = status.schedule_baseline_start || '';
		var bEnd   = status.schedule_baseline_end   || '';
		var wStart = status.schedule_window_start   || '';
		var wEnd   = status.schedule_window_end     || '';
		var hasRef = (bStart || bEnd) && !(bStart === wStart && bEnd === wEnd);

		if (!hasWindow && !hasRef) return '';

		var inf      = strings.infinity || '∞';
		var twoCol   = hasWindow && hasRef;

		var title = twoCol
			? (strings.availabilitySchedule || 'Availability & Schedule')
			: (hasWindow
				? (strings.availability || 'Availability Period')
				: (strings.scheduleReferenceTitle || 'Event Schedule (Reference)'));

		var html = '<div class="ffc-info-section ffc-info-availability-schedule">';
		html += '<h3>' + esc(title) + '</h3>';
		html += '<table class="ffc-info-table">';

		// Column headers only when both columns are present.
		if (twoCol) {
			html += '<thead><tr><th></th>';
			html += '<th class="ffc-info-table-col" title="' + esc(strings.accessColumnTip || 'Form access window') + '">'
				+ esc(strings.accessColumn || 'Access') + '</th>';
			html += '<th class="ffc-info-table-col" title="' + esc(strings.referenceColumnTip || 'Event schedule printed on the certificate') + '">'
				+ esc(strings.referenceColumn || 'Reference') + '</th>';
			html += '</tr></thead>';
		}

		html += '<tbody>';

		// refVal === null → em-dash (date rows have no reference value);
		// refVal === undefined → no reference cell at all (single column).
		function row(label, accessVal, refVal) {
			var h = '<tr><td class="ffc-info-table-label">' + esc(label) + '</td>';
			h += '<td class="ffc-info-table-value">' + accessVal + '</td>';
			if (twoCol) {
				h += (refVal === null)
					? '<td class="ffc-info-table-dash">—</td>'
					: '<td class="ffc-info-table-value">' + refVal + '</td>';
			}
			return h + '</tr>';
		}

		if (hasWindow) {
			if (dt.has_dates) {
				html += row(strings.dateStart || 'Start date', dt.date_start ? esc(dt.date_start) : inf, null);
				html += row(strings.dateEnd   || 'End date',   dt.date_end   ? esc(dt.date_end)   : inf, null);
			}
			var accStart = dt.has_times ? (dt.time_start ? esc(dt.time_start) : inf) : inf;
			var accEnd   = dt.has_times ? (dt.time_end   ? esc(dt.time_end)   : inf) : inf;
			html += row(strings.timeStart || 'Start time', accStart, twoCol ? (bStart ? esc(bStart) : inf) : undefined);
			html += row(strings.timeEnd   || 'End time',   accEnd,   twoCol ? (bEnd   ? esc(bEnd)   : inf) : undefined);
		} else {
			// Reference-only: single column of baseline times.
			html += row(strings.scheduleReferenceStart || 'Start', bStart ? esc(bStart) : inf, undefined);
			html += row(strings.scheduleReferenceEnd   || 'End',   bEnd   ? esc(bEnd)   : inf, undefined);
		}

		html += '</tbody></table>';

		// Reference clarification whenever a reference column/section shows.
		if (hasRef) {
			html += '<p class="ffc-info-schedule-ref-note">'
				+ esc(strings.scheduleReferenceNote || 'Reference event schedule (printed on the certificate) — not the form access window.')
				+ '</p>';
		}

		// No-end-date alert (relocated from the old datetime section).
		if (!status.has_end_date) {
			html += notice('warning', 'warning', strings.noEndDateAlert || 'This form has no end date configured. The CSV download will only be available after the administrator sets an end date.');
		}

		html += '</div>';
		return html;
	}

	function buildGeolocationSection(geo) {
		if (!geo || !geo.enabled) return '';

		var html = '<div class="ffc-info-section">';
		html += '<h3>' + esc(strings.geolocation || 'Geolocation') + '</h3>';

		// GPS locations.
		if (geo.gps_enabled) {
			if (geo.gps_locations && geo.gps_locations.length) {
				html += '<div class="ffc-info-subsection">';
				html += '<span class="ffc-info-sublabel">' + esc(strings.gpsLocations || 'GPS Locations') + '</span>';
				html += '<ul class="ffc-info-list ffc-info-list-locations">';
				for (var i = 0; i < geo.gps_locations.length; i++) {
					var loc = geo.gps_locations[i];
					html += '<li><a href="' + esc(loc.maps_url) + '" target="_blank" rel="noopener noreferrer">' + esc(loc.name) + '</a></li>';
				}
				html += '</ul>';
				html += '</div>';
			} else if (geo.gps_custom) {
				html += '<div class="ffc-info-row">';
				html += '<span class="ffc-info-value"><a href="https://www.google.com/maps" target="_blank" rel="noopener noreferrer">' + esc(strings.geolocationEnabled || 'Geolocation enabled') + '</a></span>';
				html += '</div>';
			}
		}

		// IP locations.
		if (geo.ip_enabled && (geo.ip_locations || geo.ip_custom)) {
			if (geo.ip_locations && geo.ip_locations.length) {
				html += '<div class="ffc-info-subsection">';
				html += '<span class="ffc-info-sublabel">' + esc(strings.ipLocations || 'IP Locations') + '</span>';
				html += '<ul class="ffc-info-list ffc-info-list-locations">';
				for (var j = 0; j < geo.ip_locations.length; j++) {
					var ipLoc = geo.ip_locations[j];
					html += '<li><a href="' + esc(ipLoc.maps_url) + '" target="_blank" rel="noopener noreferrer">' + esc(ipLoc.name) + '</a></li>';
				}
				html += '</ul>';
				html += '</div>';
			} else if (geo.ip_custom) {
				html += '<div class="ffc-info-row">';
				html += '<span class="ffc-info-value"><a href="https://www.google.com/maps" target="_blank" rel="noopener noreferrer">' + esc(strings.geolocationEnabled || 'Geolocation enabled') + '</a></span>';
				html += '</div>';
			}
		}

		html += '</div>';
		return html;
	}

	function buildQuizSection(quiz) {
		if (!quiz || !quiz.enabled) return '';

		var html = '<div class="ffc-info-section">';
		html += '<h3>' + esc(strings.quizEvaluation || 'Quiz / Evaluation') + '</h3>';
		html += '<div class="ffc-info-row">';
		html += '<span class="ffc-info-label">' + esc(strings.passingScore || 'Minimum passing score') + '</span>';
		html += '<span class="ffc-info-value">' + quiz.passing_score + '%</span>';
		html += '</div>';
		html += '<div class="ffc-info-row">';
		html += '<span class="ffc-info-label">' + esc(strings.maxAttempts || 'Maximum attempts') + '</span>';
		html += '<span class="ffc-info-value">' + (quiz.max_attempts > 0 ? quiz.max_attempts : esc(strings.unlimited || 'Unlimited')) + '</span>';
		html += '</div>';
		html += '</div>';
		return html;
	}

	function buildStatusMessage(status) {
		var html = '';
		var reason = status.download_blocked_reason;

		if (reason === 'no_end_date') {
			// Already shown in datetime section alert.
			return '';
		}

		if (reason === 'active') {
			var msg = (strings.formActiveUntil || 'This form is still active until %s. The download will be available after the end date.')
				.replace('%s', status.end_date_formatted || '');
			if (status.before_start) {
				msg = (strings.beforeStartMsg || 'The form collection has not started yet. It will begin on %s.')
					.replace('%s', status.start_date_formatted || '');
			}
			html += notice('info', 'clock', msg);
		} else if (reason === 'quota_exhausted') {
			html += notice('warning', 'warning', strings.quotaExhausted || 'The download quota for this form has been exhausted.');
		} else if (reason === 'download_disabled') {
			// CSV Download sub-toggle (post-#241) is off — Start Early /
			// Postpone Close may still be available on this same page.
			html += notice('info', 'info', strings.csvDownloadDisabled || 'The CSV download is not available for this form.');
		} else if (!reason) {
			html += notice('success', 'success', strings.downloadReady || 'The form collection period has ended. The CSV is ready for download.');
		}

		return html;
	}

	api.onSubmitInfo    = onSubmitInfo;
	api.renderInfoScreen = renderInfoScreen;

})();
