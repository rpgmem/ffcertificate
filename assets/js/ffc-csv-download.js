/**
 * Public CSV Download — AJAX batched export with progress bar.
 *
 * 3-step flow:
 *  1. ffc_public_csv_start   → validate + create job → returns job_id, total
 *  2. ffc_public_csv_batch   → process 50 rows        → returns processed/total (repeat)
 *  3. ffc_public_csv_download→ serve file via iframe   → triggers native download
 *
 * Falls back to normal form POST when JS is unavailable (graceful degradation).
 *
 * @since 5.1.0
 */
(function ($) {
	'use strict';

	var cfg         = window.ffc_csv_download || {};
	var MIN_DISPLAY = cfg.min_display_ms || 1500;
	var SAFETY_MS   = 300000; // 5 min hard timeout
	var strings     = cfg.strings || {};

	// DOM refs (set in init)
	var $form, $btn, $overlay;
	// Job state
	var jobId, nonceBatch, total, startTime, safetyTimer;

	// ── Initialise ──────────────────────────────────────────────

	function init() {
		$form = $('.ffc-public-csv-download form');
		if (!$form.length) {
			return;
		}
		$btn = $form.find('.ffc-submit-btn');
		$form.on('submit', onSubmit);
	}

	// ── Form submit ─────────────────────────────────────────────

	function onSubmit(e) {
		e.preventDefault();
		disableForm();
		showOverlay(strings.validating || 'Validating…');
		startTime = Date.now();
		safetyTimer = setTimeout(function () {
			showError(strings.timeout || 'Export timed out.');
		}, SAFETY_MS);

		// Step 1 — start export job
		$.ajax({
			url:  cfg.ajax_url,
			type: 'POST',
			data: $form.serialize() + '&action=ffc_public_csv_start',
			dataType: 'json',
			success: function (res) {
				if (!res || !res.success) {
					var msg = (res && res.data && res.data.message) || strings.error || 'Error';
					showError(msg);
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

				// Step 2 — process batches
				processBatch();
			},
			error: function () {
				showError(strings.connError || 'Connection error.');
			}
		});
	}

	// ── Recursive batch processing ──────────────────────────────

	function processBatch() {
		$.ajax({
			url:  cfg.ajax_url,
			type: 'POST',
			data: {
				action:      'ffc_public_csv_batch',
				job_id:      jobId,
				nonce_batch: nonceBatch
			},
			dataType: 'json',
			success: function (res) {
				if (!res || !res.success) {
					var msg = (res && res.data) || strings.error || 'Error';
					if (typeof msg === 'object' && msg.message) {
						msg = msg.message;
					}
					showError(msg);
					return;
				}

				updateProgress(res.data.processed, res.data.total);
				updateStatus(
					(strings.exporting || 'Exporting %1$d / %2$d…')
						.replace('%1$d', res.data.processed)
						.replace('%2$d', res.data.total)
				);

				if (res.data.done) {
					onExportComplete();
				} else {
					processBatch();
				}
			},
			error: function () {
				showError(strings.connError || 'Connection error.');
			}
		});
	}

	// ── Download via hidden iframe ──────────────────────────────

	function onExportComplete() {
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

		// Respect minimum display threshold
		var elapsed   = Date.now() - startTime;
		var remaining = Math.max(0, MIN_DISPLAY - elapsed);

		setTimeout(function () {
			updateStatus(strings.complete || 'Download complete!');
			$overlay.find('.ffc-csv-progress-bar-fill').addClass('ffc-csv-complete');

			setTimeout(function () {
				hideOverlay();
				enableForm();
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
					'<div class="ffc-csv-progress-status">' + escHtml(text) + '</div>' +
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
		enableForm();
	}

	// ── Form state helpers ──────────────────────────────────────

	function disableForm() {
		$btn.prop('disabled', true).addClass('ffc-btn-loading');
	}

	function enableForm() {
		$btn.prop('disabled', false).removeClass('ffc-btn-loading');
	}

	// ── Utility ─────────────────────────────────────────────────

	function escHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	// ── Boot ────────────────────────────────────────────────────

	$(document).ready(init);

})(jQuery);
