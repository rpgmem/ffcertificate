// Tests for the batched recruitment CSV-import orchestrator
// (`assets/js/ffc-recruitment-import-batched.js`). The script exposes a
// single global `window.ffcRecruitmentImportBatched.run({...})` that
// orchestrates the three-phase REST flow:
//
//   1. POST /import-job/start  (multipart, csv_file)        → { job_id, total }
//   2. POST /import-job/batch  (json, { job_id, size })     → { processed, total, done, inserted }
//      …loops until done === true.
//   3. POST /import-job/commit (json, { job_id })           → { inserted }
//
// fetch() is the only side effect besides DOM updates + window.location.reload,
// so these tests stub fetch + location and assert the URL / method / body of
// every call plus the progress widget updates.

import { describe, it, expect, beforeEach, afterEach, beforeAll, vi } from 'vitest';
import { loadScript } from './helpers.js';

const REST_ROOT = 'https://example.test/wp-json/ffcertificate/v1/recruitment/';
const NONCE = 'test-nonce-XYZ';
const NOTICE_ID = 7;

let fetchMock;
let reloadMock;
let originalLocation;

beforeAll(() => {
	loadScript('assets/js/ffc-recruitment-import-batched.js');
});

beforeEach(() => {
	document.body.innerHTML = '';

	fetchMock = vi.fn();
	globalThis.fetch = fetchMock;
	window.fetch = fetchMock;

	originalLocation = window.location;
	reloadMock = vi.fn();
	delete window.location;
	window.location = { reload: reloadMock };
});

afterEach(() => {
	window.location = originalLocation;
	vi.useRealTimers();
});

function jsonResponse(body, status = 200) {
	return Promise.resolve({
		status,
		json: () => Promise.resolve(body),
	});
}

/** Mount the progress widget + status the orchestrator writes into. */
function mountDom() {
	const btn = document.createElement('button');
	btn.id = 'btn';
	const wrap = document.createElement('span');
	wrap.id = 'wrap';
	wrap.style.display = 'none';
	const bar = document.createElement('progress');
	bar.id = 'bar';
	const text = document.createElement('span');
	text.id = 'text';
	const status = document.createElement('span');
	status.id = 'status';
	document.body.append(btn, wrap, bar, text, status);
	return { btn, wrap, bar, text, status };
}

function callRun({ btn, wrap, bar, text, status }, file = new File(['x'], 'a.csv')) {
	return window.ffcRecruitmentImportBatched.run({
		noticeId: NOTICE_ID,
		file,
		restRoot: REST_ROOT,
		nonce: NONCE,
		btn,
		status,
		progressWrap: wrap,
		progressBar: bar,
		progressText: text,
		strings: {
			ingesting: 'ingesting',
			validating: 'validating',
			processing: 'processing',
			committing: 'committing',
			done: 'OK',
			errorPrefix: 'Error:',
			networkError: 'Network error',
			validationFailed: 'Validation failed',
		},
	});
}

describe('ffcRecruitmentImportBatched.run — global registration', () => {
	it('is exposed on window', () => {
		expect(window.ffcRecruitmentImportBatched).toBeDefined();
		expect(typeof window.ffcRecruitmentImportBatched.run).toBe('function');
	});
});

