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

	/**
	 * Whether the challenge drawn for the next download has been answered
	 * (#2539).
	 *
	 * A first download spends the page's challenge, and the info screen draws
	 * a fresh one in `.ffc-csv-download-security`. The button stays enabled
	 * beside it, so a click before it is solved used to reach the server,
	 * which refused it with an error overlay and counted the attempt against
	 * the address. No block means the page's own, already-solved challenge
	 * travels: nothing to check.
	 *
	 * @return {jQuery|null} The control to focus when unanswered, else null.
	 */
	function unansweredChallenge() {
		var $block = api.$container ? api.$container.find('.ffc-csv-download-security') : $();
		if (!$block.length) {
			return null;
		}
		var $widget = $block.find('altcha-widget');
		if ($widget.length) {
			return String($block.find('input[name="altcha"]').val() || '').trim() ? null : $widget.first();
		}
		var $answer = $block.find('input[name="ffc_captcha_ans"]');
		if ($answer.length && !String($answer.val() || '').trim()) {
			return $answer.first();
		}
		return null;
	}

	/**
	 * Say, beside the challenge, that it comes first; and drop the line once
	 * it is answered.
	 *
	 * @param {jQuery} $control The widget or the answer field.
	 */
	function askToVerifyFirst($control) {
		var $block = api.$container.find('.ffc-csv-download-security');
		var $line  = $block.find('.ffc-csv-verify-first');
		if (!$line.length) {
			$line = $('<p class="ffc-csv-verify-first ffc-field-message" role="alert"></p>');
			$block.append($line);
			// The ALTCHA widget's `statechange` does not bubble, so it is
			// caught on the way down (as ffc-captcha.js does), and checked on
			// the next tick: the widget announces the state before it writes
			// the proof into its field.
			var blockEl = $block[0];
			var check = function () {
				setTimeout(function () {
					if (!unansweredChallenge()) {
						$line.remove();
						blockEl.removeEventListener('statechange', check, true);
						$block.off('.ffcVerifyFirst');
					}
				}, 0);
			};
			blockEl.addEventListener('statechange', check, true);
			$block.on('input.ffcVerifyFirst change.ffcVerifyFirst', check);
		}
		$line.text(strings.verifyFirst || 'Complete the verification above before downloading again.');
		var target = $control[0];
		var focusable = target && target.tagName === 'ALTCHA-WIDGET'
			? (target.querySelector('input[type="checkbox"]') || target)
			: target;
		if (focusable && typeof focusable.focus === 'function') {
			focusable.focus();
		}
	}

	function onDownloadClick() {
		var $dlBtn = $(this);
		var $unanswered = unansweredChallenge();
		if ($unanswered) {
			askToVerifyFirst($unanswered);
			return;
		}
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
			startData: api.downloadPayload(),
			callbacks: {
				onStart: function (t, resp) {
					total = t;
					// The challenge for the next download (#2341).
					if (resp && resp.download_security) {
						api.setDownloadSecurity(resp.download_security);
					}
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
					// A refused start spent the screen's block; the fresh one
					// it sent goes into the block on the screen (#2341).
					var $block = api.$container.find('.ffc-csv-download-security');
					var UI = window.FFC && window.FFC.Frontend && window.FFC.Frontend.UI;
					if ($block.length && err && err.data && err.data.refresh_captcha && UI && typeof UI.refreshCaptcha === 'function') {
						UI.refreshCaptcha($block, err.data);
						UI.refreshCaptcha(api.$downloadSecurity, err.data);
					}
					api.showError((err && err.fromServer && err.message) || strings.connError || 'Connection error.');
					$dlBtn.prop('disabled', false).removeClass('ffc-btn-loading');
				}
			}
		});
	}

	// ── Download via hidden iframe ──────────────────────────────

	var WAIT_MS = 120000; // how long the file may take to start arriving
	var POLL_MS = 400;

	/**
	 * The job id a download URL names, or '' (#2341).
	 *
	 * @param {string} url Download URL.
	 * @return {string}
	 */
	function jobIdOf(url) {
		var m = /[?&]job_id=([a-f0-9-]+)/i.exec(String(url || ''));
		return m ? m[1].toLowerCase() : '';
	}

	/**
	 * Whether the server's "file sent" cookie for this job is present, and
	 * drop it once read.
	 *
	 * @param {string} jobId Job id.
	 * @return {boolean}
	 */
	function takeDeliveredCookie(jobId) {
		var name = (api.cfg.delivered_cookie_prefix || 'ffc_csv_delivered_') + jobId;
		var found = document.cookie.split(';').some(function (part) {
			return part.replace(/^\s+/, '').indexOf(name + '=') === 0;
		});
		if (found) {
			document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
		}
		return found;
	}

	/**
	 * Serve the file, and say "Download complete" only when it arrived
	 * (#2341).
	 *
	 * The file comes through a hidden frame. Its response sets a cookie
	 * naming the job; the screen waits for that cookie instead of a timer.
	 * An error page loads into the frame instead (an attachment fires no
	 * `load`), so a `load` means the server refused, and its text is the
	 * reason. Once the file is on its way the info screen is drawn again, so
	 * the "x of y used" count shows the download just spent.
	 */
	function onExportComplete($dlBtn, downloadUrl) {
		clearTimeout(api.safetyTimer);
		api.updateProgress(total, total);
		api.updateStatus(strings.downloading || 'Starting download…');

		var jobId    = jobIdOf(downloadUrl);
		var started  = Date.now();
		var settled  = false;
		var pollId   = null;
		var $iframe  = $('<iframe>', { src: downloadUrl, hidden: true, title: strings.downloading || 'Starting download…' });

		function release() {
			if ($dlBtn) $dlBtn.prop('disabled', false).removeClass('ffc-btn-loading');
		}

		function settle(fn) {
			if (settled) {
				return;
			}
			settled = true;
			clearInterval(pollId);
			fn();
		}

		function delivered() {
			var remaining = Math.max(0, MIN_DISPLAY - (Date.now() - startTime));
			setTimeout(function () {
				api.updateStatus(strings.complete || 'Download complete!');
				if (api.$overlay) {
					api.$overlay.find('.ffc-csv-progress-bar-fill').addClass('ffc-csv-complete');
				}
				setTimeout(function () {
					api.hideOverlay();
					release();
					$iframe.remove();
					showSpent();
					api.reportResult('success', strings.complete || 'Download complete!', false);
				}, 1500);
			}, remaining);
		}

		// The server spent one download of the quota as it sent the file.
		// The screen is drawn again with that count; it is not read again,
		// because the download spent the challenge a fresh read would need.
		function showSpent() {
			var info = api.lastInfo();
			if (!info || !info.csv || !info.status) {
				return;
			}
			info.csv.count = (parseInt(info.csv.count, 10) || 0) + 1;
			var limit = parseInt(info.csv.limit, 10) || 0;
			if (limit > 0 && info.csv.count >= limit) {
				info.status.can_download = false;
				info.status.download_blocked_reason = 'quota_exhausted';
			}
			// The dialogs' challenge stays the one in hand: the info answer
			// kept here may carry one an action has spent since.
			var security = api.$actionSecurity;
			api.renderInfoScreen(info);
			api.$actionSecurity = security;
		}

		function refused(text) {
			release();
			$iframe.remove();
			api.showError(text || strings.connError || 'Connection error.');
		}

		$iframe.on('load', function () {
			var text = '';
			try {
				var doc = this.contentDocument;
				text = doc && doc.body ? String(doc.body.textContent || '').trim() : '';
			} catch (e) {
				text = '';
			}
			// A cookie may have come with a page that still loaded (a file
			// some browser shows inline): the cookie decides.
			if (jobId && takeDeliveredCookie(jobId)) {
				settle(delivered);
				return;
			}
			settle(function () { refused(text); });
		});
		$iframe.appendTo('body');

		if (!jobId) {
			// No job to wait for: keep the old behaviour of a short wait.
			setTimeout(function () { settle(delivered); }, MIN_DISPLAY);
			return;
		}
		pollId = setInterval(function () {
			if (takeDeliveredCookie(jobId)) {
				settle(delivered);
			} else if (Date.now() - started > WAIT_MS) {
				settle(function () { refused(strings.downloadNotStarted || 'The file did not arrive. Try the download again.'); });
			}
		}, POLL_MS);
	}

	api.onDownloadClick = onDownloadClick;

})(jQuery);
