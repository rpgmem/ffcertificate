/**
 * Public CSV Download — batched export flow (start → batch → download).
 *
 * Steps 3–5: creates the export job, processes batches recursively, then
 * serves the file via a hidden iframe. Reads shared state/helpers from
 * window.FFCCsv (ffc-csv-download.js) and registers `api.onDownloadClick`.
 *
 * @since 5.2.0 (split out of ffc-csv-download.js)
 */
(function ($) {
	'use strict';

	var api     = window.FFCCsv;
	var strings = api.strings;

	var MIN_DISPLAY = api.cfg.min_display_ms || 1500;
	var SAFETY_MS   = 300000; // 5 min hard timeout

	// Job state — local to this flow (never read by other modules).
	var jobId, nonceBatch, total, startTime;

	// ── Download CSV (triggers existing export flow) ────────────

	function onDownloadClick() {
		var $dlBtn = $(this);
		$dlBtn.prop('disabled', true).addClass('ffc-btn-loading');
		api.showOverlay(strings.validating || 'Validating…');
		startTime       = Date.now();
		api.safetyTimer = setTimeout(function () {
			api.showError(strings.timeout || 'Export timed out.');
		}, SAFETY_MS);

		// Step 3 — start export job (reuses saved form data).
		FFC.request('ffc_public_csv_start', api.formData)
			.then(function (data) {
				jobId      = data.job_id;
				nonceBatch = data.nonce_batch;
				total      = data.total;

				api.updateStatus(
					(strings.generating || 'Generating CSV — %d records…')
						.replace('%d', total)
				);
				api.updateProgress(0, total);

				// Step 4 — process batches.
				processBatch($dlBtn);
			})
			.catch(function (err) {
				api.showError((err && err.fromServer && err.message) || strings.connError || 'Connection error.');
				$dlBtn.prop('disabled', false).removeClass('ffc-btn-loading');
			});
	}

	// ── Recursive batch processing ──────────────────────────────

	function processBatch($dlBtn) {
		// The batch endpoint expects its own per-job nonce (`nonce_batch`),
		// not the global ffc_ajax nonce. Pass it via options.nonce so
		// FFC.request injects it as the canonical `nonce` payload field;
		// also keep it in the data under its server-side key.
		FFC.request('ffc_public_csv_batch', {
			job_id: jobId,
			nonce_batch: nonceBatch
		})
			.then(function (data) {
				api.updateProgress(data.processed, data.total);
				api.updateStatus(
					(strings.exporting || 'Exporting %1$d / %2$d…')
						.replace('%1$d', data.processed)
						.replace('%2$d', data.total)
				);

				if (data.done) {
					onExportComplete($dlBtn);
				} else {
					processBatch($dlBtn);
				}
			})
			.catch(function (err) {
				api.showError((err && err.fromServer && err.message) || strings.connError || 'Connection error.');
				if ($dlBtn) $dlBtn.prop('disabled', false).removeClass('ffc-btn-loading');
			});
	}

	// ── Download via hidden iframe ──────────────────────────────

	function onExportComplete($dlBtn) {
		clearTimeout(api.safetyTimer);
		api.updateProgress(total, total);
		api.updateStatus(strings.downloading || 'Starting download…');

		var downloadUrl = api.cfg.ajax_url
			+ '?action=ffc_public_csv_download'
			+ '&job_id='      + encodeURIComponent(jobId)
			+ '&nonce_batch=' + encodeURIComponent(nonceBatch);

		var $iframe = $('<iframe>', { src: downloadUrl })
			.css('display', 'none')
			.appendTo('body');

		var elapsed   = Date.now() - startTime;
		var remaining = Math.max(0, MIN_DISPLAY - elapsed);

		setTimeout(function () {
			api.updateStatus(strings.complete || 'Download complete!');
			api.$overlay.find('.ffc-csv-progress-bar-fill').addClass('ffc-csv-complete');

			setTimeout(function () {
				api.hideOverlay();
				if ($dlBtn) $dlBtn.prop('disabled', false).removeClass('ffc-btn-loading');
				$iframe.remove();
			}, 2000);
		}, remaining);
	}

	api.onDownloadClick = onDownloadClick;

})(jQuery);
