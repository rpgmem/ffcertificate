// Tests for `assets/js/ffc-recruitment-notice-edit.js`.
//
// The consolidated Notice Edit page handlers, extracted from the seven
// inline <script> blocks in
// class-ffc-recruitment-notice-edit-page-renderer.php (Item 10 of the
// frontend audit). Config comes from window.ffcRecruitmentNoticeEdit; the
// functions are global because the markup wires them via inline
// onclick/onsubmit.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// FFC.status() draws the status lines beside the buttons (#1843).
loadScript('assets/js/ffc-core.js');

const SCRIPT = 'assets/js/ffc-recruitment-notice-edit.js';

const CFG = {
	restRoot: 'https://example.com/wp-json/ffcertificate/v1/recruitment/',
	nonce: 'rest-nonce',
	reasonRequired: { denied: true, granted: false, appeal_denied: false, appeal_granted: false },
	importStrings: { ingesting: 'Ingesting…' },
	strings: {
		processingCsv: 'Processing CSV…',
		bulkNoSel: 'NO_SEL',
		bulkNoDate: 'NO_DATE',
		confirmOooSingle: 'CONFIRM_OOO',
		promptOooReason: 'PROMPT_OOO',
		bulkModalTitle: 'BULK_TITLE',
		bulkModalBodyTpl: 'About to issue {count} call(s) for {date} at {time}.',
		bulkModalCta: 'BULK_CTA',
		bulkConsequenceAtomic: 'ATOMIC',
		bulkConsequenceLog: 'LOG',
		bulkConsequenceOoo: 'OOO',
		bulkReasonLabel: 'OOO_LABEL',
		dateToAssume: 'DATE?',
		timeToAssume: 'TIME?',
		cancellationReason: 'CANCEL?',
		reopenReason: 'REOPEN?',
		confirmOverride: 'OVERRIDE_CONFIRM?',
		overrideReason: 'OVERRIDE_REASON?',
		notifyByEmailAsk: 'NOTIFY?',
	},
};

const NOTICES = CFG.restRoot + 'notices/';
const CLASS = CFG.restRoot + 'classifications/';

let fetchSpy;

beforeEach(() => {
	window.ffcRecruitmentNoticeEdit = CFG;
	// The definitive import asks about identity mismatches through the
	// batched importer's shared helper (#2245).
	loadScript('assets/js/ffc-recruitment-import-batched.js');
	window.localStorage.clear();
	fetchSpy = vi.fn(() => new Promise(() => {})); // never resolves → no reload
	window.fetch = fetchSpy;
});

afterEach(() => {
	document.body.innerHTML = '';
	delete window.ffcRecruitmentNoticeEdit;
	delete window.ffcRecruitmentAdmin;
	delete window.ffcRecruitmentImportBatched;
	[
		'ffcRecruitmentImportFromEdit', 'ffcRecruitmentSnapshotPromote',
		'ffcAttachAdjutancy', 'ffcDetachAdjutancy', 'ffcRecruitmentClsTabSwitch',
		'ffcRecruitmentClsToggleAll', 'ffcRecruitmentBulkCall', 'ffcRecruitmentClsAct',
	].forEach((f) => { delete window[f]; });
	vi.restoreAllMocks();
});

// ---- Section 4: tab switch ------------------------------------------

describe('ffcRecruitmentClsTabSwitch', () => {
	it('activates the clicked tab and shows the matching panel', () => {
		document.body.innerHTML = `
			<div class="wrap">
				<h2 class="nav-tab-wrapper">
					<a class="nav-tab nav-tab-active" data-ffc-clstab="preliminary">P</a>
					<a class="nav-tab" data-ffc-clstab="definitive">D</a>
				</h2>
				<div data-ffc-clspanel="preliminary" style="display:block;">prelim</div>
				<div data-ffc-clspanel="definitive" style="display:none;">def</div>
			</div>`;
		loadScript(SCRIPT);

		const defTab = document.querySelector('[data-ffc-clstab="definitive"]');
		const ret = window.ffcRecruitmentClsTabSwitch(defTab);

		expect(ret).toBe(false);
		expect(defTab.classList.contains('nav-tab-active')).toBe(true);
		expect(document.querySelector('[data-ffc-clspanel="definitive"]').style.display).toBe('block');
		expect(document.querySelector('[data-ffc-clspanel="preliminary"]').style.display).toBe('none');
	});
});

// ---- Section 1: import from edit ------------------------------------

describe('ffcRecruitmentImportFromEdit', () => {
	function installImportForm(target) {
		document.body.innerHTML = `
			<form id="ffc-recruitment-edit-import" data-notice-id="7">
				<input type="radio" name="list_target" value="${target}" checked>
				<input type="file" name="csv_file">
			</form>
			<button id="ffc-edit-csv-submit"></button>
			<span id="ffc-edit-csv-status"></span>
			<span id="ffc-edit-csv-progress"></span>
			<span id="ffc-edit-csv-progress-bar"></span>
			<span id="ffc-edit-csv-progress-text"></span>
			<ul id="ffc-edit-csv-errors"></ul>`;
	}

	it('hands the preliminary flow to the batched importer with the notice id', () => {
		installImportForm('preliminary');
		const run = vi.fn(() => Promise.resolve());
		window.ffcRecruitmentImportBatched = { run };
		loadScript(SCRIPT);

		const ret = window.ffcRecruitmentImportFromEdit(document.getElementById('ffc-recruitment-edit-import'));

		expect(ret).toBe(false);
		expect(run).toHaveBeenCalledTimes(1);
		const opts = run.mock.calls[0][0];
		expect(opts.noticeId).toBe(7);
		expect(opts.restRoot).toBe(CFG.restRoot);
		expect(opts.nonce).toBe('rest-nonce');
		expect(opts.strings).toBe(CFG.importStrings);
	});

	it('POSTs the definitive flow to /promote-preview', () => {
		installImportForm('definitive');
		loadScript(SCRIPT);

		window.ffcRecruitmentImportFromEdit(document.getElementById('ffc-recruitment-edit-import'));

		expect(fetchSpy).toHaveBeenCalledTimes(1);
		expect(fetchSpy.mock.calls[0][0]).toBe(NOTICES + '7/promote-preview');
		const body = fetchSpy.mock.calls[0][1].body;
		expect(body.get('mode')).toBe('definitive_import');
		expect(document.getElementById('ffc-edit-csv-progress-text').textContent).toBe('Processing CSV…');
	});
});

// ---- Section 2: snapshot promote ------------------------------------

