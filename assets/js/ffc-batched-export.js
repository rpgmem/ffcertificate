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
	 * The strings the export speaks in, localised once for every caller
	 * (#2344). A caller's own value wins only when it is a non-empty string.
	 *
	 * @param {Object} [own] The caller's strings.
	 * @return {Object}
	 */
	function stringsFor(own) {
		var out = $.extend({
			title: 'Exporting CSV',
			preparing: 'Preparing…',
			generating: 'Generating CSV — %d records…',
			exporting: 'Exporting %1$d / %2$d…',
			downloading: 'Starting download…',
			error: 'Export failed.',
			connectionError: 'Connection error. Check your connection and try again.',
			close: 'Close',
			percent: '%d percent done'
		}, window.ffcBatchedExportStrings || {});
		Object.keys(own || {}).forEach(function (key) {
			if (typeof own[key] === 'string' && own[key] !== '') {
				out[key] = own[key];
			}
		});
		return out;
	}

	/**
	 * Shared progress-overlay component (#786): a modal dialog with a title,
	 * a status line, a progress bar and an error state. One overlay is kept
	 * at a time, and the public download and the admin exports share it.
	 *
	 * It is a named modal (#2344): the focus moves into it and comes back
	 * to where it was when it closes. The bar is a `progressbar`, and a
	 * polite status announces every quarter of the way rather than every
	 * batch. An error stays on screen with a Close button.
	 */
	var overlay = (function () {
		var $overlay = null;
		var returnFocus = null;
		var announced = -1;
		var seq = 0;

		function strings() {
			return stringsFor();
		}

		function onKeydown(e) {
			if (!$overlay) { return; }
			var $close = $overlay.find('.ffc-csv-progress-close');
			if (e.key === 'Escape' && $close.length) {
				e.preventDefault();
				hide();
				return;
			}
			if (e.key === 'Tab') {
				// Nothing outside the dialog takes the focus while it is open.
				e.preventDefault();
				($close.length ? $close : $overlay.find('.ffc-csv-progress-card')).trigger('focus');
			}
		}

		function hide() {
			if (!$overlay) { return; }
			var $old = $overlay;
			$overlay = null;
			$(document).off('keydown.ffcProgressOverlay');
			$old.fadeOut(300, function () { $(this).remove(); });
			var back = returnFocus;
			returnFocus = null;
			if (back && document.body.contains(back) && typeof back.focus === 'function') {
				back.focus();
			}
		}

		return {
			/**
			 * Build (or rebuild) the overlay, append it to <body> and move
			 * the focus into it.
			 *
			 * @param {string} text Initial status text.
			 * @return {jQuery} The overlay element.
			 */
			show: function (text) {
				var s = strings();
				if ($overlay) {
					$overlay.remove();
				} else {
					returnFocus = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
				}
				seq++;
				announced = -1;
				var titleId = 'ffc-csv-progress-title-' + seq;
				var statusId = 'ffc-csv-progress-status-' + seq;
				$overlay = $(
					'<div class="ffc-csv-progress-overlay">' +
						'<div class="ffc-csv-progress-card" role="dialog" aria-modal="true" tabindex="-1">' +
							'<h2 class="ffc-csv-progress-title"></h2>' +
							'<p class="ffc-csv-progress-status"></p>' +
							'<div class="ffc-csv-progress-bar-container" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">' +
								'<div class="ffc-csv-progress-bar-fill" style="width:0%"></div>' +
							'</div>' +
							'<div class="ffc-csv-progress-percent" aria-hidden="true">0 %</div>' +
							'<p class="ffc-csv-progress-live" role="status"></p>' +
						'</div>' +
					'</div>'
				);
				$overlay.find('.ffc-csv-progress-title').attr('id', titleId).text(s.title);
				$overlay.find('.ffc-csv-progress-status').attr('id', statusId).text(text == null ? '' : text);
				$overlay.find('.ffc-csv-progress-card').attr({ 'aria-labelledby': titleId, 'aria-describedby': statusId });
				$overlay.find('.ffc-csv-progress-bar-container').attr('aria-labelledby', titleId);
				$overlay.appendTo('body');
				$overlay.find('.ffc-csv-progress-card').trigger('focus');
				$(document).off('keydown.ffcProgressOverlay').on('keydown.ffcProgressOverlay', onKeydown);
				return $overlay;
			},

			/** @return {jQuery|null} The current overlay element (or null). */
			el: function () { return $overlay; },

			/** Update the status line. It is not announced: the quarters are. */
			status: function (text) {
				if ($overlay) { $overlay.find('.ffc-csv-progress-status').text(text); }
			},

			/** Move the bar + percentage to current/max. */
			progress: function (current, max) {
				if (!$overlay) { return; }
				var pct = max > 0 ? Math.min(100, Math.round((current / max) * 100)) : 0;
				$overlay.find('.ffc-csv-progress-bar-fill').css('width', pct + '%');
				$overlay.find('.ffc-csv-progress-bar-container').attr('aria-valuenow', pct);
				$overlay.find('.ffc-csv-progress-percent').text(pct + ' %');
				var quarter = Math.floor(pct / 25) * 25;
				if (quarter > 0 && quarter > announced) {
					announced = quarter;
					$overlay.find('.ffc-csv-progress-live').text(strings().percent.replace('%d', quarter));
				}
			},

			/** Mark the bar as complete (green). */
			complete: function () {
				if ($overlay) { $overlay.find('.ffc-csv-progress-bar-fill').addClass('ffc-csv-complete'); }
			},

			/**
			 * Switch the overlay to its error state. The message stays until
			 * the visitor closes it.
			 */
			error: function (msg, statusLabel) {
				if (!$overlay) { return; }
				var s = strings();
				$overlay.find('.ffc-csv-progress-status').text(statusLabel || s.error);
				$overlay.find('.ffc-csv-progress-bar-fill').addClass('ffc-csv-error');
				var $card = $overlay.find('.ffc-csv-progress-card');
				$card.attr('role', 'alertdialog');
				var $err = $overlay.find('.ffc-csv-progress-error');
				if (!$err.length) {
					$err = $('<p class="ffc-csv-progress-error" role="alert"></p>').appendTo($card);
				}
				$err.text(msg == null ? '' : msg);
				var $close = $overlay.find('.ffc-csv-progress-close');
				if (!$close.length) {
					var close = window.FFC && typeof window.FFC.button === 'function'
						? window.FFC.button({ label: s.close, category: 'neutral', icon: 'cross', className: 'ffc-csv-progress-close' })
						: $('<button type="button" class="ffc-csv-progress-close"></button>').text(s.close)[0];
					$close = $(close).appendTo($card);
					$close.on('click', hide);
				}
				$close.trigger('focus');
			},

			/** Close the overlay and give the focus back. */
			hide: hide
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
		var s         = stringsFor(config.strings);
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
			// The focus moves into the dialog first, so disabling the
			// trigger does not drop it to the page (#2344).
			overlay.show(s.preparing);
			if ($button) { $button.prop('disabled', true); }
		}

		FFC.request('ffc_export_start', startData, startOpts)
			.then(function (data) {
				var jobId = data.job_id;
				var total = data.total;
				if (useOverlay) {
					overlay.status(s.generating.replace('%d', total));
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
									s.exporting
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
						overlay.status(s.downloading);
						overlay.complete();
						var $iframe = $('<iframe>', { src: url }).css('display', 'none').appendTo('body');
						setTimeout(function () {
							// Re-enabled first, so the focus can return to it.
							if ($button) { $button.prop('disabled', false); }
							overlay.hide();
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
				// The error stays until it is closed (#2344).
				if ($button) { $button.prop('disabled', false); }
				overlay.error(
					(err && err.fromServer && err.message) || s.connectionError,
					s.error
				);
			}
			if (cb.onError) { cb.onError(err); }
		}
	}

	window.FFCBatchedExport = { run: run, strings: stringsFor };

})(jQuery);
