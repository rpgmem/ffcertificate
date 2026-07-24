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

	// Progress state — local to this flow (never read by other modules).
	// The job id + per-job nonce are owned by window.FFCBatchedExport now.
	var total, startTime;

	// ── Download CSV (triggers existing export flow) ────────────

	function onDownloadClick() {
		var $dlBtn = $(this);
		$dlBtn.prop('disabled', true).addClass('ffc-btn-loading');
		api.showOverlay(strings.validating || 'Validating…');
		startTime       = Date.now();
		api.safetyTimer = setTimeout(function () {
			api.showError(strings.timeout || 'Export timed out.');
		}, SAFETY_MS);

		// Drive the start → batch → download flow through the shared batched
		// export module (#772). The public path is anonymous: the page nonce
		// travels inside api.formData and start returns a per-job nonce_batch,
		// so no capability nonce is passed here.
		window.FFCBatchedExport.run({
			type: 'public_forms',
			ajaxUrl: api.cfg.ajax_url,
			startData: api.formData,
			callbacks: {
				onStart: function (t) {
					total = t;
					api.updateStatus(
						(strings.generating || 'Generating CSV — %d records…')
							.replace('%d', total)
					);
					api.updateProgress(0, total);
				},
				onProgress: function (processed, tot) {
					api.updateProgress(processed, tot);
					api.updateStatus(
						(strings.exporting || 'Exporting %1$d / %2$d…')
							.replace('%1$d', processed)
							.replace('%2$d', tot)
					);
				},
				onComplete: function (downloadUrl) {
					onExportComplete($dlBtn, downloadUrl);
				},
				onError: function (err) {
					api.showError((err && err.fromServer && err.message) || strings.connError || 'Connection error.');
					$dlBtn.prop('disabled', false).removeClass('ffc-btn-loading');
				}
			}
		});
	}

	// ── Download via hidden iframe ──────────────────────────────

	function onExportComplete($dlBtn, downloadUrl) {
		clearTimeout(api.safetyTimer);
		api.updateProgress(total, total);
		api.updateStatus(strings.downloading || 'Starting download…');

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
