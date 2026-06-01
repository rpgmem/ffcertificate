/**
 * Batched CSV import for recruitment notices.
 *
 * Companion to the three REST endpoints under
 *   /ffcertificate/v1/recruitment/notices/{id}/import-job/{start,batch,commit}
 *
 * The pre-batched flow POSTed the full CSV to `/import` and waited for
 * the server to parse + validate + write the entire list inside one
 * request. On notices with ~hundreds of rows that crossed the gateway
 * timeout (504), Chrome auto-retried the multipart upload (ERR_UPLOAD_FILE_CHANGED
 * because the tmp file moved between retries), and PHP-FPM kept getting
 * killed mid-transaction — producing "Commands out of sync" cascades on
 * the shutdown handler (LiteSpeed cache + ActivityLog::flush_buffer).
 *
 * This orchestrator:
 *
 *   1. POSTs the multipart CSV to `/import-job/start` exactly ONCE.
 *   2. Loops `/import-job/batch` (small JSON requests) until the server
 *      returns `done: true`, updating the progress UI between batches.
 *   3. POSTs `/import-job/commit` to swap the staging rows in for the
 *      live list inside a short transaction.
 *
 * The window-scoped entry point keeps the existing inline submit handler
 * (which already chooses between preview and definitive flows) minimal.
 *
 * @since 6.8.1
 */
(function () {
	'use strict';

	var BATCH_SIZE_DEFAULT = 50;

	/**
	 * Run the batched import flow.
	 *
	 * @param {Object}      opts
	 * @param {number}      opts.noticeId       Notice ID.
	 * @param {File}        opts.file           The user-selected CSV file.
	 * @param {string}      opts.restRoot       Base REST URL, e.g.
	 *                                          `https://site/wp-json/ffcertificate/v1/recruitment/`.
	 * @param {string}      opts.nonce          `wp_rest` nonce.
	 * @param {HTMLElement} opts.btn            Submit button (re-enabled on finish).
	 * @param {HTMLElement} opts.status         Span node that receives the
	 *                                          final status string.
	 * @param {HTMLElement} opts.progressWrap   Wrapper revealed during the run.
	 * @param {HTMLElement} opts.progressBar    `<progress>` element.
	 * @param {HTMLElement} opts.progressText   Span node for "X / Y" text.
	 * @param {Object}      opts.strings        Localised label dictionary.
	 * @returns {Promise<void>}
	 */
	function run(opts) {
		var noticeId      = opts.noticeId;
		var file          = opts.file;
		var restRoot      = opts.restRoot;
		var nonce         = opts.nonce;
		var btn           = opts.btn;
		var statusEl      = opts.status;
		var progressWrap  = opts.progressWrap;
		var progressBar   = opts.progressBar;
		var progressText  = opts.progressText;
		var strings       = opts.strings || {};

		var baseUrl = restRoot.replace(/\/$/, '') + '/notices/' + noticeId + '/import-job';

		function setStatus(msg) {
			statusEl.textContent = msg;
		}

		function setProgress(processed, total) {
			progressBar.max   = total;
			progressBar.value = processed;
			progressText.textContent = processed + ' / ' + total;
		}

		function cleanup() {
			progressWrap.style.display = 'none';
			btn.disabled = false;
		}

		function postJson(url, payload) {
			return fetch(url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				},
				credentials: 'same-origin',
				body: JSON.stringify(payload)
			}).then(parseJsonResponse);
		}

		function postMultipart(url, formData) {
			return fetch(url, {
				method: 'POST',
				headers: { 'X-WP-Nonce': nonce },
				credentials: 'same-origin',
				body: formData
			}).then(parseJsonResponse);
		}

		// Server-side WP_Error envelopes come back as
		//   { code, message, data: { status } }
		// while success envelopes are the response shape directly. The
		// `ok` boolean we throw on lets the chain bail out without
		// dropping the WP error message.
		function parseJsonResponse(resp) {
			return resp.json().then(function (body) {
				if (resp.status >= 200 && resp.status < 300) {
					return body;
				}
				var msg = (body && body.message) ? body.message : ('HTTP ' + resp.status);
				var err = new Error(msg);
				err.fromServer = true;
				err.data = body;
				throw err;
			}).catch(function (parseErr) {
				if (parseErr && parseErr.fromServer) { throw parseErr; }
				// Server returned non-JSON (gateway timeout HTML, etc.).
				var err = new Error(strings.networkError || 'Network error');
				err.fromServer = false;
				err.underlying = parseErr;
				throw err;
			});
		}

		// Phase 1 — start.
		progressWrap.style.display = 'inline-flex';
		setProgress(0, 0);
		setStatus(strings.starting || 'Starting…');

		var fd = new FormData();
		fd.append('csv_file', file);

		return postMultipart(baseUrl + '/start', fd).then(function (start) {
			var jobId = start.job_id;
			var total = start.total;
			setProgress(0, total);
			setStatus(strings.processing || 'Processing…');

			// Phase 2 — sequential batches.
			function nextBatch() {
				return postJson(baseUrl + '/batch', { job_id: jobId, size: BATCH_SIZE_DEFAULT })
					.then(function (batch) {
						setProgress(batch.processed, batch.total);
						if (!batch.done) {
							return nextBatch();
						}
						return batch;
					});
			}

			return nextBatch().then(function () {
				// Phase 3 — commit (swap).
				setStatus(strings.committing || 'Finalising…');
				return postJson(baseUrl + '/commit', { job_id: jobId }).then(function (commit) {
					setStatus((strings.done || 'Done') + ' (' + commit.inserted + ')');
					cleanup();
					setTimeout(function () { window.location.reload(); }, 600);
				});
			});
		}).catch(function (err) {
			cleanup();
			setStatus((strings.errorPrefix || 'Error:') + ' ' + (err.message || err));
			// Re-throw so any caller-side .catch() chain still fires.
			throw err;
		});
	}

	window.ffcRecruitmentImportBatched = { run: run };
}());