describe('ffcRecruitmentSnapshotPromote', () => {
	it('aborts (opens modal only) until data-ffc-confirm-ok=1', () => {
		document.body.innerHTML = '<button id="b"></button>';
		loadScript(SCRIPT);
		const btn = document.getElementById('b');

		expect(window.ffcRecruitmentSnapshotPromote(5, btn)).toBe(false);
		expect(fetchSpy).not.toHaveBeenCalled();
	});

	it('POSTs the snapshot once confirmed', () => {
		document.body.innerHTML = '<button id="b" data-ffc-confirm-ok="1"></button>';
		loadScript(SCRIPT);

		window.ffcRecruitmentSnapshotPromote(5, document.getElementById('b'));

		expect(fetchSpy.mock.calls[0][0]).toBe(NOTICES + '5/promote-preview');
		expect(fetchSpy.mock.calls[0][1].body.get('mode')).toBe('snapshot');
	});
});

// ---- Section 3: attach / detach adjutancy ---------------------------

describe('attach / detach adjutancy', () => {
	it('ffcAttachAdjutancy PUTs to the notice adjutancy endpoint', () => {
		document.body.innerHTML = `
			<form data-notice="3"><select name="adjutancy_id"><option value="9" selected>9</option></select></form>`;
		loadScript(SCRIPT);

		const ret = window.ffcAttachAdjutancy(document.querySelector('form'));

		expect(ret).toBe(false);
		expect(fetchSpy.mock.calls[0][0]).toBe(NOTICES + '3/adjutancies/9');
		expect(fetchSpy.mock.calls[0][1].method).toBe('PUT');
	});

	it('ffcDetachAdjutancy DELETEs using the link data-attributes', () => {
		document.body.innerHTML = '<a data-notice="3" data-adjutancy="9">x</a>';
		loadScript(SCRIPT);

		window.ffcDetachAdjutancy(document.querySelector('a'));

		expect(fetchSpy.mock.calls[0][0]).toBe(NOTICES + '3/adjutancies/9');
		expect(fetchSpy.mock.calls[0][1].method).toBe('DELETE');
	});
});

// ---- Section 5: bulk prefill ----------------------------------------

describe('bulk date/time prefill on load', () => {
	it('fills the inputs from localStorage', () => {
		window.localStorage.setItem('ffcRecruitmentLastBulkDate', '2026-05-20');
		window.localStorage.setItem('ffcRecruitmentLastBulkTime', '09:00');
		document.body.innerHTML = '<input id="ffc-bulk-date"><input id="ffc-bulk-time">';
		loadScript(SCRIPT);

		expect(document.getElementById('ffc-bulk-date').value).toBe('2026-05-20');
		expect(document.getElementById('ffc-bulk-time').value).toBe('09:00');
	});
});

// ---- Section 6: bulk call + per-row actions -------------------------