describe('ffcRecruitmentImportBatched.run — happy path', () => {
	it('orchestrates start → validate → batch → commit and reloads on success', async () => {
		vi.useFakeTimers();
		const els = mountDom();

		fetchMock
			// 1. /start
			.mockReturnValueOnce(jsonResponse({ ok: true, job_id: 'JOB-1', total: 75 }))
			// 2. /validate (no errors → safe to promote)
			.mockReturnValueOnce(jsonResponse({ ok: true, errors: [] }))
			// 3. /batch #1
			.mockReturnValueOnce(jsonResponse({ ok: true, processed: 50, total: 75, done: false }))
			// 4. /batch #2 (done)
			.mockReturnValueOnce(jsonResponse({ ok: true, processed: 75, total: 75, done: true }))
			// 5. /commit
			.mockReturnValueOnce(jsonResponse({ ok: true, inserted: 75 }));

		await callRun(els);

		expect(fetchMock).toHaveBeenCalledTimes(5);

		// 1. start — multipart, no Content-Type override (fetch sets it).
		const startCall = fetchMock.mock.calls[0];
		expect(startCall[0]).toBe(`${REST_ROOT.replace(/\/$/, '')}/notices/${NOTICE_ID}/import-job/start`);
		expect(startCall[1].method).toBe('POST');
		expect(startCall[1].body).toBeInstanceOf(FormData);
		expect(startCall[1].headers['X-WP-Nonce']).toBe(NONCE);

		// 2. validate — JSON body { job_id }.
		const validateCall = fetchMock.mock.calls[1];
		expect(validateCall[0]).toBe(`${REST_ROOT.replace(/\/$/, '')}/notices/${NOTICE_ID}/import-job/validate`);
		expect(JSON.parse(validateCall[1].body)).toEqual({ job_id: 'JOB-1' });

		// 3. first promote batch — JSON with job_id + size.
		const batch1 = fetchMock.mock.calls[2];
		expect(batch1[0]).toBe(`${REST_ROOT.replace(/\/$/, '')}/notices/${NOTICE_ID}/import-job/batch`);
		expect(JSON.parse(batch1[1].body)).toEqual({ job_id: 'JOB-1', size: 50 });
		expect(batch1[1].headers['Content-Type']).toBe('application/json');

		// 4. second promote batch — same shape.
		expect(JSON.parse(fetchMock.mock.calls[3][1].body)).toEqual({ job_id: 'JOB-1', size: 50 });

		// 5. commit.
		const commitCall = fetchMock.mock.calls[4];
		expect(commitCall[0]).toBe(`${REST_ROOT.replace(/\/$/, '')}/notices/${NOTICE_ID}/import-job/commit`);
		expect(JSON.parse(commitCall[1].body)).toEqual({ job_id: 'JOB-1' });

		// Progress widget reflects final batch + done state.
		expect(Number(els.bar.max)).toBe(75);
		expect(Number(els.bar.value)).toBe(75);
		expect(els.text.textContent).toBe('75 / 75');
		expect(els.status.textContent).toContain('OK');

		// Reload fires after the orchestrator queues a 600ms setTimeout.
		expect(reloadMock).not.toHaveBeenCalled();
		vi.advanceTimersByTime(700);
		expect(reloadMock).toHaveBeenCalledTimes(1);
	});

	it('aborts on validation errors without touching /batch or /commit', async () => {
		const els = mountDom();

		fetchMock
			.mockReturnValueOnce(jsonResponse({ ok: true, job_id: 'JOB-2', total: 5 }))
			// /validate returns 3 per-line errors.
			.mockReturnValueOnce(jsonResponse({
				ok: true,
				errors: [
					'line=2: recruitment_csv_missing_cpf_or_rf',
					'line=4: recruitment_csv_duplicate_candidate_adjutancy: matches line 3',
					'line=5: recruitment_csv_adjutancy_not_in_notice: bogus',
				],
			}));

		// errorList container so we can assert it gets populated.
		const errorList = document.createElement('ul');
		errorList.id = 'errors';
		document.body.append(errorList);

		await expect(
			window.ffcRecruitmentImportBatched.run({
				noticeId: NOTICE_ID,
				file: new File(['x'], 'a.csv'),
				restRoot: REST_ROOT,
				nonce: NONCE,
				btn: els.btn,
				status: els.status,
				progressWrap: els.wrap,
				progressBar: els.bar,
				progressText: els.text,
				errorList,
				strings: {
					ingesting: 'ingesting',
					validating: 'validating',
					done: 'OK',
					errorPrefix: 'Error:',
					networkError: 'Network error',
					validationFailed: 'Validation failed',
				},
			})
		).rejects.toThrow(/Validation failed/);

		// Only /start + /validate fired — NO batch, NO commit.
		expect(fetchMock).toHaveBeenCalledTimes(2);
		expect(reloadMock).not.toHaveBeenCalled();

		// Each error landed in the list as its own <li>.
		expect(errorList.querySelectorAll('li').length).toBe(3);
		expect(errorList.textContent).toContain('missing_cpf_or_rf');
		expect(errorList.textContent).toContain('duplicate_candidate_adjutancy');
		expect(errorList.textContent).toContain('adjutancy_not_in_notice');

		// Submit button re-enabled, widget hidden.
		expect(els.btn.disabled).toBe(false);
		expect(els.wrap.style.display).toBe('none');
	});
});

