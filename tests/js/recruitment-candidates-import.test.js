// Tests for `assets/js/ffc-recruitment-candidates-import.js`.
//
// The two global handlers for the recruitment Candidates-tab CSV import,
// extracted from an inline <script> in class-ffc-recruitment-admin-page.php
// (Item 10 of the frontend audit). They read config (REST notices root,
// nonce, i18n strings) from window.ffcRecruitmentCandidatesImport.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-recruitment-candidates-import.js';

const CFG = {
	noticesRoot: 'https://example.com/wp-json/ffcertificate/v1/recruitment/notices/',
	nonce: 'rest-nonce',
	strings: {
		bothLists: 'BOTH',
		draftOnly: 'DRAFT',
		pickNotice: 'PICK',
		selectTarget: 'SELECT',
		processing: 'Processing CSV…',
		elapsed: 'elapsed',
	},
};

function installForm(noticeStatus) {
	document.body.innerHTML = `
		<form id="ffc-recruitment-candidates-import">
			<select id="ffc-cand-import-notice" name="notice_id" onchange="ffcRecruitmentImportNoticeChanged(this);">
				<option value="" data-status="">— Select —</option>
				<option value="5" data-status="${noticeStatus}" selected>Notice 5</option>
			</select>
			<input type="radio" name="list_target" value="preliminary" checked>
			<input type="radio" name="list_target" value="definitive">
			<p id="ffc-cand-import-target-help"></p>
			<input type="file" name="csv_file">
			<button id="ffc-cand-csv-submit" type="submit">Import</button>
			<span id="ffc-cand-csv-progress"></span>
			<span id="ffc-cand-csv-progress-text"></span>
			<span id="ffc-cand-csv-status"></span>
		</form>
	`;
}

function select() {
	return document.getElementById('ffc-cand-import-notice');
}
function defRadio() {
	return document.querySelector('input[name="list_target"][value="definitive"]');
}
function prelimRadio() {
	return document.querySelector('input[name="list_target"][value="preliminary"]');
}

beforeEach(() => {
	window.ffcRecruitmentCandidatesImport = CFG;
});

afterEach(() => {
	document.body.innerHTML = '';
	delete window.ffcRecruitmentCandidatesImport;
	delete window.ffcRecruitmentImportNoticeChanged;
	delete window.ffcRecruitmentImportFromCandidates;
	vi.restoreAllMocks();
	vi.useRealTimers();
});

describe('ffcRecruitmentImportNoticeChanged', () => {
	it('enables the definitive radio for a preliminary notice', () => {
		installForm('preliminary');
		loadScript(SCRIPT);
		window.ffcRecruitmentImportNoticeChanged(select());

		expect(defRadio().disabled).toBe(false);
		expect(document.getElementById('ffc-cand-import-target-help').textContent).toBe('BOTH');
	});

	it('forces preliminary for a draft notice', () => {
		installForm('draft');
		loadScript(SCRIPT);
		window.ffcRecruitmentImportNoticeChanged(select());

		expect(defRadio().disabled).toBe(true);
		expect(defRadio().checked).toBe(false);
		expect(prelimRadio().checked).toBe(true);
		expect(document.getElementById('ffc-cand-import-target-help').textContent).toBe('DRAFT');
	});

	it('falls back to the pick-notice hint for an empty status', () => {
		installForm('');
		loadScript(SCRIPT);
		window.ffcRecruitmentImportNoticeChanged(select());

		expect(defRadio().disabled).toBe(true);
		expect(document.getElementById('ffc-cand-import-target-help').textContent).toBe('PICK');
	});
});