describe('ffcRecruitmentBulkCall', () => {
	function installBulk(empties) {
		document.body.innerHTML = `
			<div data-ffc-clspanel="definitive" data-ffc-empties='${JSON.stringify(empties)}'></div>
			<input id="ffc-bulk-date"><input id="ffc-bulk-time">
			<span id="ffc-bulk-status"></span>
			<input type="checkbox" class="ffc-cls-bulk-cb" value="10">
			<input type="checkbox" class="ffc-cls-bulk-cb" value="11">`;
	}

	it('refuses without date/time', () => {
		installBulk({});
		loadScript(SCRIPT);
		window.ffcRecruitmentBulkCall();
		expect(document.getElementById('ffc-bulk-status').textContent).toBe('NO_DATE');
	});

	it('refuses with no rows selected', () => {
		installBulk({});
		loadScript(SCRIPT);
		document.getElementById('ffc-bulk-date').value = '2026-05-20';
		document.getElementById('ffc-bulk-time').value = '09:00';
		window.ffcRecruitmentBulkCall();
		expect(document.getElementById('ffc-bulk-status').textContent).toBe('NO_SEL');
	});

	it('opens the confirm modal without OOO when the selection is in order', () => {
		// Empties [10,11] both selected → no skip → primary, no reason gate.
		installBulk({ 'adj-1': [{ id: 10, rank: 1 }, { id: 11, rank: 2 }] });
		const openConfirmModal = vi.fn();
		window.ffcRecruitmentAdmin = { openConfirmModal };
		loadScript(SCRIPT);
		document.getElementById('ffc-bulk-date').value = '2026-05-20';
		document.getElementById('ffc-bulk-time').value = '09:00';
		document.querySelectorAll('.ffc-cls-bulk-cb').forEach((cb) => { cb.checked = true; });

		window.ffcRecruitmentBulkCall();

		expect(openConfirmModal).toHaveBeenCalledTimes(1);
		const cfg = openConfirmModal.mock.calls[0][0];
		expect(cfg.style).toBe('primary');
		expect(cfg.reasonLabel).toBe('');
		expect(cfg.body).toBe('About to issue 2 call(s) for 2026-05-20 at 09:00.');
	});

	it('warns that a past bulk date sends no e-mail and drops the notify box', () => {
		installBulk({ 'adj-1': [{ id: 10, rank: 1 }, { id: 11, rank: 2 }] });
		const openConfirmModal = vi.fn((cfg, cb) => cb('', true));
		window.ffcRecruitmentAdmin = { openConfirmModal };
		window.ffcRecruitmentNoticeEdit = { ...CFG, emailMode: 'ask', siteNow: '2026-05-21T08:00', strings: { ...CFG.strings, callPastWarning: 'PAST' } };
		loadScript(SCRIPT);
		document.getElementById('ffc-bulk-date').value = '2026-05-20';
		document.getElementById('ffc-bulk-time').value = '09:00';
		document.querySelectorAll('.ffc-cls-bulk-cb').forEach((cb) => { cb.checked = true; });

		window.ffcRecruitmentBulkCall();

		const cfg = openConfirmModal.mock.calls[0][0];
		expect(cfg.consequences).toContain('PAST');
		expect(cfg.checkboxLabel).toBeUndefined();
		expect(JSON.parse(fetchSpy.mock.calls[0][1].body).notify_email).toBeUndefined();
	});

	it('flags OOO when the selection skips a higher-ranked empty', () => {
		// Empty rank 1 (id 9) is NOT selected; selecting rank-2 (id 11) skips it.
		installBulk({ 'adj-1': [{ id: 9, rank: 1 }, { id: 11, rank: 2 }] });
		const openConfirmModal = vi.fn();
		window.ffcRecruitmentAdmin = { openConfirmModal };
		loadScript(SCRIPT);
		document.getElementById('ffc-bulk-date').value = '2026-05-20';
		document.getElementById('ffc-bulk-time').value = '09:00';
		// Only id 11 selected.
		document.querySelector('.ffc-cls-bulk-cb[value="11"]').checked = true;

		window.ffcRecruitmentBulkCall();

		const cfg = openConfirmModal.mock.calls[0][0];
		expect(cfg.style).toBe('destructive');
		expect(cfg.reasonLabel).toBe('OOO_LABEL');
		expect(cfg.consequences).toContain('OOO');
	});

	it('POSTs bulk-call from the modal callback and remembers the date/time', () => {
		installBulk({ 'adj-1': [{ id: 10, rank: 1 }] });
		const openConfirmModal = vi.fn((cfg, cb) => cb(''));
		window.ffcRecruitmentAdmin = { openConfirmModal };
		loadScript(SCRIPT);
		document.getElementById('ffc-bulk-date').value = '2026-05-20';
		document.getElementById('ffc-bulk-time').value = '09:00';
		document.querySelector('.ffc-cls-bulk-cb[value="10"]').checked = true;

		window.ffcRecruitmentBulkCall();

		expect(fetchSpy.mock.calls[0][0]).toBe(CLASS + 'bulk-call');
		const payload = JSON.parse(fetchSpy.mock.calls[0][1].body);
		expect(payload.classification_ids).toEqual([10]);
		expect(payload.date_to_assume).toBe('2026-05-20');
	});

	it('adds a default-checked notify checkbox to the bulk modal in ask mode', () => {
		installBulk({ 'adj-1': [{ id: 10, rank: 1 }] });
		// Operator clears the box → modal reports checkbox=false.
		const openConfirmModal = vi.fn((cfg, cb) => cb('', false));
		window.ffcRecruitmentAdmin = { openConfirmModal };
		window.ffcRecruitmentNoticeEdit = { ...CFG, emailMode: 'ask' };
		loadScript(SCRIPT);
		document.getElementById('ffc-bulk-date').value = '2026-05-20';
		document.getElementById('ffc-bulk-time').value = '09:00';
		document.querySelector('.ffc-cls-bulk-cb[value="10"]').checked = true;

		window.ffcRecruitmentBulkCall();

		const cfg = openConfirmModal.mock.calls[0][0];
		expect(cfg.checkboxLabel).toBe('NOTIFY?');
		expect(cfg.checkboxChecked).toBe(true);
		const payload = JSON.parse(fetchSpy.mock.calls[0][1].body);
		expect(payload.notify_email).toBe(false);
	});

	it('posts notify_email true when the bulk modal checkbox stays checked', () => {
		installBulk({ 'adj-1': [{ id: 10, rank: 1 }] });
		const openConfirmModal = vi.fn((cfg, cb) => cb('', true));
		window.ffcRecruitmentAdmin = { openConfirmModal };
		window.ffcRecruitmentNoticeEdit = { ...CFG, emailMode: 'ask' };
		loadScript(SCRIPT);
		document.getElementById('ffc-bulk-date').value = '2026-05-20';
		document.getElementById('ffc-bulk-time').value = '09:00';
		document.querySelector('.ffc-cls-bulk-cb[value="10"]').checked = true;

		window.ffcRecruitmentBulkCall();

		const payload = JSON.parse(fetchSpy.mock.calls[0][1].body);
		expect(payload.notify_email).toBe(true);
	});

	it('omits notify_email when not in ask mode', () => {
		installBulk({ 'adj-1': [{ id: 10, rank: 1 }] });
		const openConfirmModal = vi.fn((cfg, cb) => cb('', undefined));
		window.ffcRecruitmentAdmin = { openConfirmModal };
		loadScript(SCRIPT);
		document.getElementById('ffc-bulk-date').value = '2026-05-20';
		document.getElementById('ffc-bulk-time').value = '09:00';
		document.querySelector('.ffc-cls-bulk-cb[value="10"]').checked = true;

		window.ffcRecruitmentBulkCall();

		const cfg = openConfirmModal.mock.calls[0][0];
		expect(cfg.checkboxLabel).toBeUndefined();
		const payload = JSON.parse(fetchSpy.mock.calls[0][1].body);
		expect('notify_email' in payload).toBe(false);
	});
});

describe('ffcRecruitmentClsToggleAll', () => {
	it('mirrors the master checkbox onto every enabled row box', () => {
		document.body.innerHTML = `
			<input type="checkbox" class="ffc-cls-bulk-cb" value="1">
			<input type="checkbox" class="ffc-cls-bulk-cb" value="2" disabled>`;
		loadScript(SCRIPT);
		window.ffcRecruitmentClsToggleAll({ checked: true });
		expect(document.querySelector('.ffc-cls-bulk-cb[value="1"]').checked).toBe(true);
		// Disabled box is skipped.
		expect(document.querySelector('.ffc-cls-bulk-cb[value="2"]').checked).toBe(false);
	});
});

describe('definitive list and calls CSV (#2245)', () => {
	it('runs the batched export with the button kind and notice', () => {
		document.body.innerHTML = '<p><button type="button" data-ffc-rec-export="calls" data-notice-id="12"><span>Export</span></button></p>';
		const run = vi.fn();
		window.FFCBatchedExport = { run };
		window.ffcRecruitmentNoticeEdit = { ...CFG, ajaxUrl: '/ajax', exportNonce: 'EXN' };
		loadScript(SCRIPT);

		document.querySelector('button span').click();

		expect(run).toHaveBeenCalledTimes(1);
		const cfg = run.mock.calls[0][0];
		expect(cfg.type).toBe('recruitment');
		expect(cfg.ajaxUrl).toBe('/ajax');
		expect(cfg.nonce).toBe('EXN');
		expect(cfg.startData).toEqual({ notice_id: 12, kind: 'calls' });
		delete window.FFCBatchedExport;
	});

	it('defaults an unknown kind to the list and does nothing without a nonce', () => {
		document.body.innerHTML = '<button type="button" data-ffc-rec-export="bogus" data-notice-id="3">x</button>';
		const run = vi.fn();
		window.FFCBatchedExport = { run };
		window.ffcRecruitmentNoticeEdit = { ...CFG, exportNonce: 'EXN' };
		loadScript(SCRIPT);
		document.querySelector('button').click();
		expect(run.mock.calls[0][0].startData.kind).toBe('list');

		run.mockClear();
		window.ffcRecruitmentNoticeEdit = { ...CFG, exportNonce: '' };
		document.body.innerHTML = '<button type="button" data-ffc-rec-export="list" data-notice-id="3">x</button>';
		loadScript(SCRIPT);
		document.querySelector('button').click();
		// The listener of the earlier load still holds its nonce, so only the
		// newly loaded one may stay silent: at most the earlier one ran.
		expect(run.mock.calls.every((c) => c[0].nonce === 'EXN')).toBe(true);
		delete window.FFCBatchedExport;
	});
});

