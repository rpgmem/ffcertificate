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
		window.FFC.status(status, '', '');
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
		// Definitive list → single-shot /promote-preview. Lines that differ
		// from a stored candidate are confirmed once, then the file is sent
		// again with the acknowledgement (#2245).
		var url = noticesRoot + nid + '/promote-preview';
		function send(acknowledge, confirm) {
			var fd = new FormData();
			fd.append('csv_file', file);
			fd.append('mode', 'definitive_import');
			if (acknowledge) { fd.append('acknowledge_identity', '1'); }
			if (confirm) { fd.append('confirm_reimport', '1'); }
			return fetch(url, { method: 'POST', headers: { 'X-WP-Nonce': nonce }, body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); });
		}
		progress.style.display = 'inline-flex';
		progressBar.style.display = 'none';
		progressText.textContent = strings.processingCsv || '';
		function cleanup() {
			progress.style.display = 'none';
			progressBar.style.display = '';
			btn.disabled = false;
		}
		window.ffcRecruitmentImportBatched.sendWithIdentityAck(send, errorList)
			.then(function (o) {
				cleanup();
				if (o === null) {
					window.FFC.status(status, 'warning', (window.ffcRecruitmentImportIdentity || {}).cancelled || '');
					return;
				}
				if (o.status >= 200 && o.status < 300) {
					window.FFC.status(status, 'success', 'OK (' + ((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body)) + ')');
					location.reload();
				} else {
					window.FFC.status(status, 'error', 'Error: ' + ((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body)));
				}
			})
			.catch(function (e) { cleanup(); window.FFC.status(status, 'error', (strings.networkError || 'Network error:') + ' ' + e.message); });
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

	// "Select all across pages" (#2245): the prompt shows once every waiting
	// row of the page is checked, and its button extends the selection to the
	// waiting rows of every page under the current filters.
	var selectAllPages = false;

	function ffcRecruitmentSelectAllBox() {
		return document.querySelector('.ffc-rec-select-all-pages');
	}

	function ffcRecruitmentAllWaitingIds() {
		var box = ffcRecruitmentSelectAllBox();
		if (!box) { return []; }
		try {
			var ids = JSON.parse(box.getAttribute('data-ffc-waiting-ids') || '[]');
			return Array.isArray(ids) ? ids.map(function (n) { return parseInt(n, 10); }).filter(function (n) { return n > 0; }) : [];
		} catch (e) { return []; }
	}

	function ffcRecruitmentSyncSelectAll() {
		var box = ffcRecruitmentSelectAllBox();
		if (!box) { return; }
		var boxes = document.querySelectorAll('.ffc-cls-bulk-cb:not([disabled])');
		var all = boxes.length > 0;
		for (var i = 0; i < boxes.length; i++) { if (!boxes[i].checked) { all = false; break; } }
		if (!all) { selectAllPages = false; }
		box.hidden = !all;
		var pagePart = box.querySelector('[data-ffc-select-all-page]');
		var everyPart = box.querySelector('[data-ffc-select-all-every]');
		if (pagePart) { pagePart.hidden = selectAllPages; }
		if (everyPart) { everyPart.hidden = !selectAllPages; }
	}

	window.ffcRecruitmentSelectAllPages = function (on) {
		selectAllPages = !!on;
		if (!on) {
			var boxes = document.querySelectorAll('.ffc-cls-bulk-cb:not([disabled])');
			for (var i = 0; i < boxes.length; i++) { boxes[i].checked = false; }
			var head = document.getElementById('ffc-cls-bulk-all');
			if (head) { head.checked = false; }
		}
		ffcRecruitmentSyncSelectAll();
	};

	window.ffcRecruitmentClsToggleAll = function (cb) {
		var boxes = document.querySelectorAll('.ffc-cls-bulk-cb:not([disabled])');
		for (var i = 0; i < boxes.length; i++) { boxes[i].checked = cb.checked; }
		ffcRecruitmentSyncSelectAll();
	};

	document.addEventListener('change', function (e) {
		if (e.target && e.target.classList && e.target.classList.contains('ffc-cls-bulk-cb')) { ffcRecruitmentSyncSelectAll(); }
	});

	window.ffcRecruitmentBulkCall = function () {
		var status = document.getElementById('ffc-bulk-status');
		var date = document.getElementById('ffc-bulk-date').value;
		var time = document.getElementById('ffc-bulk-time').value;
		if (!date || !time) { window.FFC.status(status, 'warning', strings.bulkNoDate || ''); return; }
		var ids = [];
		if (selectAllPages) {
			ids = ffcRecruitmentAllWaitingIds();
		} else {
			var boxes = document.querySelectorAll('.ffc-cls-bulk-cb:checked');
			for (var i = 0; i < boxes.length; i++) { ids.push(parseInt(boxes[i].value, 10)); }
		}
		if (ids.length === 0) { window.FFC.status(status, 'warning', strings.bulkNoSel || ''); return; }
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
		// A past date is recorded without e-mail (#2245).
		var past = ffcRecruitmentIsPast(date, time);
		if (past) { consequences.push(strings.callPastWarning || ''); }
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
		// Ask mode (#794): surface a "Notify by email" checkbox (default on)
		// inside the confirmation modal instead of a separate native prompt.
		var askEmail = cfg.emailMode === 'ask' && !past;
		if (askEmail) {
			modalCfg.checkboxLabel = strings.notifyByEmailAsk || '';
			modalCfg.checkboxChecked = true;
		}
		window.ffcRecruitmentAdmin.openConfirmModal(modalCfg, function (sharedReason, notifyChecked) {
			var reasons = {};
			if (anyOoO) { for (var k2 = 0; k2 < ids.length; k2++) { reasons[String(ids[k2])] = sharedReason; } }
			window.FFC.status(status, 'busy', '…');
			var bulkPayload = { classification_ids: ids, date_to_assume: date, time_to_assume: time };
			if (anyOoO) { bulkPayload.out_of_order_reasons = reasons; }
			// Ask mode (#794): the modal checkbox drives the per-batch opt-in.
			if (askEmail) { bulkPayload.notify_email = !!notifyChecked; }
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
						ffcRecruitmentWarnUnreached(o.body);
						location.reload();
					} else {
						window.FFC.status(status, 'error', 'Error: ' + ((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body)));
					}
				}).catch(function (e) { window.FFC.status(status, 'error', (strings.networkError || 'Network error:') + ' ' + e.message); });
		});
	};

	// Shared dispatch for a single-classification action: POST/PUT, reload
	// on success, surface the error and re-enable the button on failure.
	// Reused by the synchronous path and the ask-mode modal callback (#794).
	// A call whose e-mail found no address on file (#2245): say so before the
	// reload, so the operator knows to reach the candidate another way.
	function ffcRecruitmentWarnUnreached(body) {
		var n = (body && Array.isArray(body.unreached)) ? body.unreached.length : 0;
		if (n > 0 && strings.noEmailContactManually) {
			alert(strings.noEmailContactManually.replace('{count}', String(n)));
		}
	}

	function ffcRecruitmentDispatchCls(btn, url, init) {
		btn.disabled = true;
		fetch(url, init).then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
			.then(function (o) {
				if (o.status >= 200 && o.status < 300) { ffcRecruitmentWarnUnreached(o.body); location.reload(); }
				else { alert((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body)); btn.disabled = false; }
			}).catch(function (e) { alert((strings.networkError || 'Network error:') + ' ' + e.message); btn.disabled = false; });
	}

	// Whether a date and time to assume are already over, against the site
	// clock the page was rendered with (#2245). `YYYY-MM-DD` and `HH:MM`
	// compare as text. The server decides again when the call is saved.
	function ffcRecruitmentIsPast(date, time) {
		if (!date || !time || !cfg.siteNow) { return false; }
		return (date + 'T' + time.slice(0, 5)) < cfg.siteNow;
	}

	// The live part of every call dialog: a past date says no e-mail is sent
	// and hides the "notify" choice, which would not apply.
	function ffcRecruitmentCallChange(values) {
		var past = ffcRecruitmentIsPast(values.date_to_assume, values.time_to_assume);
		return { warning: past ? (strings.callPastWarning || '') : '', hideCheckbox: past };
	}

	function ffcRecruitmentLastDateTime() {
		var last = { date: '', time: '' };
		try {
			last.date = localStorage.getItem('ffcRecruitmentLastBulkDate') || '';
			last.time = localStorage.getItem('ffcRecruitmentLastBulkTime') || '';
		} catch (e) {}
		return last;
	}

	function ffcRecruitmentRememberDateTime(date, time) {
		try {
			localStorage.setItem('ffcRecruitmentLastBulkDate', date);
			localStorage.setItem('ffcRecruitmentLastBulkTime', time);
		} catch (e) {}
	}

	// The confirm wears its trigger's icon (#1904).
	function ffcRecruitmentIconOf(btn) {
		var m = typeof btn.className === 'string' ? btn.className.match(/(?:^|\s)ffc-icon-([a-z0-9-]+)/) : null;
		return m ? m[1] : '';
	}

	// One styled dialog per action (#2245): the browser prompts are gone.
	window.ffcRecruitmentClsAct = function (btn) {
		var id = btn.getAttribute('data-cls-id');
		var action = btn.getAttribute('data-cls-action');
		var base = classRoot;
		var modal = window.ffcRecruitmentAdmin;
		if (action === 'call') {
			// Out-of-order is detected first, so the dialog asks for the
			// justification together with the date and time.
			var ooo = false;
			var tr = document.querySelector('tr[data-cls-id="' + id + '"]');
			if (tr) {
				var rank = parseInt(tr.getAttribute('data-cls-rank'), 10);
				var adj = tr.getAttribute('data-cls-adjutancy');
				var lowest = ffcRecruitmentLowestEmpty();
				ooo = !!(lowest[adj] && rank > lowest[adj]);
			}
			var last = ffcRecruitmentLastDateTime();
			var askEmail = cfg.emailMode === 'ask';
			modal.openConfirmModal({
				title: strings.callModalTitle || '',
				consequences: ooo ? [strings.confirmOooSingle || ''] : [],
				cta: strings.callModalCta || '',
				style: ooo ? 'destructive' : 'primary',
				icon: ffcRecruitmentIconOf(btn),
				fields: [
					{ name: 'date_to_assume', label: strings.dateToAssume || '', type: 'date', value: last.date, required: true },
					{ name: 'time_to_assume', label: strings.timeToAssume || '', type: 'time', value: last.time, required: true }
				],
				onChange: ffcRecruitmentCallChange,
				reasonLabel: ooo ? (strings.promptOooReason || '') : '',
				checkboxLabel: askEmail ? (strings.notifyByEmailAsk || '') : '',
				checkboxChecked: true
			}, function (oooReason, notifyChecked, values) {
				var fd = new FormData();
				fd.append('date_to_assume', values.date_to_assume);
				fd.append('time_to_assume', values.time_to_assume);
				if (ooo && oooReason) { fd.append('out_of_order_reason', oooReason); }
				if (askEmail && notifyChecked !== null) { fd.append('notify_email', notifyChecked ? '1' : '0'); }
				ffcRecruitmentRememberDateTime(values.date_to_assume, values.time_to_assume);
				ffcRecruitmentDispatchCls(btn, base + id + '/call', { method: 'POST', headers: { 'X-WP-Nonce': nonce }, body: fd, credentials: 'same-origin' });
			});
			return;
		}
		if (action === 'cancel' || action === 'reopen' || action === 'override') {
			// Cancel and reopen end the active call through its own route
			// (#2245), so the call row is cancelled with the status: no
			// `.ics`, nothing in "My calls", and the next call is the only
			// active one. Override undoes a realized decision on its own
			// audited endpoint.
			var callId = btn.getAttribute('data-call-id');
			if (action !== 'override' && !callId) { return; }
			var copy = {
				cancel: { title: strings.cancelModalTitle, body: strings.cancelModalBody, cta: strings.cancelModalCta, reason: strings.cancellationReason },
				reopen: { title: strings.reopenModalTitle, body: strings.reopenModalBody, cta: strings.reopenModalCta, reason: strings.reopenReason },
				override: { title: strings.overrideModalTitle, body: strings.confirmOverride, cta: strings.overrideModalCta, reason: strings.overrideReason }
			}[action];
			modal.openConfirmModal({
				title: copy.title || '',
				body: copy.body || '',
				cta: copy.cta || '',
				style: 'destructive',
				icon: ffcRecruitmentIconOf(btn),
				reasonLabel: copy.reason || ''
			}, function (reason) {
				var p = new URLSearchParams();
				p.append('reason', reason);
				var url = action === 'override' ? base + id + '/override-to-empty' : base + id + '/calls/' + callId + '/cancel';
				ffcRecruitmentDispatchCls(btn, url, { method: 'POST', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/x-www-form-urlencoded' }, body: p.toString(), credentials: 'same-origin' });
			});
			return;
		}
		var p3 = new URLSearchParams();
		p3.append('status', action);
		ffcRecruitmentDispatchCls(btn, base + id + '/status', { method: 'PUT', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/x-www-form-urlencoded' }, body: p3.toString(), credentials: 'same-origin' });
	};

	// -- Section 7: preliminary preview-status + reason dropdowns -------

	function ffcRecruitmentPreviewMarkRequired(reasonSel, required) {
		reasonSel.setAttribute('aria-required', required ? 'true' : 'false');
	}

	function ffcRecruitmentPreviewSync(row) {
		var id = row.getAttribute('data-cls-id');
		var statusSel = row.querySelector('.ffc-cls-preview-status');
		var reasonSel = row.querySelector('.ffc-cls-preview-reason');
		var status = statusSel.value;
		var reasonId = parseInt(reasonSel.value, 10) || 0;
		// Client-side check: a status that requires a reason but is left at "none"
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
