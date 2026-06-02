/**
 * Staging-based CSV import for recruitment notices (4-phase flow, V10).
 *
 * Companion to the REST endpoints under
 *   /ffcertificate/v1/recruitment/notices/{id}/import-job/{start,validate,batch,commit}
 *
 * The flow:
 *
 *   1. /import-job/start    — multipart CSV upload. The server parses
 *                             and mass-INSERTs the entire row set into
 *                             ffc_recruitment_import_staging (encrypted
 *                             at rest), returns { job_id, total }. NO
 *                             classification touched yet.
 *   2. /import-job/validate — SQL GROUP BY validation against staging.
 *                             Returns 200 with `errors: [...]`. Non-empty
 *                             list = operator must fix the CSV; we abort
 *                             and surface every line-numbered error.
 *   3. /import-job/batch    — loop: promote N rows (upsert_candidate
 *                             writes the candidate + wp_user) until
 *                             `done: true`. This is the only phase that
 *                             touches the canonical candidate table.
 *   4. /import-job/commit   — atomic transaction: DELETE prior live
 *                             list + INSERT INTO classification SELECT
 *                             FROM staging. The classification table
 *                             only sees the change here.
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
	 * Run the staging-based import flow.
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
	 * @param {HTMLElement} [opts.errorList]    Optional `<ul>` / `<div>`
	 *                                          that will hold per-line
	 *                                          validation errors when
	 *                                          /validate returns a non-
	 *                                          empty list. Falls back to
	 *                                          stuffing them into status
	 *                                          when absent.
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
		var errorListEl   = opts.errorList || null;
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

		function clearErrorList() {
			if (errorListEl) { errorListEl.innerHTML = ''; }
		}

		function renderErrorList(errors) {
			if (errorListEl) {
				errorListEl.innerHTML = '';
				errors.forEach(function (line) {
					var li = document.createElement('li');
					li.textContent = line;
					errorListEl.appendChild(li);
				});
				return;
			}
			// No dedicated container — fall back to the status node so
			// the operator still sees the first few errors.
			var preview = errors.slice(0, 3).join(' | ');
			setStatus((strings.errorPrefix || 'Error:') + ' ' + preview
				+ (errors.length > 3 ? ' (+' + (errors.length - 3) + ' more)' : ''));
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
		clearErrorList();
		progressWrap.style.display = 'inline-flex';
		setProgress(0, 0);
		setStatus(strings.ingesting || strings.starting || 'Ingesting…');

		var fd = new FormData();
		fd.append('csv_file', file);

		return postMultipart(baseUrl + '/start', fd).then(function (start) {
			var jobId = start.job_id;
			var total = start.total;
			setProgress(0, total);

			// Phase 2 — validate.
			setStatus(strings.validating || 'Validating…');
			return postJson(baseUrl + '/validate', { job_id: jobId }).then(function (validation) {
				if (validation.errors && validation.errors.length > 0) {
					// Don't try to promote a job with validation errors —
					// surface them, leave staging for the next attempt's
					// TTL sweep, and bail out without touching the
					// canonical schema.
					renderErrorList(validation.errors);
					cleanup();
					var validationErr = new Error(strings.validationFailed || 'Validation failed');
					validationErr.fromServer = true;
					validationErr.data = { errors: validation.errors };
					throw validationErr;
				}

				// Phase 3 — sequential promote batches.
				setStatus(strings.processing || 'Processing…');
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
					// Phase 4 — commit (swap).
					setStatus(strings.committing || 'Finalising…');
					return postJson(baseUrl + '/commit', { job_id: jobId }).then(function (commit) {
						setStatus((strings.done || 'Done') + ' (' + commit.inserted + ')');
						cleanup();
						setTimeout(function () { window.location.reload(); }, 600);
					});
				});
			});
		}).catch(function (err) {
			cleanup();
			// Validation-error path already populated the list/status,
			// so only overwrite for other failures.
			if (!(err && err.data && Array.isArray(err.data.errors))) {
				setStatus((strings.errorPrefix || 'Error:') + ' ' + (err.message || err));
			}
			// Re-throw so any caller-side .catch() chain still fires.
			throw err;
		});
	}

	window.ffcRecruitmentImportBatched = { run: run };
}());
