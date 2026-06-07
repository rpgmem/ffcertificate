/**
 * Recruitment Notice Edit page — all inline-script handlers, extracted from
 * the seven `echo '<script>'` blocks in
 * class-ffc-recruitment-notice-edit-page-renderer.php (Item 10 of the
 * frontend audit).
 *
 * The functions stay on `window` because the markup invokes them through
 * inline onclick/onsubmit attributes (the page is rendered server-side and
 * each row/button wires its own handler). Config — REST root, nonce, the
 * per-status "reason required" flags, and every i18n string — arrives once
 * via `window.ffcRecruitmentNoticeEdit` (wp_localize_script), so the PHP
 * carries no inline interpolation. Per-instance data (the notice id, the
 * out-of-order empties map) rides on DOM data-attributes.
 *
 * Depends on the recruitment admin bundle for the shared confirm modal
 * (`window.ffcRecruitmentAdmin.openConfirmModal`) and, for the preliminary
 * CSV import, the batched importer (`window.ffcRecruitmentImportBatched`).
 */
(function () {
	'use strict';

	var cfg = window.ffcRecruitmentNoticeEdit || {};
	var strings = cfg.strings || {};
	var importStrings = cfg.importStrings || {};
	var reasonRequired = cfg.reasonRequired || {};
	var restRoot = cfg.restRoot || '';
	var nonce = cfg.nonce || '';
	var noticesRoot = restRoot + 'notices/';
	var classRoot = restRoot + 'classifications/';

	// -- Section 1: CSV import from the edit page -----------------------

	window.ffcRecruitmentImportFromEdit = function (form) {
		var nid = parseInt(form.getAttribute('data-notice-id'), 10);
		var els = form.elements;
		var target = els.namedItem('list_target').value;
		var file = els.namedItem('csv_file').files[0];
		var btn = document.getElementById('ffc-edit-csv-submit');
		var status = document.getElementById('ffc-edit-csv-status');
		var progress = document.getElementById('ffc-edit-csv-progress');
		var progressBar = document.getElementById('ffc-edit-csv-progress-bar');
		var progressText = document.getElementById('ffc-edit-csv-progress-text');
		var errorList = document.getElementById('ffc-edit-csv-errors');
		btn.disabled = true;
		status.textContent = '';
		if (errorList) { errorList.innerHTML = ''; }
		if (target !== 'definitive' && window.ffcRecruitmentImportBatched) {
			// Preview list → staging-based orchestrator (4 phases).
			window.ffcRecruitmentImportBatched.run({
				noticeId: nid,
				file: file,
				restRoot: restRoot,
				nonce: nonce,
				btn: btn,
				status: status,
				progressWrap: progress,
				progressBar: progressBar,
				progressText: progressText,
				errorList: errorList,
				strings: importStrings
			}).catch(function () {});
			return false;
		}
		// Definitive list → single-shot /promote-preview (unchanged).
		var fd = new FormData();
		fd.append('csv_file', file);
		fd.append('mode', 'definitive_import');
		var url = noticesRoot + nid + '/promote-preview';
		progress.style.display = 'inline-flex';
		progressBar.style.display = 'none';
		progressText.textContent = strings.processingCsv || '';
		function cleanup() {
			progress.style.display = 'none';
			progressBar.style.display = '';
			btn.disabled = false;
		}
		fetch(url, { method: 'POST', headers: { 'X-WP-Nonce': nonce }, body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
			.then(function (o) {
				cleanup();
				if (o.status >= 200 && o.status < 300) {
					status.textContent = 'OK (' + ((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body)) + ')';
					location.reload();
				} else {
					status.textContent = 'Error: ' + ((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body));
				}
			})
			.catch(function (e) { cleanup(); status.textContent = (strings.networkError || 'Network error:') + ' ' + e.message; });
		return false;
	};

	// -- Section 2: snapshot-promote preliminary → definitive -----------

	window.ffcRecruitmentSnapshotPromote = function (nid, btn) {
		if (btn && btn.getAttribute('data-ffc-confirm-ok') !== '1') { return false; }
		var fd = new FormData();
		fd.append('mode', 'snapshot');
		fetch(noticesRoot + nid + '/promote-preview', {
			method: 'POST', headers: { 'X-WP-Nonce': nonce }, body: fd, credentials: 'same-origin'
		}).then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
			.then(function (o) {
				if (o.status >= 200 && o.status < 300) { location.reload(); }
				else { alert((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body)); }
			}).catch(function (e) { alert((strings.networkError || 'Network error:') + ' ' + e.message); });
	};

	// -- Section 3: attach / detach adjutancy ---------------------------

	window.ffcAttachAdjutancy = function (form) {
		var nid = form.getAttribute('data-notice');
		var aid = form.elements.namedItem('adjutancy_id').value;
		fetch(noticesRoot + nid + '/adjutancies/' + aid, {
			method: 'PUT', headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin'
		}).then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
			.then(function (o) {
				if (o.status >= 200 && o.status < 300) { location.reload(); }
				else { alert((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body)); }
			});
		return false;
	};

	window.ffcDetachAdjutancy = function (a) {
		var nid = a.getAttribute('data-notice');
		var aid = a.getAttribute('data-adjutancy');
		fetch(noticesRoot + nid + '/adjutancies/' + aid, {
			method: 'DELETE', headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin'
		}).then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
			.then(function (o) {
				if (o.status >= 200 && o.status < 300) { location.reload(); }
				else { alert((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body)); }
			});
		return false;
	};

	// -- Section 4: classification tab switch ---------------------------

	window.ffcRecruitmentClsTabSwitch = function (a) {
		var key = a.getAttribute('data-ffc-clstab');
		var nav = a.parentNode;
		var tabs = nav.querySelectorAll('.nav-tab');
		for (var i = 0; i < tabs.length; i++) { tabs[i].classList.remove('nav-tab-active'); }
		a.classList.add('nav-tab-active');
		var panels = nav.parentNode.querySelectorAll('[data-ffc-clspanel]');
		for (var j = 0; j < panels.length; j++) {
			panels[j].style.display = panels[j].getAttribute('data-ffc-clspanel') === key ? 'block' : 'none';
		}
		try {
			var u = new URL(window.location.href);
			u.searchParams.set('ffc_cls_tab', key);
			history.replaceState(null, '', u.toString());
		} catch (e) {}
		return false;
	};

	// -- Section 5: bulk date/time prefill from localStorage ------------

	function prefillBulkDateTime() {
		try {
			var d = localStorage.getItem('ffcRecruitmentLastBulkDate');
			var t = localStorage.getItem('ffcRecruitmentLastBulkTime');
			var dEl = document.getElementById('ffc-bulk-date');
			var tEl = document.getElementById('ffc-bulk-time');
			if (d && dEl) { dEl.value = d; }
			if (t && tEl) { tEl.value = t; }
		} catch (e) {}
	}

	// -- Section 6: classification actions (call / bulk-call / status) --

	// Out-of-order detection reads the AUTHORITATIVE empties map from the
	// definitive panel's data-ffc-empties attribute (server-built from the
	// full, unfiltered/unpaginated queue — compute_empties_by_adjutancy /
	// #Item7). Shape: { adjutancySlug: [{id,rank}, …] }.
	function ffcRecruitmentEmptiesMap() {
		var panel = document.querySelector('[data-ffc-clspanel="definitive"]');
		if (!panel) { return {}; }
		try { return JSON.parse(panel.getAttribute('data-ffc-empties') || '{}'); } catch (e) { return {}; }
	}

	// Lowest-rank `empty` per adjutancy (single-row Call handler).
	function ffcRecruitmentLowestEmpty() {
		var empties = ffcRecruitmentEmptiesMap();
		var lowest = {};
		for (var adj in empties) {
			var list = empties[adj];
			for (var i = 0; i < list.length; i++) {
				if (!(adj in lowest) || list[i].rank < lowest[adj]) { lowest[adj] = list[i].rank; }
			}
		}
		return lowest;
	}

	window.ffcRecruitmentClsToggleAll = function (cb) {
		var boxes = document.querySelectorAll('.ffc-cls-bulk-cb:not([disabled])');
		for (var i = 0; i < boxes.length; i++) { boxes[i].checked = cb.checked; }
	};

	window.ffcRecruitmentBulkCall = function () {
		var status = document.getElementById('ffc-bulk-status');
		var date = document.getElementById('ffc-bulk-date').value;
		var time = document.getElementById('ffc-bulk-time').value;
		if (!date || !time) { status.textContent = strings.bulkNoDate || ''; return; }
		var ids = [];
		var boxes = document.querySelectorAll('.ffc-cls-bulk-cb:checked');
		for (var i = 0; i < boxes.length; i++) { ids.push(parseInt(boxes[i].value, 10)); }
		if (ids.length === 0) { status.textContent = strings.bulkNoSel || ''; return; }
		// Out-of-order detection: per adjutancy, find the lowest-rank empty
		// row NOT in this selection (the "threshold"); any selected row with
		// rank > threshold means the bulk would skip someone. Comparing
		// against the global lowest-empty would false-flag an in-order bulk
		// that includes the lowest rank itself.
		var emptyByAdj = ffcRecruitmentEmptiesMap();
		for (var k in emptyByAdj) { emptyByAdj[k].sort(function (a, b) { return a.rank - b.rank; }); }
		var selSet = {};
		for (var s = 0; s < ids.length; s++) { selSet[String(ids[s])] = true; }
		var anyOoO = false;
		for (var adjKey in emptyByAdj) {
			var threshold = Infinity;
			var rows = emptyByAdj[adjKey];
			for (var t = 0; t < rows.length; t++) {
				if (!selSet[String(rows[t].id)]) { threshold = rows[t].rank; break; }
			}
			for (var u = 0; u < rows.length; u++) {
				if (selSet[String(rows[u].id)] && rows[u].rank > threshold) { anyOoO = true; break; }
			}
			if (anyOoO) { break; }
		}
		// Build modal config dynamically — copy/style/reason-gate depend on
		// whether OOO was detected. Confirmation goes through the shared
		// confirm modal so the operator gets the destructive-transition look.
		var consequences = [strings.bulkConsequenceAtomic || '', strings.bulkConsequenceLog || ''];
		if (anyOoO) { consequences.push(strings.bulkConsequenceOoo || ''); }
		var bodyTpl = strings.bulkModalBodyTpl || '';
		var bodyText = bodyTpl.replace('{count}', String(ids.length)).replace('{date}', date).replace('{time}', time);
		var modalCfg = {
			title: strings.bulkModalTitle || '',
			body: bodyText,
			consequences: consequences,
			cta: strings.bulkModalCta || '',
			style: anyOoO ? 'destructive' : 'primary',
			reasonLabel: anyOoO ? (strings.bulkReasonLabel || '') : ''
		};
		window.ffcRecruitmentAdmin.openConfirmModal(modalCfg, function (sharedReason) {
			var reasons = {};
			if (anyOoO) { for (var k2 = 0; k2 < ids.length; k2++) { reasons[String(ids[k2])] = sharedReason; } }
			status.textContent = '…';
			var bulkPayload = { classification_ids: ids, date_to_assume: date, time_to_assume: time };
			if (anyOoO) { bulkPayload.out_of_order_reasons = reasons; }
			fetch(classRoot + 'bulk-call', {
				method: 'POST',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				body: JSON.stringify(bulkPayload),
				credentials: 'same-origin'
			}).then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
				.then(function (o) {
					if (o.status >= 200 && o.status < 300) {
						// Remember the values so the next bulk call opens pre-filled.
						try {
							localStorage.setItem('ffcRecruitmentLastBulkDate', date);
							localStorage.setItem('ffcRecruitmentLastBulkTime', time);
						} catch (e) {}
						location.reload();
					} else {
						status.textContent = 'Error: ' + ((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body));
					}
				}).catch(function (e) { status.textContent = (strings.networkError || 'Network error:') + ' ' + e.message; });
		});
	};

	window.ffcRecruitmentClsAct = function (btn) {
		var id = btn.getAttribute('data-cls-id');
		var action = btn.getAttribute('data-cls-action');
		var base = classRoot;
		var url, init;
		if (action === 'call') {
			// Out-of-order is detected BEFORE asking date/time so the admin
			// sees the warning/justification step at the top of the flow.
			var oooReason = '';
			var tr = document.querySelector('tr[data-cls-id="' + id + '"]');
			if (tr) {
				var rank = parseInt(tr.getAttribute('data-cls-rank'), 10);
				var adj = tr.getAttribute('data-cls-adjutancy');
				var lowest = ffcRecruitmentLowestEmpty();
				if (lowest[adj] && rank > lowest[adj]) {
					if (!confirm(strings.confirmOooSingle || '')) { return; }
					oooReason = prompt(strings.promptOooReason || '') || '';
					if (!oooReason.trim()) { alert(strings.reasonRequired || ''); return; }
				}
			}
			var date = prompt(strings.dateToAssume || '');
			if (!date) { return; }
			var time = prompt(strings.timeToAssume || '');
			if (!time) { return; }
			var fd = new FormData();
			fd.append('date_to_assume', date);
			fd.append('time_to_assume', time);
			if (oooReason) { fd.append('out_of_order_reason', oooReason); }
			url = base + id + '/call';
			init = { method: 'POST', headers: { 'X-WP-Nonce': nonce }, body: fd, credentials: 'same-origin' };
		} else if (action === 'cancel') {
			var reason = prompt(strings.cancellationReason || '');
			if (!reason) { return; }
			var p = new URLSearchParams();
			p.append('status', 'empty');
			p.append('reason', reason);
			url = base + id + '/status';
			init = { method: 'PUT', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/x-www-form-urlencoded' }, body: p.toString(), credentials: 'same-origin' };
		} else if (action === 'reopen') {
			var reason2 = prompt(strings.reopenReason || '');
			if (!reason2) { return; }
			var p2 = new URLSearchParams();
			p2.append('status', 'empty');
			p2.append('reason', reason2);
			url = base + id + '/status';
			init = { method: 'PUT', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/x-www-form-urlencoded' }, body: p2.toString(), credentials: 'same-origin' };
		} else if (action === 'override') {
			// Admin override (#Item 8): undo a realized hired/withdrew/not_shown
			// decision. Destructive — confirm first, then require an audited
			// reason. Hits the dedicated endpoint that bypasses the terminal
			// guard and the reopen-freeze server-side.
			if (!confirm(strings.confirmOverride || '')) { return; }
			var oReason = prompt(strings.overrideReason || '');
			if (!oReason || !oReason.trim()) { return; }
			var po = new URLSearchParams();
			po.append('reason', oReason);
			url = base + id + '/override-to-empty';
			init = { method: 'POST', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/x-www-form-urlencoded' }, body: po.toString(), credentials: 'same-origin' };
		} else {
			var p3 = new URLSearchParams();
			p3.append('status', action);
			url = base + id + '/status';
			init = { method: 'PUT', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/x-www-form-urlencoded' }, body: p3.toString(), credentials: 'same-origin' };
		}
		btn.disabled = true;
		fetch(url, init).then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
			.then(function (o) {
				if (o.status >= 200 && o.status < 300) { location.reload(); }
				else { alert((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body)); btn.disabled = false; }
			}).catch(function (e) { alert((strings.networkError || 'Network error:') + ' ' + e.message); btn.disabled = false; });
	};

	// -- Section 7: preliminary preview-status + reason dropdowns -------

	function ffcRecruitmentPreviewMarkRequired(reasonSel, required) {
		reasonSel.style.outline = required ? '2px solid #d63638' : '';
		reasonSel.style.outlineOffset = required ? '2px' : '';
		reasonSel.setAttribute('aria-required', required ? 'true' : 'false');
	}

	function ffcRecruitmentPreviewSync(row) {
		var id = row.getAttribute('data-cls-id');
		var statusSel = row.querySelector('.ffc-cls-preview-status');
		var reasonSel = row.querySelector('.ffc-cls-preview-reason');
		var status = statusSel.value;
		var reasonId = parseInt(reasonSel.value, 10) || 0;
		// Preflight: a status that requires a reason but is left at "none"
		// flags the dropdown red and skips the PATCH (the server still
		// enforces it as a backstop).
		if (reasonRequired[status] === true && reasonId <= 0) {
			ffcRecruitmentPreviewMarkRequired(reasonSel, true);
			return;
		}
		ffcRecruitmentPreviewMarkRequired(reasonSel, false);
		var fd = new FormData();
		fd.append('preview_status', status);
		if (reasonId > 0) { fd.append('preview_reason_id', String(reasonId)); }
		fetch(classRoot + id + '/preview-status', {
			method: 'POST',
			headers: { 'X-WP-Nonce': nonce, 'X-HTTP-Method-Override': 'PATCH' },
			body: fd,
			credentials: 'same-origin'
		}).then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
			.then(function (o) {
				if (o.status >= 200 && o.status < 300) { return; }
				alert((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body));
			});
	}

	function wirePreviewStatusRows() {
		document.querySelectorAll('tr[data-cls-id]').forEach(function (row) {
			var statusSel = row.querySelector('.ffc-cls-preview-status');
			var reasonSel = row.querySelector('.ffc-cls-preview-reason');
			if (!statusSel || !reasonSel) { return; }
			statusSel.addEventListener('change', function () {
				var status = statusSel.value;
				var opts = reasonSel.querySelectorAll('option[data-applies]');
				opts.forEach(function (opt) {
					var applies = (opt.getAttribute('data-applies') || '').split(',');
					var allowed = applies.length === 0 || applies[0] === '' || applies.indexOf(status) !== -1;
					opt.style.display = allowed ? '' : 'none';
				});
				if (status === 'empty') {
					reasonSel.value = '0';
					reasonSel.disabled = true;
					ffcRecruitmentPreviewMarkRequired(reasonSel, false);
				} else {
					reasonSel.disabled = false;
					// Reset to "none" if the prior reason no longer applies.
					var current = reasonSel.options[reasonSel.selectedIndex];
					if (current && current.style.display === 'none') { reasonSel.value = '0'; }
				}
				ffcRecruitmentPreviewSync(row);
			});
			reasonSel.addEventListener('change', function () { ffcRecruitmentPreviewSync(row); });
		});
	}

	// -- DOM-ready wiring (sections 5 + 7) ------------------------------
	// The page enqueues this asset in the footer, so the document may
	// already be parsed; bind immediately when it is, else wait.
	function init() {
		prefillBulkDateTime();
		wirePreviewStatusRows();
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
