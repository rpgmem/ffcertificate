/**
 * CSV-import handlers for the recruitment admin page's "Import candidates"
 * tab, extracted from an inline <script> in class-ffc-recruitment-admin-page.php
 * (Item 10 of the frontend audit).
 *
 * The two functions stay on `window` because the markup calls them through
 * inline `onchange` / `onsubmit` attributes:
 *   - ffcRecruitmentImportNoticeChanged(select) — enable/disable the
 *     "definitive" target radio based on the picked notice's status.
 *   - ffcRecruitmentImportFromCandidates(form) — POST the CSV to the same
 *     REST endpoints the Notice Edit page uses (no new backend).
 *
 * Config (REST root, nonce, i18n strings) arrives via
 * `window.ffcRecruitmentCandidatesImport` (wp_localize_script) so the PHP
 * carries no inline interpolation.
 */
(function () {
	'use strict';

	var cfg = window.ffcRecruitmentCandidatesImport || {};
	var strings = cfg.strings || {};

	window.ffcRecruitmentImportNoticeChanged = function (sel) {
		var opt = sel.options[sel.selectedIndex];
		var st = opt ? opt.getAttribute('data-status') : '';
		var defRadio = document.querySelector('#ffc-recruitment-candidates-import input[name="list_target"][value="definitive"]');
		var prelimRadio = document.querySelector('#ffc-recruitment-candidates-import input[name="list_target"][value="preliminary"]');
		var help = document.getElementById('ffc-cand-import-target-help');
		if (st === 'preliminary') {
			defRadio.disabled = false;
			help.textContent = strings.bothLists || '';
		} else if (st === 'draft') {
			defRadio.disabled = true;
			defRadio.checked = false;
			prelimRadio.checked = true;
			help.textContent = strings.draftOnly || '';
		} else {
			defRadio.disabled = true;
			defRadio.checked = false;
			prelimRadio.checked = true;
			help.textContent = strings.pickNotice || '';
		}
	};

	window.ffcRecruitmentImportFromCandidates = function (form) {
		// Resolve controls via form.elements.namedItem rather than the legacy
		// form.<name> shorthand: it's the canonical API (works everywhere) and
		// can't be shadowed by a control happening to share a name with an
		// HTMLFormElement property.
		var els = form.elements;
		var nid = parseInt(els.namedItem('notice_id').value, 10);
		if (!(nid > 0)) {
			alert(strings.selectTarget || '');
			return false;
		}
		var target = els.namedItem('list_target').value;
		var fd = new FormData();
		fd.append('csv_file', els.namedItem('csv_file').files[0]);
		var url;
		if (target === 'definitive') {
			url = cfg.noticesRoot + nid + '/promote-preview';
			fd.append('mode', 'definitive_import');
		} else {
			url = cfg.noticesRoot + nid + '/import';
		}
		var btn = document.getElementById('ffc-cand-csv-submit');
		var status = document.getElementById('ffc-cand-csv-status');
		var progress = document.getElementById('ffc-cand-csv-progress');
		var progressText = document.getElementById('ffc-cand-csv-progress-text');
		btn.disabled = true;
		progress.style.display = 'inline-flex';
		status.textContent = '';
		var startedAt = Date.now();
		function tick() {
			var sec = Math.floor((Date.now() - startedAt) / 1000);
			progressText.textContent = (strings.processing || '') + ' ' + sec + 's ' + (strings.elapsed || '');
		}
		tick();
		var timer = setInterval(tick, 1000);
		function cleanup() {
			clearInterval(timer);
			progress.style.display = 'none';
			btn.disabled = false;
		}
		fetch(url, {
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce },
			body: fd,
			credentials: 'same-origin'
		})
			.then(function (r) {
				return r.json().then(function (d) { return { status: r.status, body: d }; });
			})
			.then(function (o) {
				cleanup();
				if (o.status >= 200 && o.status < 300) {
					status.textContent = 'OK (' + ((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body)) + ')';
					location.reload();
				} else {
					status.textContent = 'Error: ' + ((o.body && o.body.message) ? o.body.message : JSON.stringify(o.body));
				}
			})
			.catch(function (e) {
				cleanup();
				status.textContent = 'Network error: ' + e.message;
			});
		return false;
	};
}());
