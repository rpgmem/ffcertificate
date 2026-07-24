/**
 * FFCBatchedExport — shared driver for the unified batched CSV export.
 *
 * Runs the start → batch → download flow against the single `ffc_export_*`
 * AJAX dispatcher (issue #772), routing to a server-side source by `type`.
 * Both callers — the admin submissions export (ffc-admin.js) and the public
 * forms export (ffc-csv-download-flow.js) — call FFCBatchedExport.run() with
 * their own type, request data, nonce handling and UI callbacks; the loop
 * mechanics (start job, poll batches to done, build the download URL) live here
 * once instead of duplicated per exporter.
 *
 * Nonce handling supports both ownership models:
 *  - capability-gated admin: a single `nonce` reused for start + batch, and
 *    appended as `&nonce=` to the download URL.
 *  - anonymous public: start returns a per-job `nonce_batch` that is then used
 *    for batch + appended as `&nonce_batch=` to the download URL.
 *
 * @since 6.17.0
 */
(function ($) {
	'use strict';

	/**
	 * Drive one export to completion.
	 *
	 * @param {Object}   config
	 * @param {string}   config.type       Server source id ('submissions' | 'public_forms').
	 * @param {string}   config.ajaxUrl    admin-ajax.php base URL (for the download iframe).
	 * @param {Object}   [config.startData] Extra fields merged into the start request.
	 * @param {string}   [config.nonce]     Nonce for start + batch (admin model). Omit for the
	 *                                       public model, which uses the per-job nonce_batch.
	 * @param {Object}   [config.callbacks] { onStart(total, startResp), onProgress(processed, total),
	 *                                        onComplete(downloadUrl, ctx), onError(err) }.
	 */
	function run(config) {
		var type      = config.type;
		var ajaxUrl   = config.ajaxUrl;
		var cb        = config.callbacks || {};
		// startData may be an object (admin export) or a pre-serialised
		// URL-encoded string from $form.serialize() (public export). Merge the
		// routing `type` into either shape WITHOUT corrupting it: $.extend on a
		// string spreads it into character-index keys, dropping every real field
		// — including the page nonce — which surfaced as a bogus "security check
		// failed" on the public download. FFC.request accepts both shapes, so we
		// only need to append `&type=` to a string and object-merge otherwise.
		var startData = ( typeof config.startData === 'string' )
			? config.startData + '&type=' + encodeURIComponent( type )
			: $.extend({ type: type }, config.startData || {});
		var startOpts = config.nonce ? { nonce: config.nonce } : {};

		FFC.request('ffc_export_start', startData, startOpts)
			.then(function (data) {
				var jobId = data.job_id;
				var total = data.total;
				if (cb.onStart) { cb.onStart(total, data); }

				processBatch();

				function processBatch() {
					var batchData = { type: type, job_id: jobId };
					var batchOpts = {};
					if (data.nonce_batch) {
						batchData.nonce_batch = data.nonce_batch;
						batchOpts.nonce = data.nonce_batch;
					} else if (config.nonce) {
						batchOpts.nonce = config.nonce;
					}

					FFC.request('ffc_export_batch', batchData, batchOpts)
						.then(function (bd) {
							if (cb.onProgress) { cb.onProgress(bd.processed, bd.total); }
							if (bd.done) {
								finish(bd);
							} else {
								processBatch();
							}
						})
						.catch(function (err) { if (cb.onError) { cb.onError(err); } });
				}

				function finish(bd) {
					var url = ajaxUrl
						+ '?action=ffc_export_download'
						+ '&type='   + encodeURIComponent(type)
						+ '&job_id=' + encodeURIComponent(jobId);
					if (data.nonce_batch) {
						url += '&nonce_batch=' + encodeURIComponent(data.nonce_batch);
					} else if (config.nonce) {
						url += '&nonce=' + encodeURIComponent(config.nonce);
					}
					if (cb.onComplete) {
						cb.onComplete(url, { jobId: jobId, processed: bd.processed, total: bd.total, start: data });
					}
				}
			})
			.catch(function (err) { if (cb.onError) { cb.onError(err); } });
	}

	window.FFCBatchedExport = { run: run };

})(jQuery);