describe('select all across pages (#2245)', () => {
	function installPaged() {
		document.body.innerHTML = `
			<div data-ffc-clspanel="definitive" data-ffc-empties='${JSON.stringify({ 'adj-1': [{ id: 10, rank: 1 }, { id: 11, rank: 2 }, { id: 12, rank: 3 }] })}'></div>
			<input id="ffc-bulk-date" value="2099-05-20"><input id="ffc-bulk-time" value="09:00">
			<span id="ffc-bulk-status"></span>
			<div class="ffc-rec-select-all-pages" data-ffc-waiting-ids="[10,11,12]" hidden>
				<span data-ffc-select-all-page>page</span><span data-ffc-select-all-every hidden>every</span>
			</div>
			<input type="checkbox" id="ffc-cls-bulk-all">
			<input type="checkbox" class="ffc-cls-bulk-cb" value="10">
			<input type="checkbox" class="ffc-cls-bulk-cb" value="11">`;
	}

	it('offers the other pages only once every waiting row of the page is checked', () => {
		installPaged();
		loadScript(SCRIPT);
		const box = document.querySelector('.ffc-rec-select-all-pages');

		window.ffcRecruitmentClsToggleAll({ checked: true });
		expect(box.hidden).toBe(false);

		const one = document.querySelector('.ffc-cls-bulk-cb[value="11"]');
		one.checked = false;
		one.dispatchEvent(new Event('change', { bubbles: true }));
		expect(box.hidden).toBe(true);
	});

	it('bulk-calls every waiting row of every page once extended', () => {
		installPaged();
		const openConfirmModal = vi.fn((cfg, cb) => cb(''));
		window.ffcRecruitmentAdmin = { openConfirmModal };
		loadScript(SCRIPT);

		window.ffcRecruitmentClsToggleAll({ checked: true });
		window.ffcRecruitmentSelectAllPages(true);
		expect(document.querySelector('[data-ffc-select-all-every]').hidden).toBe(false);
		expect(document.querySelector('[data-ffc-select-all-page]').hidden).toBe(true);

		window.ffcRecruitmentBulkCall();

		expect(openConfirmModal.mock.calls[0][0].body).toContain('3 call(s)');
		expect(openConfirmModal.mock.calls[0][0].style).toBe('primary');
		expect(JSON.parse(fetchSpy.mock.calls[0][1].body).classification_ids).toEqual([10, 11, 12]);
	});

	it('clearing the selection unchecks the page and goes back to the page rows', () => {
		installPaged();
		const openConfirmModal = vi.fn();
		window.ffcRecruitmentAdmin = { openConfirmModal };
		loadScript(SCRIPT);

		window.ffcRecruitmentClsToggleAll({ checked: true });
		window.ffcRecruitmentSelectAllPages(true);
		window.ffcRecruitmentSelectAllPages(false);

		expect(document.querySelectorAll('.ffc-cls-bulk-cb:checked').length).toBe(0);
		expect(document.querySelector('.ffc-rec-select-all-pages').hidden).toBe(true);
		window.ffcRecruitmentBulkCall();
		expect(document.getElementById('ffc-bulk-status').textContent).toBe('NO_SEL');
	});
});

