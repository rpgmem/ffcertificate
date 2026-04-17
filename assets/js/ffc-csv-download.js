/**
 * Public CSV Download — AJAX batched export with intermediate info screen.
 *
 * Flow:
 *  1. ffc_public_csv_info     → validate hash → show form details screen
 *  2. (optional) cert preview → modal with certificate HTML
 *  3. ffc_public_csv_start    → create export job → returns job_id, total
 *  4. ffc_public_csv_batch    → process 50 rows   → returns processed/total (repeat)
 *  5. ffc_public_csv_download → serve file via iframe → triggers native download
 *
 * Falls back to normal form POST when JS is unavailable (graceful degradation).
 *
 * @since 5.2.0
 */
(function ($) {
	'use strict';

	var cfg         = window.ffc_csv_download || {};
	var MIN_DISPLAY = cfg.min_display_ms || 1500;
	var SAFETY_MS   = 300000; // 5 min hard timeout
	var strings     = cfg.strings || {};

	// DOM refs (set in init)
	var $container, $form, $btn, $overlay;
	// Saved form data for reuse across AJAX calls
	var formData, savedFormId, savedHash;
	// Job state
	var jobId, nonceBatch, total, startTime, safetyTimer;

	// ── Initialise ──────────────────────────────────────────────

	function init() {
		$container = $('.ffc-public-csv-download');
		$form      = $container.find('form');
		if (!$form.length) {
			return;
		}
		$btn = $form.find('.ffc-submit-btn');
		$form.on('submit', onSubmitInfo);
	}

	// ── Step 1: Request form info ───────────────────────────────

	function onSubmitInfo(e) {
		e.preventDefault();
		disableBtn();
		showOverlay(strings.validating || 'Validating…');

		formData    = $form.serialize();
		savedFormId = $form.find('[name="form_id"]').val();
		savedHash   = $form.find('[name="hash"]').val();

		$.ajax({
			url:      cfg.ajax_url,
			type:     'POST',
			data:     formData + '&action=ffc_public_csv_info',
			dataType: 'json',
			success:  function (res) {
				hideOverlay();
				if (!res || !res.success) {
					var msg = (res && res.data && res.data.message) || strings.error || 'Error';
					showFlash(msg, 'error');
					enableBtn();
					return;
				}
				renderInfoScreen(res.data);
			},
			error: function () {
				hideOverlay();
				showFlash(strings.connError || 'Connection error.', 'error');
				enableBtn();
			}
		});
	}

	// ── Render info screen ──────────────────────────────────────

	function renderInfoScreen(info) {
		var html = '';

		// Header with back button.
		html += '<div class="ffc-info-header">';
		html += '<button type="button" class="ffc-info-back" title="' + esc(strings.backToForm || 'Back') + '">';
		html += '<span class="ffc-info-back-arrow">&#8592;</span> ' + esc(strings.backToForm || 'Back');
		html += '</button>';
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
		html += '</div>';

		// Restrictions (only if any are active).
		html += buildRestrictionsSection(info.restrictions);

		// Availability period.
		html += buildDatetimeSection(info.datetime, info.status);

		// Geolocation.
		html += buildGeolocationSection(info.geolocation);

		// Quiz.
		html += buildQuizSection(info.quiz);

		// CSV download section.
		html += buildCsvSection(info.csv, info.status);

		// Status message.
		html += buildStatusMessage(info.status);

		// Action buttons.
		html += '<div class="ffc-info-actions">';
		if (info.status.can_preview_cert) {
			html += '<button type="button" class="ffc-info-btn ffc-info-btn-secondary ffc-btn-cert-preview">';
			html += esc(strings.previewCertificate || 'Preview Certificate');
			html += '</button>';
		}
		if (info.status.can_download) {
			html += '<button type="button" class="ffc-info-btn ffc-info-btn-primary ffc-btn-download-csv">';
			html += esc(strings.downloadCsv || 'Download CSV');
			html += '</button>';
		} else {
			html += '<button type="button" class="ffc-info-btn ffc-info-btn-primary ffc-btn-download-csv" disabled>';
			html += esc(strings.downloadCsv || 'Download CSV');
			html += '</button>';
		}
		html += '</div>';

		// Replace container content.
		$container.html('<div class="ffc-info-screen">' + html + '</div>');

		// Bind events.
		$container.find('.ffc-info-back').on('click', goBack);
		$container.find('.ffc-btn-download-csv').not('[disabled]').on('click', onDownloadClick);
		$container.find('.ffc-btn-cert-preview').on('click', onCertPreviewClick);
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

	function buildDatetimeSection(dt, status) {
		if (!dt.has_dates && !dt.has_times) return '';

		var inf = strings.infinity || '∞';
		var html = '<div class="ffc-info-section">';
		html += '<h3>' + esc(strings.availability || 'Availability Period') + '</h3>';

		if (dt.has_dates) {
			html += '<div class="ffc-info-row">';
			html += '<span class="ffc-info-label">' + esc(strings.dateStart || 'Start date') + '</span>';
			html += '<span class="ffc-info-value">' + (dt.date_start ? esc(dt.date_start) : inf) + '</span>';
			html += '</div>';
			html += '<div class="ffc-info-row">';
			html += '<span class="ffc-info-label">' + esc(strings.dateEnd || 'End date') + '</span>';
			html += '<span class="ffc-info-value">' + (dt.date_end ? esc(dt.date_end) : inf) + '</span>';
			html += '</div>';
		}

		if (dt.has_times) {
			html += '<div class="ffc-info-row">';
			html += '<span class="ffc-info-label">' + esc(strings.timeStart || 'Start time') + '</span>';
			html += '<span class="ffc-info-value">' + (dt.time_start ? esc(dt.time_start) : inf) + '</span>';
			html += '</div>';
			html += '<div class="ffc-info-row">';
			html += '<span class="ffc-info-label">' + esc(strings.timeEnd || 'End time') + '</span>';
			html += '<span class="ffc-info-value">' + (dt.time_end ? esc(dt.time_end) : inf) + '</span>';
			html += '</div>';
		}

		// Alert when no end date.
		if (!status.has_end_date) {
			html += '<div class="ffc-info-alert ffc-info-alert-warning">';
			html += esc(strings.noEndDateAlert || 'This form has no end date configured. The CSV download will only be available after the administrator sets an end date.');
			html += '</div>';
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

	function buildCsvSection(csv, status) {
		var html = '<div class="ffc-info-section">';
		html += '<h3>' + esc(strings.csvDownload || 'CSV Download') + '</h3>';
		html += '<div class="ffc-info-row">';
		html += '<span class="ffc-info-label">' + esc(strings.downloadQuota || 'Download quota') + '</span>';
		var quotaText = (strings.quotaUsed || '%1$d of %2$d used')
			.replace('%1$d', csv.count)
			.replace('%2$d', csv.limit);
		html += '<span class="ffc-info-value">' + esc(quotaText) + '</span>';
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
			html += '<div class="ffc-info-alert ffc-info-alert-info">' + esc(msg) + '</div>';
		} else if (reason === 'quota_exhausted') {
			html += '<div class="ffc-info-alert ffc-info-alert-warning">' + esc(strings.quotaExhausted || 'The download quota for this form has been exhausted.') + '</div>';
		} else if (!reason) {
			html += '<div class="ffc-info-alert ffc-info-alert-success">' + esc(strings.downloadReady || 'The form collection period has ended. The CSV is ready for download.') + '</div>';
		}

		return html;
	}

	// ── Back button ─────────────────────────────────────────────

	function goBack() {
		location.reload();
	}

	// ── Flash message ───────────────────────────────────────────

	function showFlash(msg, type) {
		$container.find('.ffc-pcd-message').remove();
		var cls = type === 'error' ? 'ffc-verify-error' : 'ffc-verify-success';
		var $flash = $('<div class="ffc-verify-result ffc-pcd-message"><div class="' + cls + '">' + esc(msg) + '</div></div>');
		$container.find('.ffc-verification-header').after($flash);
	}

	// ── Certificate preview ─────────────────────────────────────

	function onCertPreviewClick() {
		var $btn = $(this);
		$btn.prop('disabled', true).text(strings.loadingPreview || 'Loading preview…');

		$.ajax({
			url:      cfg.ajax_url,
			type:     'POST',
			data:     formData + '&action=ffc_public_cert_preview',
			dataType: 'json',
			success:  function (res) {
				$btn.prop('disabled', false).text(strings.previewCertificate || 'Preview Certificate');
				if (!res || !res.success) {
					var msg = (res && res.data && res.data.message) || strings.error || 'Error';
					alert(msg);
					return;
				}
				showCertPreviewModal(res.data);
			},
			error: function () {
				$btn.prop('disabled', false).text(strings.previewCertificate || 'Preview Certificate');
				alert(strings.connError || 'Connection error.');
			}
		});
	}

	function showCertPreviewModal(data) {
		var sampleData = buildSampleData(data.fields);
		var processedHtml = replacePlaceholders(data.html, sampleData);

		var iframeHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
		iframeHtml += '<style>';
		iframeHtml += 'html, body { margin: 0; padding: 0; }';
		iframeHtml += 'body { font-family: Arial, Helvetica, sans-serif; ';
		if (data.bg_image) {
			iframeHtml += 'background-image: url(' + data.bg_image + '); ';
			iframeHtml += 'background-size: cover; background-position: center; background-repeat: no-repeat; ';
		}
		iframeHtml += '}';
		iframeHtml += '</style></head><body>';
		iframeHtml += processedHtml;
		iframeHtml += '</body></html>';

		var previewTitle = strings.certPreviewTitle || 'Certificate Preview';
		var closeText    = strings.close || 'Close';
		var noteText     = strings.certPreviewNote || 'Placeholders replaced with sample data. QR code shown as placeholder.';

		var $modal = $('<div id="ffc-preview-modal">' +
			'<div class="ffc-preview-backdrop"></div>' +
			'<div class="ffc-preview-container">' +
				'<div class="ffc-preview-header">' +
					'<h2>' + esc(previewTitle) + '</h2>' +
					'<button type="button" class="ffc-preview-close" title="' + esc(closeText) + '">&times;</button>' +
				'</div>' +
				'<div class="ffc-preview-note">' + esc(noteText) + '</div>' +
				'<div class="ffc-preview-body">' +
					'<iframe id="ffc-preview-iframe" frameborder="0"></iframe>' +
				'</div>' +
			'</div>' +
		'</div>');

		$('body').append($modal);

		var iframe    = $modal.find('#ffc-preview-iframe')[0];
		var iframeDoc = iframe.contentWindow || iframe.contentDocument;
		if (iframeDoc.document) {
			iframeDoc = iframeDoc.document;
		}
		iframeDoc.open();
		iframeDoc.write(iframeHtml);
		iframeDoc.close();

		requestAnimationFrame(function () {
			$modal.addClass('ffc-preview-visible');
		});

		function closePreview() {
			$modal.removeClass('ffc-preview-visible');
			setTimeout(function () { $modal.remove(); }, 200);
			$(document).off('keydown.ffcCertPreview');
		}

		$modal.find('.ffc-preview-close').on('click', closePreview);
		$modal.find('.ffc-preview-backdrop').on('click', closePreview);
		$(document).on('keydown.ffcCertPreview', function (e) {
			if (e.key === 'Escape') closePreview();
		});
	}

	function buildSampleData(fields) {
		var data = {
			'name':            'John Doe',
			'email':           'john_doe@example.com',
			'cpf_rf':          '123.456.789-00',
			'cpf':             '123.456.789-00',
			'auth_code':       'A1B2-C3D4-E5F6',
			'submission_date': new Date().toLocaleDateString('pt-BR', { year: 'numeric', month: 'long', day: 'numeric' }),
			'print_date':      new Date().toLocaleDateString('pt-BR', { year: 'numeric', month: 'long', day: 'numeric' }),
			'fill_date':       new Date().toLocaleDateString('pt-BR', { year: 'numeric', month: 'long', day: 'numeric' }),
			'date':            new Date().toLocaleDateString('pt-BR', { year: 'numeric', month: 'long', day: 'numeric' }),
			'submission_id':   '1234',
			'magic_token':     'abc123def456ghi789jkl012',
			'ticket':          'TK01-AB2C-3D4E'
		};

		if (fields && fields.length) {
			for (var i = 0; i < fields.length; i++) {
				if (fields[i].name && !data[fields[i].name]) {
					data[fields[i].name] = fields[i].label || fields[i].name;
				}
			}
		}

		return data;
	}

	function replacePlaceholders(html, data) {
		html = html.replace(/\{\{(\w+)\}\}/g, function (match, key) {
			return data[key] !== undefined ? data[key] : match;
		});
		html = html.replace(/\{\{qr_code[^}]*\}\}/g,
			'<svg width="150" height="150" viewBox="0 0 150 150" xmlns="http://www.w3.org/2000/svg">' +
			'<rect width="150" height="150" fill="#f0f0f0" stroke="#ccc" stroke-width="1"/>' +
			'<text x="75" y="70" text-anchor="middle" font-size="12" fill="#999">QR Code</text>' +
			'<text x="75" y="90" text-anchor="middle" font-size="10" fill="#bbb">(preview)</text>' +
			'</svg>'
		);
		html = html.replace(/\{\{validation_url[^}]*\}\}/g,
			'<a href="#" style="color:#0073aa;">https://example.com/valid/#token=abc123</a>'
		);
		return html;
	}

	// ── Download CSV (triggers existing export flow) ────────────

	function onDownloadClick() {
		var $dlBtn = $(this);
		$dlBtn.prop('disabled', true).addClass('ffc-btn-loading');
		showOverlay(strings.validating || 'Validating…');
		startTime   = Date.now();
		safetyTimer = setTimeout(function () {
			showError(strings.timeout || 'Export timed out.');
		}, SAFETY_MS);

		// Step 3 — start export job (reuses saved form data).
		$.ajax({
			url:      cfg.ajax_url,
			type:     'POST',
			data:     formData + '&action=ffc_public_csv_start',
			dataType: 'json',
			success:  function (res) {
				if (!res || !res.success) {
					var msg = (res && res.data && res.data.message) || strings.error || 'Error';
					showError(msg);
					$dlBtn.prop('disabled', false).removeClass('ffc-btn-loading');
					return;
				}
				jobId      = res.data.job_id;
				nonceBatch = res.data.nonce_batch;
				total      = res.data.total;

				updateStatus(
					(strings.generating || 'Generating CSV — %d records…')
						.replace('%d', total)
				);
				updateProgress(0, total);

				// Step 4 — process batches.
				processBatch($dlBtn);
			},
			error: function () {
				showError(strings.connError || 'Connection error.');
				$dlBtn.prop('disabled', false).removeClass('ffc-btn-loading');
			}
		});
	}

	// ── Recursive batch processing ──────────────────────────────

	function processBatch($dlBtn) {
		$.ajax({
			url:      cfg.ajax_url,
			type:     'POST',
			data: {
				action:      'ffc_public_csv_batch',
				job_id:      jobId,
				nonce_batch: nonceBatch
			},
			dataType: 'json',
			success:  function (res) {
				if (!res || !res.success) {
					var msg = (res && res.data) || strings.error || 'Error';
					if (typeof msg === 'object' && msg.message) {
						msg = msg.message;
					}
					showError(msg);
					if ($dlBtn) $dlBtn.prop('disabled', false).removeClass('ffc-btn-loading');
					return;
				}

				updateProgress(res.data.processed, res.data.total);
				updateStatus(
					(strings.exporting || 'Exporting %1$d / %2$d…')
						.replace('%1$d', res.data.processed)
						.replace('%2$d', res.data.total)
				);

				if (res.data.done) {
					onExportComplete($dlBtn);
				} else {
					processBatch($dlBtn);
				}
			},
			error: function () {
				showError(strings.connError || 'Connection error.');
				if ($dlBtn) $dlBtn.prop('disabled', false).removeClass('ffc-btn-loading');
			}
		});
	}

	// ── Download via hidden iframe ──────────────────────────────

	function onExportComplete($dlBtn) {
		clearTimeout(safetyTimer);
		updateProgress(total, total);
		updateStatus(strings.downloading || 'Starting download…');

		var downloadUrl = cfg.ajax_url
			+ '?action=ffc_public_csv_download'
			+ '&job_id='      + encodeURIComponent(jobId)
			+ '&nonce_batch=' + encodeURIComponent(nonceBatch);

		var $iframe = $('<iframe>', { src: downloadUrl })
			.css('display', 'none')
			.appendTo('body');

		var elapsed   = Date.now() - startTime;
		var remaining = Math.max(0, MIN_DISPLAY - elapsed);

		setTimeout(function () {
			updateStatus(strings.complete || 'Download complete!');
			$overlay.find('.ffc-csv-progress-bar-fill').addClass('ffc-csv-complete');

			setTimeout(function () {
				hideOverlay();
				if ($dlBtn) $dlBtn.prop('disabled', false).removeClass('ffc-btn-loading');
				$iframe.remove();
			}, 2000);
		}, remaining);
	}

	// ── Overlay helpers ─────────────────────────────────────────

	function showOverlay(text) {
		if ($overlay) {
			$overlay.remove();
		}

		$overlay = $(
			'<div class="ffc-csv-progress-overlay" role="alertdialog" aria-live="assertive">' +
				'<div class="ffc-csv-progress-card">' +
					'<div class="ffc-csv-progress-status">' + esc(text) + '</div>' +
					'<div class="ffc-csv-progress-bar-container">' +
						'<div class="ffc-csv-progress-bar-fill" style="width:0%"></div>' +
					'</div>' +
					'<div class="ffc-csv-progress-percent">0 %</div>' +
				'</div>' +
			'</div>'
		).appendTo('body');
	}

	function hideOverlay() {
		if ($overlay) {
			$overlay.fadeOut(300, function () { $(this).remove(); });
			$overlay = null;
		}
	}

	function updateProgress(current, max) {
		if (!$overlay) return;
		var pct = max > 0 ? Math.min(100, Math.round((current / max) * 100)) : 0;
		$overlay.find('.ffc-csv-progress-bar-fill').css('width', pct + '%');
		$overlay.find('.ffc-csv-progress-percent').text(pct + ' %');
	}

	function updateStatus(text) {
		if (!$overlay) return;
		$overlay.find('.ffc-csv-progress-status').text(text);
	}

	function showError(msg) {
		clearTimeout(safetyTimer);
		if ($overlay) {
			$overlay.find('.ffc-csv-progress-status').text(strings.error || 'Error');
			$overlay.find('.ffc-csv-progress-bar-fill').addClass('ffc-csv-error');

			var $err = $overlay.find('.ffc-csv-progress-error');
			if (!$err.length) {
				$err = $('<div class="ffc-csv-progress-error"></div>')
					.appendTo($overlay.find('.ffc-csv-progress-card'));
			}
			$err.text(msg);

			setTimeout(function () { hideOverlay(); }, 4000);
		}
	}

	// ── Form state helpers ──────────────────────────────────────

	function disableBtn() {
		if ($btn) $btn.prop('disabled', true).addClass('ffc-btn-loading');
	}

	function enableBtn() {
		if ($btn) $btn.prop('disabled', false).removeClass('ffc-btn-loading');
	}

	// ── Utility ─────────────────────────────────────────────────

	function esc(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	// ── Boot ────────────────────────────────────────────────────

	$(document).ready(init);

})(jQuery);