describe('ffcRecruitmentImportBatched.run — failure handling', () => {
	it('aborts the loop and surfaces the WP_Error message when /start rejects', async () => {
		const els = mountDom();

		fetchMock.mockReturnValueOnce(
			jsonResponse({ code: 'recruitment_csv_missing_headers', message: 'CSV header missing' }, 400)
		);

		await expect(callRun(els)).rejects.toThrow(/CSV header missing/);

		// No batch / commit calls when /start rejects.
		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(els.status.textContent).toMatch(/Error:.*CSV header missing/);
		// Submit button is re-enabled so the operator can fix + retry.
		expect(els.btn.disabled).toBe(false);
		expect(els.wrap.style.display).toBe('none');
		expect(reloadMock).not.toHaveBeenCalled();
	});

	it('surfaces a network-error label when the response is not valid JSON', async () => {
		const els = mountDom();

		// Simulate a gateway-timeout HTML body — the bug this whole feature
		// was built to make visible. `r.json()` rejects on non-JSON.
		fetchMock.mockReturnValueOnce(
			Promise.resolve({
				status: 504,
				json: () => Promise.reject(new SyntaxError('Unexpected token <')),
			})
		);

		await expect(callRun(els)).rejects.toThrow(/Network error/);

		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(els.status.textContent).toContain('Network error');
		expect(els.btn.disabled).toBe(false);
	});

	it('reports a batch-phase failure without committing', async () => {
		const els = mountDom();

		fetchMock
			.mockReturnValueOnce(jsonResponse({ ok: true, job_id: 'JOB-X', total: 100 }))
			.mockReturnValueOnce(jsonResponse({ ok: true, errors: [] }))
			.mockReturnValueOnce(
				jsonResponse({ code: 'recruitment_candidate_upsert_failed', message: 'upsert blew up' }, 400)
			);

		await expect(callRun(els)).rejects.toThrow(/upsert blew up/);

		// start + validate + 1 batch attempt = 3 calls. NO commit was issued.
		expect(fetchMock).toHaveBeenCalledTimes(3);
		expect(els.status.textContent).toMatch(/Error:.*upsert blew up/);
	});
});

describe('ffcRecruitmentImportBatched.run — DOM side effects', () => {
	it('reveals the progress widget synchronously, then hides it on success', async () => {
		const els = mountDom();
		// Pre-disable the button the way the inline submit handler does so
		// we can verify the orchestrator re-enables it on success.
		els.btn.disabled = true;

		// Resolve start fast, then a single completing batch + commit.
		fetchMock
			.mockReturnValueOnce(jsonResponse({ ok: true, job_id: 'JOB-2', total: 10 }))
			.mockReturnValueOnce(jsonResponse({ ok: true, errors: [] }))
			.mockReturnValueOnce(jsonResponse({ ok: true, processed: 10, total: 10, done: true, inserted: 10 }))
			.mockReturnValueOnce(jsonResponse({ ok: true, inserted: 10 }));

		vi.useFakeTimers();
		const p = callRun(els);

		// Synchronously (before any await): the widget is visible. The
		// initial progress text/bar values reflect the "ingesting…" phase
		// where total is still 0.
		expect(els.wrap.style.display).toBe('inline-flex');
		expect(els.status.textContent).toBe('ingesting');

		await p;
		vi.advanceTimersByTime(700);

		// After the success path the widget is hidden again + button re-enabled.
		expect(els.wrap.style.display).toBe('none');
		expect(els.btn.disabled).toBe(false);
	});
});