describe('ffcRecruitmentImportFromCandidates', () => {
	it('alerts and aborts when no notice is selected', () => {
		installForm('preliminary');
		// Blank out the notice value.
		select().value = '';
		loadScript(SCRIPT);
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		const result = window.ffcRecruitmentImportFromCandidates(document.querySelector('form'));

		expect(result).toBe(false);
		expect(alertSpy).toHaveBeenCalledWith('SELECT');
	});

	it('POSTs to the import endpoint for a preliminary target', () => {
		installForm('preliminary');
		loadScript(SCRIPT);
		const fetchSpy = vi.fn(() => new Promise(() => {})); // never resolves
		window.fetch = fetchSpy;

		const result = window.ffcRecruitmentImportFromCandidates(document.querySelector('form'));

		expect(result).toBe(false);
		expect(fetchSpy).toHaveBeenCalledTimes(1);
		const [url, opts] = fetchSpy.mock.calls[0];
		expect(url).toBe(CFG.noticesRoot + '5/import');
		expect(opts.method).toBe('POST');
		expect(opts.headers['X-WP-Nonce']).toBe('rest-nonce');
		expect(opts.credentials).toBe('same-origin');
		// Progress UI engaged.
		expect(document.getElementById('ffc-cand-csv-submit').disabled).toBe(true);
		expect(document.getElementById('ffc-cand-csv-progress').style.display).toBe('inline-flex');
	});

	it('targets promote-preview and tags the mode for a definitive import', () => {
		installForm('preliminary');
		defRadio().checked = true;
		prelimRadio().checked = false;
		loadScript(SCRIPT);
		const fetchSpy = vi.fn(() => new Promise(() => {}));
		window.fetch = fetchSpy;

		window.ffcRecruitmentImportFromCandidates(document.querySelector('form'));

		expect(fetchSpy.mock.calls[0][0]).toBe(CFG.noticesRoot + '5/promote-preview');
		const body = fetchSpy.mock.calls[0][1].body;
		expect(body.get('mode')).toBe('definitive_import');
	});

	it('renders the elapsed-time ticker while the request is in flight', () => {
		vi.useFakeTimers();
		installForm('preliminary');
		loadScript(SCRIPT);
		window.fetch = vi.fn(() => new Promise(() => {}));

		window.ffcRecruitmentImportFromCandidates(document.querySelector('form'));
		// Initial tick is 0s.
		expect(document.getElementById('ffc-cand-csv-progress-text').textContent)
			.toBe('Processing CSV… 0s elapsed');
		vi.advanceTimersByTime(2000);
		expect(document.getElementById('ffc-cand-csv-progress-text').textContent)
			.toBe('Processing CSV… 2s elapsed');
	});

	it('on a 2xx response reports OK, clears the progress UI, and reloads', async () => {
		installForm('preliminary');
		loadScript(SCRIPT);
		const reload = vi.fn();
		Object.defineProperty(window, 'location', {
			value: { ...window.location, reload },
			configurable: true,
		});
		window.fetch = vi.fn(() =>
			Promise.resolve({ status: 200, json: () => Promise.resolve({ message: 'done' }) })
		);

		window.ffcRecruitmentImportFromCandidates(document.querySelector('form'));
		await new Promise((r) => setTimeout(r, 0));

		expect(document.getElementById('ffc-cand-csv-status').textContent).toBe('OK (done)');
		expect(document.getElementById('ffc-cand-csv-submit').disabled).toBe(false);
		expect(document.getElementById('ffc-cand-csv-progress').style.display).toBe('none');
		expect(reload).toHaveBeenCalledTimes(1);
	});

	it('on a non-2xx response reports the server error and re-enables submit', async () => {
		installForm('preliminary');
		loadScript(SCRIPT);
		window.fetch = vi.fn(() =>
			Promise.resolve({ status: 422, json: () => Promise.resolve({ message: 'bad rows' }) })
		);

		window.ffcRecruitmentImportFromCandidates(document.querySelector('form'));
		await new Promise((r) => setTimeout(r, 0));

		expect(document.getElementById('ffc-cand-csv-status').textContent).toBe('Error: bad rows');
		expect(document.getElementById('ffc-cand-csv-submit').disabled).toBe(false);
	});

	it('on a network failure reports the caught error and re-enables submit', async () => {
		installForm('preliminary');
		loadScript(SCRIPT);
		window.fetch = vi.fn(() => Promise.reject(new Error('offline')));

		window.ffcRecruitmentImportFromCandidates(document.querySelector('form'));
		await new Promise((r) => setTimeout(r, 0));

		expect(document.getElementById('ffc-cand-csv-status').textContent).toBe('Network error: offline');
		expect(document.getElementById('ffc-cand-csv-submit').disabled).toBe(false);
	});
});