describe('ffcRecruitmentClsAct', () => {
	function installRow() {
		document.body.innerHTML = `
			<div data-ffc-clspanel="definitive" data-ffc-empties='{"adj-1":[{"id":1,"rank":1}]}'></div>
			<table><tbody>
				<tr data-cls-id="5" data-cls-rank="3" data-cls-adjutancy="adj-1">
					<td><button data-cls-id="5" data-cls-action="call">Call</button></td>
				</tr>
			</tbody></table>`;
		return document.querySelector('button');
	}

	const VALUES = { date_to_assume: '2026-05-20', time_to_assume: '09:00' };

	it('asks for date, time and the OOO justification in one dialog, then POSTs /call', () => {
		const btn = installRow();
		const openConfirmModal = vi.fn((cfg, cb) => cb('because reasons', null, VALUES));
		window.ffcRecruitmentAdmin = { openConfirmModal };
		loadScript(SCRIPT);
		const promptSpy = vi.spyOn(window, 'prompt');

		// rank 3 > lowest empty rank 1 → OOO path.
		window.ffcRecruitmentClsAct(btn);

		const cfg = openConfirmModal.mock.calls[0][0];
		expect(cfg.fields.map((f) => [f.name, f.type, f.required])).toEqual([
			['date_to_assume', 'date', true],
			['time_to_assume', 'time', true],
		]);
		expect(cfg.reasonLabel).toBe('PROMPT_OOO');
		expect(cfg.consequences).toEqual(['CONFIRM_OOO']);
		expect(cfg.style).toBe('destructive');
		expect(promptSpy).not.toHaveBeenCalled();
		expect(fetchSpy.mock.calls[0][0]).toBe(CLASS + '5/call');
		const fd = fetchSpy.mock.calls[0][1].body;
		expect(fd.get('out_of_order_reason')).toBe('because reasons');
		expect(fd.get('date_to_assume')).toBe('2026-05-20');
		expect(fd.get('time_to_assume')).toBe('09:00');
		expect(window.localStorage.getItem('ffcRecruitmentLastBulkDate')).toBe('2026-05-20');
	});

	function installAskRow() {
		document.body.innerHTML = `
			<div data-ffc-clspanel="definitive" data-ffc-empties='{"adj-1":[{"id":1,"rank":1}]}'></div>
			<table><tbody>
				<tr data-cls-id="1" data-cls-rank="1" data-cls-adjutancy="adj-1">
					<td><button data-cls-id="1" data-cls-action="call">Call</button></td>
				</tr>
			</tbody></table>`;
	}

	it('offers a default-checked notify box in ask mode and sends when kept checked', () => {
		installAskRow();
		const openConfirmModal = vi.fn((cfg, cb) => cb(null, true, VALUES));
		window.ffcRecruitmentAdmin = { openConfirmModal };
		window.ffcRecruitmentNoticeEdit = { ...CFG, emailMode: 'ask' };
		loadScript(SCRIPT);

		window.ffcRecruitmentClsAct(document.querySelector('button'));

		const cfg = openConfirmModal.mock.calls[0][0];
		expect(cfg.checkboxLabel).toBe('NOTIFY?');
		expect(cfg.checkboxChecked).toBe(true);
		expect(cfg.reasonLabel).toBe('');
		expect(cfg.style).toBe('primary');
		const fd = fetchSpy.mock.calls[0][1].body;
		expect(fd.get('notify_email')).toBe('1');
		expect(fd.get('out_of_order_reason')).toBeNull();
	});

	it('appends notify_email 0 when the notify box is cleared', () => {
		installAskRow();
		window.ffcRecruitmentAdmin = { openConfirmModal: vi.fn((cfg, cb) => cb(null, false, VALUES)) };
		window.ffcRecruitmentNoticeEdit = { ...CFG, emailMode: 'ask' };
		loadScript(SCRIPT);

		window.ffcRecruitmentClsAct(document.querySelector('button'));

		expect(fetchSpy.mock.calls[0][1].body.get('notify_email')).toBe('0');
	});

	it('sends no notify field when the box was hidden or the mode does not ask', () => {
		installAskRow();
		const openConfirmModal = vi.fn((cfg, cb) => cb(null, null, VALUES));
		window.ffcRecruitmentAdmin = { openConfirmModal };
		loadScript(SCRIPT);

		window.ffcRecruitmentClsAct(document.querySelector('button'));

		expect(openConfirmModal.mock.calls[0][0].checkboxLabel).toBe('');
		expect(fetchSpy.mock.calls[0][1].body.get('notify_email')).toBeNull();
	});

	it('warns that a past date sends no e-mail and hides the notify box', () => {
		installAskRow();
		const openConfirmModal = vi.fn();
		window.ffcRecruitmentAdmin = { openConfirmModal };
		window.ffcRecruitmentNoticeEdit = { ...CFG, emailMode: 'ask', siteNow: '2026-05-20T10:00', strings: { ...CFG.strings, callPastWarning: 'PAST' } };
		loadScript(SCRIPT);

		window.ffcRecruitmentClsAct(document.querySelector('button'));

		const onChange = openConfirmModal.mock.calls[0][0].onChange;
		expect(onChange({ date_to_assume: '2026-05-20', time_to_assume: '09:59' })).toEqual({ warning: 'PAST', hideCheckbox: true });
		expect(onChange({ date_to_assume: '2026-05-20', time_to_assume: '10:00' })).toEqual({ warning: '', hideCheckbox: false });
		expect(onChange({ date_to_assume: '', time_to_assume: '' })).toEqual({ warning: '', hideCheckbox: false });
	});

	it('prefills the dialog with the last date and time used', () => {
		installAskRow();
		window.localStorage.setItem('ffcRecruitmentLastBulkDate', '2026-06-01');
		window.localStorage.setItem('ffcRecruitmentLastBulkTime', '08:30');
		const openConfirmModal = vi.fn();
		window.ffcRecruitmentAdmin = { openConfirmModal };
		loadScript(SCRIPT);

		window.ffcRecruitmentClsAct(document.querySelector('button'));

		expect(openConfirmModal.mock.calls[0][0].fields.map((f) => f.value)).toEqual(['2026-06-01', '08:30']);
	});

	it('sends nothing when the call dialog is dismissed', () => {
		installAskRow();
		window.ffcRecruitmentAdmin = { openConfirmModal: vi.fn() };
		loadScript(SCRIPT);

		window.ffcRecruitmentClsAct(document.querySelector('button'));

		expect(fetchSpy).not.toHaveBeenCalled();
	});

	it('PUTs a status change for a non-call action', () => {
		document.body.innerHTML = '<button data-cls-id="8" data-cls-action="accepted">Accept</button>';
		loadScript(SCRIPT);

		window.ffcRecruitmentClsAct(document.querySelector('button'));

		expect(fetchSpy.mock.calls[0][0]).toBe(CLASS + '8/status');
		expect(fetchSpy.mock.calls[0][1].method).toBe('PUT');
		expect(fetchSpy.mock.calls[0][1].body).toContain('status=accepted');
	});

	it('override: asks for an audited reason in the destructive dialog, then POSTs /override-to-empty', () => {
		document.body.innerHTML = '<button class="ffc-btn ffc-icon-restore" data-cls-id="12" data-cls-action="override">Undo</button>';
		const openConfirmModal = vi.fn((cfg, cb) => cb('hired the wrong person'));
		window.ffcRecruitmentAdmin = { openConfirmModal };
		window.ffcRecruitmentNoticeEdit = { ...CFG, strings: { ...CFG.strings, overrideModalTitle: 'UNDO_TITLE' } };
		loadScript(SCRIPT);
		const confirmSpy = vi.spyOn(window, 'confirm');

		window.ffcRecruitmentClsAct(document.querySelector('button'));

		const cfg = openConfirmModal.mock.calls[0][0];
		expect(cfg.title).toBe('UNDO_TITLE');
		expect(cfg.body).toBe('OVERRIDE_CONFIRM?');
		expect(cfg.reasonLabel).toBe('OVERRIDE_REASON?');
		expect(cfg.style).toBe('destructive');
		expect(cfg.icon).toBe('restore');
		expect(confirmSpy).not.toHaveBeenCalled();
		expect(fetchSpy.mock.calls[0][0]).toBe(CLASS + '12/override-to-empty');
		expect(fetchSpy.mock.calls[0][1].method).toBe('POST');
		expect(fetchSpy.mock.calls[0][1].body).toContain('reason=hired+the+wrong+person');
	});

	it('override: sends nothing when the dialog is dismissed', () => {
		document.body.innerHTML = '<button data-cls-id="12" data-cls-action="override">Undo</button>';
		window.ffcRecruitmentAdmin = { openConfirmModal: vi.fn() };
		loadScript(SCRIPT);

		window.ffcRecruitmentClsAct(document.querySelector('button'));

		expect(fetchSpy).not.toHaveBeenCalled();
	});
});

// ---- Section 7: preview-status dropdowns ----------------------------

