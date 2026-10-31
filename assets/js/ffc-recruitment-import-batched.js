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

	// Error code of an import that waits for the comparison to be confirmed (#2245).
	var DIFF_CODE = 'recruitment_reimport_needs_confirmation';

	// Seconds the replace button waits, the same as the promotion dialog's.
	var DIFF_COUNTDOWN = 15;

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

		// Phases 3 and 4 — promote every batch, then swap. `acknowledge`
		// rides on each batch: the server promotes a job parked in
		// `needs_ack` only when the operator accepted the mismatches.
		function promoteAndCommit(jobId, acknowledge) {
			setStatus(strings.processing || 'Processing…');
			function nextBatch() {
				var payload = { job_id: jobId, size: BATCH_SIZE_DEFAULT };
				if (acknowledge) { payload.acknowledge_identity = true; }
				return postJson(baseUrl + '/batch', payload)
					.then(function (batch) {
						setProgress(batch.processed, batch.total);
						if (!batch.done) {
							return nextBatch();
						}
						return batch;
					});
			}

			// A swap over a list that has rows answers with the comparison
			// first (#2245); the staged rows wait for the operator.
			function commit(confirm) {
				var payload = { job_id: jobId };
				if (confirm) { payload.confirm_reimport = true; }
				return postJson(baseUrl + '/commit', payload).then(function (done) {
					setStatus((strings.done || 'Done') + ' (' + done.inserted + ')');
					cleanup();
					setTimeout(function () { window.location.reload(); }, 600);
				}, function (err) {
					var body = err && err.data;
					if (confirm || !body || body.code !== DIFF_CODE || !body.data) { throw err; }
					return confirmDiff(body.data, errorListEl).then(function (ok) {
						if (!ok) {
							cleanup();
							setStatus(diffStrings().cancelled || 'Import cancelled.');
							return undefined;
						}
						return commit(true);
					});
				});
			}

			return nextBatch().then(function () {
				setStatus(strings.committing || 'Finalising…');
				return commit(false);
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

				// Rows that differ from a stored candidate wait for the
				// operator (#2245); a refusal ends the run with nothing written.
				var mismatches = validation.mismatch_messages || [];
				var acknowledged = mismatches.length === 0
					? Promise.resolve(true)
					: confirmIdentity(mismatches, errorListEl);

				return acknowledged.then(function (ok) {
					if (!ok) {
						cleanup();
						setStatus(identityStrings().cancelled || 'Import cancelled.');
						return undefined;
					}
					return promoteAndCommit(jobId, mismatches.length > 0);
				});
			});
		}).catch(function (err) {
			cleanup();
			// A refused file lists every line and value (#2245): the server
			// answers the start with the translated messages.
			var serverMessages = err && err.data && err.data.data && Array.isArray(err.data.data.messages)
				? err.data.data.messages
				: null;
			if (serverMessages && serverMessages.length > 1) {
				renderErrorList(serverMessages);
				if (errorListEl) {
					setStatus(strings.validationFailed || 'Validation failed');
				}
				throw err;
			}
			// Validation-error path already populated the list/status,
			// so only overwrite for other failures.
			if (!(err && err.data && Array.isArray(err.data.errors))) {
				setStatus((strings.errorPrefix || 'Error:') + ' ' + (err.message || err));
			}
			// Re-throw so any caller-side .catch() chain still fires.
			throw err;
		});
	}

	/**
	 * Identity strings, localised once for every recruitment screen.
	 *
	 * @returns {Object}
	 */
	function identityStrings() {
		return window.ffcRecruitmentImportIdentity || {};
	}

	/**
	 * Ask the operator to accept the rows that differ from a stored
	 * candidate (#2245). Every line is listed under the form; the shared
	 * confirm dialog repeats the first few.
	 *
	 * @param {string[]}         messages One sentence per line.
	 * @param {HTMLElement|null} listEl   List to fill, when the screen has one.
	 * @returns {Promise<boolean>} True when the operator confirmed.
	 */
	function confirmIdentity(messages, listEl) {
		var s = identityStrings();
		if (listEl) {
			listEl.innerHTML = '';
			messages.forEach(function (line) {
				var li = document.createElement('li');
				li.textContent = line;
				listEl.appendChild(li);
			});
		}
		var shown = messages.slice(0, 5);
		if (messages.length > shown.length) {
			shown.push((s.more || '+{n} more').replace('{n}', String(messages.length - shown.length)));
		}
		var admin = window.ffcRecruitmentAdmin;
		return new Promise(function (resolve) {
			if (!admin || typeof admin.openConfirmModal !== 'function') {
				resolve(window.confirm((s.body || '') + '\n\n' + shown.join('\n')));
				return;
			}
			admin.openConfirmModal({
				title: s.title || 'Some lines differ from stored candidates',
				body: s.body || '',
				consequences: shown,
				cta: s.cta || 'Import and keep stored data',
				icon: 'upload'
			}, function () { resolve(true); }, function () { resolve(false); });
		});
	}

	/**
	 * Strings of the re-import comparison, localised once (#2245).
	 *
	 * @returns {Object}
	 */
	function diffStrings() {
		return window.ffcRecruitmentImportDiff || {};
	}

	/**
	 * Ask the operator to confirm what a file changes in the list it
	 * replaces (#2245). Every row is listed under the form; the shared
	 * confirm dialog shows the counts.
	 *
	 * @param {{diff_summary: string[], diff_messages: string[]}} data Server data.
	 * @param {HTMLElement|null} listEl List to fill, when the screen has one.
	 * @returns {Promise<boolean>} True when the operator confirmed.
	 */
	function confirmDiff(data, listEl) {
		var s = diffStrings();
		var summary = data.diff_summary || [];
		var lines = data.diff_messages || [];
		if (listEl) {
			listEl.innerHTML = '';
			lines.forEach(function (line) {
				var li = document.createElement('li');
				li.textContent = line;
				listEl.appendChild(li);
			});
		}
		var admin = window.ffcRecruitmentAdmin;
		return new Promise(function (resolve) {
			if (!admin || typeof admin.openConfirmModal !== 'function') {
				resolve(window.confirm((s.body || '') + '\n\n' + summary.join('\n')));
				return;
			}
			admin.openConfirmModal({
				title: s.title || 'Replace the list?',
				body: s.body || '',
				consequences: summary,
				cta: s.cta || 'Replace list',
				icon: 'upload',
				// The plugin's countdown, so the list is read before it is replaced.
				countdown: DIFF_COUNTDOWN
			}, function () { resolve(true); }, function () { resolve(false); });
		});
	}

	/**
	 * Run a single-request import, asking the operator when the server
	 * answers that some lines differ from stored candidates, or that the
	 * file changes a list that already has rows (#2245), then sending the
	 * same file again with the answers given so far.
	 *
	 * @param {function(boolean, boolean): Promise<{status: number, body: Object}>} send
	 *        Posts the file; the arguments say whether to acknowledge the
	 *        identity mismatches and whether to confirm the comparison.
	 * @param {HTMLElement|null} listEl List for the lines to review.
	 * @returns {Promise<{status: number, body: Object}|null>} The final
	 *          response, or null when the operator declined.
	 */
	function sendWithIdentityAck(send, listEl) {
		var acknowledged = false;
		var confirmed = false;
		function attempt() {
			return send(acknowledged, confirmed).then(function (o) {
				var data = o.body && o.body.data;
				if (o.status !== 409 || !o.body || !data) {
					return o;
				}
				if (o.body.code === 'recruitment_import_identity_mismatch' && !acknowledged) {
					return confirmIdentity(data.mismatch_messages || [], listEl).then(function (ok) {
						if (!ok) { return null; }
						acknowledged = true;
						return attempt();
					});
				}
				if (o.body.code === DIFF_CODE && !confirmed) {
					return confirmDiff(data, listEl).then(function (ok) {
						if (!ok) { return null; }
						confirmed = true;
						return attempt();
					});
				}
				return o;
			});
		}
		return attempt();
	}

	window.ffcRecruitmentImportBatched = {
		run: run,
		confirmIdentity: confirmIdentity,
		confirmDiff: confirmDiff,
		sendWithIdentityAck: sendWithIdentityAck
	};
}());
