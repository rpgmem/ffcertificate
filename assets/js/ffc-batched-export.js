/**
 * FFCBatchedExport — shared driver for the unified batched CSV export.
 *
 * Runs the start → batch → download flow against the single `ffc_export_*`
 * AJAX dispatcher (issue #772), routing to a server-side source by `type`.
 * Every caller — the six admin exports and the public forms export — calls
 * FFCBatchedExport.run() with its own type, request data and nonce handling;
 * the loop mechanics (start job, poll batches to done, build the download URL)
 * live here once instead of duplicated per exporter.
 *
 * Progress UI is shared too: this file also owns `window.FFCProgressOverlay`, a
 * single modal-overlay component (bar + %, status line, error state) reused by
 * both surfaces so the admin exports look identical to the public download
 * (#786). Pass `overlay: true` to have the driver drive the overlay itself
 * across the whole lifecycle (show → progress → download → hide); the public
 * flow instead drives the same overlay by hand through its own callbacks.
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
	 * Shared progress-overlay component: a fixed modal card with a status line,
	 * an animated bar + percentage, and an error state. One singleton overlay is
	 * kept at a time. Markup/classes match the public download so a single
	 * stylesheet (ffc-progress-overlay.css) styles both surfaces.
	 */
	var overlay = (function () {
		var $overlay = null;

		function esc(str) {
			var div = document.createElement('div');
			div.appendChild(document.createTextNode(str == null ? '' : str));
			return div.innerHTML;
		}

		return {
			/**
			 * Build (or rebuild) the overlay and append it to <body>.
			 * @param {string} text Initial status text.
			 * @return {jQuery} The overlay element.
			 */
			show: function (text) {
				if ($overlay) { $overlay.remove(); }
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
				return $overlay;
			},

			/** @return {jQuery|null} The current overlay element (or null). */
			el: function () { return $overlay; },

			/** Update the status line. */
			status: function (text) {
				if ($overlay) { $overlay.find('.ffc-csv-progress-status').text(text); }
			},

			/** Move the bar + percentage to current/max. */
			progress: function (current, max) {
				if (!$overlay) { return; }
				var pct = max > 0 ? Math.min(100, Math.round((current / max) * 100)) : 0;
				$overlay.find('.ffc-csv-progress-bar-fill').css('width', pct + '%');
				$overlay.find('.ffc-csv-progress-percent').text(pct + ' %');
			},

			/** Mark the bar as complete (green). */
			complete: function () {
				if ($overlay) { $overlay.find('.ffc-csv-progress-bar-fill').addClass('ffc-csv-complete'); }
			},

			/** Switch the overlay to its error state and show a message. */
			error: function (msg, statusLabel) {
				if (!$overlay) { return; }
				$overlay.find('.ffc-csv-progress-status').text(statusLabel || 'Error');
				$overlay.find('.ffc-csv-progress-bar-fill').addClass('ffc-csv-error');
				var $err = $overlay.find('.ffc-csv-progress-error');
				if (!$err.length) {
					$err = $('<div class="ffc-csv-progress-error"></div>')
						.appendTo($overlay.find('.ffc-csv-progress-card'));
				}
				$err.text(msg == null ? '' : msg);
			},

			/** Fade out and remove the overlay. */
			hide: function () {
				if ($overlay) {
					$overlay.fadeOut(300, function () { $(this).remove(); });
					$overlay = null;
				}
			}
		};
	})();

	window.FFCProgressOverlay = overlay;

	/**
	 * Drive one export to completion.
	 *
	 * @param {Object}   config
	 * @param {string}   config.type       Server source id (e.g. 'submissions', 'public_forms').
	 * @param {string}   config.ajaxUrl    admin-ajax.php base URL (for the download iframe).
	 * @param {Object}   [config.startData] Extra fields merged into the start request.
	 * @param {string}   [config.nonce]     Nonce for start + batch (admin model). Omit for the
	 *                                       public model, which uses the per-job nonce_batch.
	 * @param {boolean}  [config.overlay]   When true, the driver shows and drives the shared
	 *                                       FFCProgressOverlay across the whole lifecycle and
	 *                                       triggers the download iframe itself.
	 * @param {Element|jQuery} [config.button] Button to disable while the export runs (re-enabled
	 *                                       when the driver owns the overlay).
	 * @param {Object}   [config.strings]   Localized status strings for the overlay:
	 *                                       { preparing, generating(%d), exporting(%1$d/%2$d),
	 *                                         downloading, error }.
	 * @param {Object}   [config.callbacks] { onStart(total, startResp), onProgress(processed, total),
	 *                                        onComplete(downloadUrl, ctx), onError(err) }.
	 */
	function run(config) {
		var type      = config.type;
		var ajaxUrl   = config.ajaxUrl;
		var cb        = config.callbacks || {};
		var useOverlay = !!config.overlay && !!window.FFCProgressOverlay;
		var $button   = config.button ? $(config.button) : null;
		var s         = config.strings || {};
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

		if (useOverlay) {
			if ($button) { $button.prop('disabled', true); }
			overlay.show(s.preparing || 'Preparing…');
		}

		FFC.request('ffc_export_start', startData, startOpts)
			.then(function (data) {
				var jobId = data.job_id;
				var total = data.total;
				if (useOverlay) {
					overlay.status((s.generating || 'Generating CSV — %d records…').replace('%d', total));
					overlay.progress(0, total);
				}
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
							if (useOverlay) {
								// Use the job total captured at start as the
								// authoritative denominator (it does not change
								// between batches).
								overlay.progress(bd.processed, total);
								overlay.status(
									(s.exporting || 'Exporting %1$d / %2$d…')
										.replace('%1$d', bd.processed)
										.replace('%2$d', total)
								);
							}
							if (cb.onProgress) { cb.onProgress(bd.processed, bd.total); }
							if (bd.done) {
								finish(bd);
							} else {
								processBatch();
							}
						})
						.catch(handleError);
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

					if (useOverlay) {
						overlay.progress(total, total);
						overlay.status(s.downloading || 'Starting download…');
						overlay.complete();
						var $iframe = $('<iframe>', { src: url }).css('display', 'none').appendTo('body');
						setTimeout(function () {
							overlay.hide();
							if ($button) { $button.prop('disabled', false); }
							$iframe.remove();
						}, 2000);
					}

					if (cb.onComplete) {
						cb.onComplete(url, { jobId: jobId, processed: bd.processed, total: bd.total, start: data });
					}
				}
			})
			.catch(handleError);

		function handleError(err) {
			if (useOverlay) {
				// A server-sent message wins; otherwise fall back to the
				// connection-error string, then the generic error string —
				// mirroring the public download's onError.
				overlay.error(
					(err && err.fromServer && err.message) || s.connectionError || s.error || 'Export failed.',
					s.error
				);
				if ($button) { $button.prop('disabled', false); }
				setTimeout(function () { overlay.hide(); }, 4000);
			}
			if (cb.onError) { cb.onError(err); }
		}
	}

	window.FFCBatchedExport = { run: run };

})(jQuery);