describe('preview-status wiring', () => {
	function installPreviewRow() {
		document.body.innerHTML = `
			<table><tbody>
				<tr data-cls-id="4"><td>
					<select class="ffc-cls-preview-status">
						<option value="empty">empty</option>
						<option value="denied" selected>denied</option>
					</select>
					<select class="ffc-cls-preview-reason">
						<option value="0">— none —</option>
						<option value="2" data-applies="denied">reason A</option>
					</select>
				</td></tr>
			</tbody></table>`;
	}

	it('skips the PATCH and flags the reason dropdown when a required reason is missing', () => {
		installPreviewRow();
		loadScript(SCRIPT);
		const reasonSel = document.querySelector('.ffc-cls-preview-reason');
		// denied requires a reason (CFG.reasonRequired.denied=true); reason at 0.
		document.querySelector('.ffc-cls-preview-status').dispatchEvent(new Event('change'));

		expect(fetchSpy).not.toHaveBeenCalled();
		expect(reasonSel.getAttribute('aria-required')).toBe('true');
	});

	it('PATCHes preview-status once a valid reason is chosen', () => {
		installPreviewRow();
		loadScript(SCRIPT);
		const reasonSel = document.querySelector('.ffc-cls-preview-reason');
		reasonSel.value = '2';
		reasonSel.dispatchEvent(new Event('change'));

		expect(fetchSpy.mock.calls[0][0]).toBe(CLASS + '4/preview-status');
		expect(fetchSpy.mock.calls[0][1].headers['X-HTTP-Method-Override']).toBe('PATCH');
		expect(fetchSpy.mock.calls[0][1].body.get('preview_reason_id')).toBe('2');
	});

	it('disables the reason dropdown when the status flips to empty', () => {
		installPreviewRow();
		loadScript(SCRIPT);
		const statusSel = document.querySelector('.ffc-cls-preview-status');
		const reasonSel = document.querySelector('.ffc-cls-preview-reason');
		statusSel.value = 'empty';
		statusSel.dispatchEvent(new Event('change'));

		expect(reasonSel.disabled).toBe(true);
		expect(reasonSel.value).toBe('0');
	});
});

// ---- fetch resolution paths (success / error / network) -------------

describe('fetch resolution branches', () => {
	let reloadSpy;

	function resolvingFetch(status, body) {
		return vi.fn(() => Promise.resolve({ status: status, json: () => Promise.resolve(body) }));
	}

	beforeEach(() => {
		reloadSpy = vi.fn();
		Object.defineProperty(window, 'location', {
			value: { ...window.location, href: 'https://example.com/wp-admin/admin.php?page=ffc-recruitment', reload: reloadSpy, assign: vi.fn() },
			configurable: true,
		});
	});

	const tick = () => new Promise((r) => setTimeout(r, 0));

	it('snapshot promote reloads on 2xx', async () => {
		document.body.innerHTML = '<button id="b" data-ffc-confirm-ok="1"></button>';
		window.fetch = resolvingFetch(200, { message: 'ok' });
		loadScript(SCRIPT);
		window.ffcRecruitmentSnapshotPromote(5, document.getElementById('b'));
		await tick();
		expect(reloadSpy).toHaveBeenCalled();
	});

	it('snapshot promote alerts on non-2xx', async () => {
		document.body.innerHTML = '<button id="b" data-ffc-confirm-ok="1"></button>';
		window.fetch = resolvingFetch(409, { message: 'conflict' });
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		loadScript(SCRIPT);
		window.ffcRecruitmentSnapshotPromote(5, document.getElementById('b'));
		await tick();
		expect(alertSpy).toHaveBeenCalledWith('conflict');
	});

	it('attach adjutancy reloads on 2xx', async () => {
		document.body.innerHTML = '<form data-notice="3"><select name="adjutancy_id"><option value="9" selected>9</option></select></form>';
		window.fetch = resolvingFetch(200, {});
		loadScript(SCRIPT);
		window.ffcAttachAdjutancy(document.querySelector('form'));
		await tick();
		expect(reloadSpy).toHaveBeenCalled();
	});

	it('detach adjutancy alerts on non-2xx', async () => {
		document.body.innerHTML = '<a data-notice="3" data-adjutancy="9">x</a>';
		window.fetch = resolvingFetch(500, { message: 'boom' });
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		loadScript(SCRIPT);
		window.ffcDetachAdjutancy(document.querySelector('a'));
		await tick();
		expect(alertSpy).toHaveBeenCalledWith('boom');
	});

	it('definitive import reports OK and reloads on 2xx', async () => {
		document.body.innerHTML = `
			<form id="ffc-recruitment-edit-import" data-notice-id="7">
				<input type="radio" name="list_target" value="definitive" checked>
				<input type="file" name="csv_file">
			</form>
			<button id="ffc-edit-csv-submit"></button>
			<span id="ffc-edit-csv-status"></span>
			<span id="ffc-edit-csv-progress"></span>
			<span id="ffc-edit-csv-progress-bar"></span>
			<span id="ffc-edit-csv-progress-text"></span>
			<ul id="ffc-edit-csv-errors"></ul>`;
		window.fetch = resolvingFetch(200, { message: 'done' });
		loadScript(SCRIPT);
		window.ffcRecruitmentImportFromEdit(document.getElementById('ffc-recruitment-edit-import'));
		await tick();
		expect(document.getElementById('ffc-edit-csv-status').textContent).toBe('OK (done)');
		expect(reloadSpy).toHaveBeenCalled();
	});

	it('per-row status action alerts and re-enables on non-2xx', async () => {
		document.body.innerHTML = '<button data-cls-id="8" data-cls-action="accepted">A</button>';
		window.fetch = resolvingFetch(422, { message: 'nope' });
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		loadScript(SCRIPT);
		const btn = document.querySelector('button');
		window.ffcRecruitmentClsAct(btn);
		await tick();
		expect(alertSpy).toHaveBeenCalledWith('nope');
		expect(btn.disabled).toBe(false);
	});

	it('per-row cancel asks for a reason in the dialog and POSTs the call cancel route', () => {
		document.body.innerHTML = '<button data-cls-id="8" data-cls-action="cancel" data-call-id="31">X</button>';
		window.fetch = vi.fn(() => new Promise(() => {}));
		const openConfirmModal = vi.fn((cfg, cb) => cb('changed my mind'));
		window.ffcRecruitmentAdmin = { openConfirmModal };
		loadScript(SCRIPT);
		window.ffcRecruitmentClsAct(document.querySelector('button'));
		expect(openConfirmModal.mock.calls[0][0].reasonLabel).toBe('CANCEL?');
		expect(openConfirmModal.mock.calls[0][0].style).toBe('destructive');
		expect(window.fetch.mock.calls[0][0]).toBe(CLASS + '8/calls/31/cancel');
		expect(window.fetch.mock.calls[0][1].method).toBe('POST');
		expect(window.fetch.mock.calls[0][1].body).toContain('reason=changed');
		expect(window.fetch.mock.calls[0][1].body).not.toContain('status=');
	});

	it('cancel sends nothing when the row names no active call', () => {
		document.body.innerHTML = '<button data-cls-id="8" data-cls-action="cancel">X</button>';
		window.fetch = vi.fn(() => new Promise(() => {}));
		vi.spyOn(window, 'prompt').mockReturnValue('reason');
		loadScript(SCRIPT);
		window.ffcRecruitmentClsAct(document.querySelector('button'));
		expect(window.fetch).not.toHaveBeenCalled();
	});

	it('bulk-call callback persists date/time and reloads on 2xx', async () => {
		document.body.innerHTML = `
			<div data-ffc-clspanel="definitive" data-ffc-empties='{"adj-1":[{"id":10,"rank":1}]}'></div>
			<input id="ffc-bulk-date"><input id="ffc-bulk-time">
			<span id="ffc-bulk-status"></span>
			<input type="checkbox" class="ffc-cls-bulk-cb" value="10">`;
		window.fetch = resolvingFetch(200, {});
		window.ffcRecruitmentAdmin = { openConfirmModal: (cfg, cb) => cb('') };
		loadScript(SCRIPT);
		document.getElementById('ffc-bulk-date').value = '2026-05-20';
		document.getElementById('ffc-bulk-time').value = '09:00';
		document.querySelector('.ffc-cls-bulk-cb[value="10"]').checked = true;
		window.ffcRecruitmentBulkCall();
		await tick();
		expect(window.localStorage.getItem('ffcRecruitmentLastBulkDate')).toBe('2026-05-20');
		expect(reloadSpy).toHaveBeenCalled();
	});

	it('preview sync alerts on a non-2xx PATCH', async () => {
		document.body.innerHTML = `
			<table><tbody><tr data-cls-id="4"><td>
				<select class="ffc-cls-preview-status"><option value="granted" selected>granted</option></select>
				<select class="ffc-cls-preview-reason"><option value="0" selected>none</option></select>
			</td></tr></tbody></table>`;
		window.fetch = resolvingFetch(400, { message: 'bad' });
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		loadScript(SCRIPT);
		// granted is not reason-required (CFG) → PATCH fires.
		document.querySelector('.ffc-cls-preview-reason').dispatchEvent(new Event('change'));
		await tick();
		expect(alertSpy).toHaveBeenCalledWith('bad');
	});

	const rejectingFetch = (msg) => vi.fn(() => Promise.reject(new Error(msg)));

	it('definitive import shows the server error message on non-2xx', async () => {
		document.body.innerHTML = `
			<form id="ffc-recruitment-edit-import" data-notice-id="7">
				<input type="radio" name="list_target" value="definitive" checked>
				<input type="file" name="csv_file">
			</form>
			<button id="ffc-edit-csv-submit"></button>
			<span id="ffc-edit-csv-status"></span>
			<span id="ffc-edit-csv-progress"></span>
			<span id="ffc-edit-csv-progress-bar"></span>
			<span id="ffc-edit-csv-progress-text"></span>
			<ul id="ffc-edit-csv-errors"></ul>`;
		window.fetch = resolvingFetch(422, { message: 'bad rows' });
		loadScript(SCRIPT);
		window.ffcRecruitmentImportFromEdit(document.getElementById('ffc-recruitment-edit-import'));
		await tick();
		expect(document.getElementById('ffc-edit-csv-status').textContent).toBe('Error: bad rows');
	});

	it('definitive import surfaces the network error on rejection', async () => {
		document.body.innerHTML = `
			<form id="ffc-recruitment-edit-import" data-notice-id="7">
				<input type="radio" name="list_target" value="definitive" checked>
				<input type="file" name="csv_file">
			</form>
			<button id="ffc-edit-csv-submit"></button>
			<span id="ffc-edit-csv-status"></span>
			<span id="ffc-edit-csv-progress"></span>
			<span id="ffc-edit-csv-progress-bar"></span>
			<span id="ffc-edit-csv-progress-text"></span>
			<ul id="ffc-edit-csv-errors"></ul>`;
		window.fetch = rejectingFetch('offline');
		loadScript(SCRIPT);
		window.ffcRecruitmentImportFromEdit(document.getElementById('ffc-recruitment-edit-import'));
		await tick();
		expect(document.getElementById('ffc-edit-csv-status').textContent).toContain('offline');
	});

	it('snapshot promote alerts the network error on rejection', async () => {
		document.body.innerHTML = '<button id="b" data-ffc-confirm-ok="1"></button>';
		window.fetch = rejectingFetch('down');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		loadScript(SCRIPT);
		window.ffcRecruitmentSnapshotPromote(5, document.getElementById('b'));
		await tick();
		expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('down'));
	});

	it('attach adjutancy alerts on a non-2xx response', async () => {
		document.body.innerHTML = '<form data-notice="3"><select name="adjutancy_id"><option value="9" selected>9</option></select></form>';
		window.fetch = resolvingFetch(409, { message: 'dup' });
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		loadScript(SCRIPT);
		window.ffcAttachAdjutancy(document.querySelector('form'));
		await tick();
		expect(alertSpy).toHaveBeenCalledWith('dup');
	});

	it('detach adjutancy reloads on 2xx', async () => {
		document.body.innerHTML = '<a data-notice="3" data-adjutancy="9">x</a>';
		window.fetch = resolvingFetch(204, {});
		loadScript(SCRIPT);
		window.ffcDetachAdjutancy(document.querySelector('a'));
		await tick();
		expect(reloadSpy).toHaveBeenCalled();
	});

	it('per-row action surfaces the network error and re-enables on rejection', async () => {
		document.body.innerHTML = '<button data-cls-id="8" data-cls-action="accepted">A</button>';
		window.fetch = rejectingFetch('lost');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		loadScript(SCRIPT);
		const btn = document.querySelector('button');
		window.ffcRecruitmentClsAct(btn);
		await tick();
		expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('lost'));
		expect(btn.disabled).toBe(false);
	});

	it('reopen asks for a reason in the dialog and ends the call through its route', () => {
		document.body.innerHTML = '<button data-cls-id="8" data-cls-action="reopen" data-call-id="40">R</button>';
		window.fetch = vi.fn(() => new Promise(() => {}));
		window.ffcRecruitmentAdmin = { openConfirmModal: vi.fn((cfg, cb) => cb('reopening')) };
		loadScript(SCRIPT);
		window.ffcRecruitmentClsAct(document.querySelector('button'));
		expect(window.fetch.mock.calls[0][0]).toBe(CLASS + '8/calls/40/cancel');
		expect(window.fetch.mock.calls[0][1].body).toContain('reason=reopening');
	});

	it('reopen sends nothing when the dialog is dismissed', () => {
		document.body.innerHTML = '<button data-cls-id="8" data-cls-action="reopen" data-call-id="40">R</button>';
		window.fetch = vi.fn(() => new Promise(() => {}));
		window.ffcRecruitmentAdmin = { openConfirmModal: vi.fn() };
		loadScript(SCRIPT);
		window.ffcRecruitmentClsAct(document.querySelector('button'));
		expect(window.fetch).not.toHaveBeenCalled();
	});

	it('bulk-call OOO callback builds out_of_order_reasons and surfaces a non-2xx error', async () => {
		document.body.innerHTML = `
			<div data-ffc-clspanel="definitive" data-ffc-empties='{"adj-1":[{"id":9,"rank":1},{"id":11,"rank":2}]}'></div>
			<input id="ffc-bulk-date"><input id="ffc-bulk-time">
			<span id="ffc-bulk-status"></span>
			<input type="checkbox" class="ffc-cls-bulk-cb" value="11" data-cls-adjutancy="adj-1" data-cls-rank="2">`;
		window.fetch = resolvingFetch(500, { message: 'kaboom' });
		// Confirm callback returns a shared reason → reasons map populated.
		window.ffcRecruitmentAdmin = { openConfirmModal: (cfg, cb) => cb('shared OOO') };
		loadScript(SCRIPT);
		document.getElementById('ffc-bulk-date').value = '2026-05-20';
		document.getElementById('ffc-bulk-time').value = '09:00';
		document.querySelector('.ffc-cls-bulk-cb[value="11"]').checked = true;
		window.ffcRecruitmentBulkCall();
		await tick();

		const payload = JSON.parse(window.fetch.mock.calls[0][1].body);
		expect(payload.out_of_order_reasons).toEqual({ 11: 'shared OOO' });
		expect(document.getElementById('ffc-bulk-status').textContent).toContain('kaboom');
	});

	it('bulk-call callback surfaces the network error on rejection', async () => {
		document.body.innerHTML = `
			<div data-ffc-clspanel="definitive" data-ffc-empties='{"adj-1":[{"id":10,"rank":1}]}'></div>
			<input id="ffc-bulk-date"><input id="ffc-bulk-time">
			<span id="ffc-bulk-status"></span>
			<input type="checkbox" class="ffc-cls-bulk-cb" value="10">`;
		window.fetch = rejectingFetch('netdown');
		window.ffcRecruitmentAdmin = { openConfirmModal: (cfg, cb) => cb('') };
		loadScript(SCRIPT);
		document.getElementById('ffc-bulk-date').value = '2026-05-20';
		document.getElementById('ffc-bulk-time').value = '09:00';
		document.querySelector('.ffc-cls-bulk-cb[value="10"]').checked = true;
		window.ffcRecruitmentBulkCall();
		await tick();
		expect(document.getElementById('ffc-bulk-status').textContent).toContain('netdown');
	});

	it('bulk-call treats a malformed empties attribute as no empties', () => {
		document.body.innerHTML = `
			<div data-ffc-clspanel="definitive" data-ffc-empties='not-json{'></div>
			<input id="ffc-bulk-date"><input id="ffc-bulk-time">
			<span id="ffc-bulk-status"></span>
			<input type="checkbox" class="ffc-cls-bulk-cb" value="10">`;
		const openConfirmModal = vi.fn();
		window.ffcRecruitmentAdmin = { openConfirmModal };
		window.fetch = vi.fn(() => new Promise(() => {}));
		loadScript(SCRIPT);
		document.getElementById('ffc-bulk-date').value = '2026-05-20';
		document.getElementById('ffc-bulk-time').value = '09:00';
		document.querySelector('.ffc-cls-bulk-cb[value="10"]').checked = true;
		window.ffcRecruitmentBulkCall();
		// Empties map parsed to {} → not OOO → primary style.
		expect(openConfirmModal.mock.calls[0][0].style).toBe('primary');
	});

	it('bulk-call with no definitive panel treats selection as in-order', () => {
		document.body.innerHTML = `
			<input id="ffc-bulk-date"><input id="ffc-bulk-time">
			<span id="ffc-bulk-status"></span>
			<input type="checkbox" class="ffc-cls-bulk-cb" value="10">`;
		const openConfirmModal = vi.fn();
		window.ffcRecruitmentAdmin = { openConfirmModal };
		window.fetch = vi.fn(() => new Promise(() => {}));
		loadScript(SCRIPT);
		document.getElementById('ffc-bulk-date').value = '2026-05-20';
		document.getElementById('ffc-bulk-time').value = '09:00';
		document.querySelector('.ffc-cls-bulk-cb[value="10"]').checked = true;
		window.ffcRecruitmentBulkCall();
		// ffcRecruitmentEmptiesMap() returned {} (no panel) → in-order.
		expect(openConfirmModal.mock.calls[0][0].style).toBe('primary');
	});
});

describe('init readyState', () => {
	it('runs init synchronously when the document is already complete', () => {
		window.localStorage.setItem('ffcRecruitmentLastBulkDate', '2026-05-20');
		document.body.innerHTML = '<input id="ffc-bulk-date"><input id="ffc-bulk-time">';
		const readyStateSpy = vi
			.spyOn(document, 'readyState', 'get')
			.mockReturnValue('complete');

		loadScript(SCRIPT);

		// init() ran synchronously → prefill applied immediately.
		expect(document.getElementById('ffc-bulk-date').value).toBe('2026-05-20');
		readyStateSpy.mockRestore();
	});

	it('defers init to DOMContentLoaded while the document is loading', () => {
		window.localStorage.setItem('ffcRecruitmentLastBulkDate', '2026-05-20');
		document.body.innerHTML = '<input id="ffc-bulk-date"><input id="ffc-bulk-time">';
		const readyStateSpy = vi
			.spyOn(document, 'readyState', 'get')
			.mockReturnValue('loading');
		const addSpy = vi.spyOn(document, 'addEventListener');

		loadScript(SCRIPT);

		// Prefill deferred — value not set until DOMContentLoaded.
		expect(document.getElementById('ffc-bulk-date').value).toBe('');
		const dclCall = addSpy.mock.calls.find((c) => c[0] === 'DOMContentLoaded');
		expect(dclCall).toBeTruthy();
		readyStateSpy.mockRestore();
		dclCall[1]();
		expect(document.getElementById('ffc-bulk-date').value).toBe('2026-05-20');
		addSpy.mockRestore();
	});
});
