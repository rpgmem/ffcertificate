// Tests for assets/js/ffc-recruitment-candidate-edit.js — the two
// document-delegated handlers extracted from the candidate-edit page's
// inline <script> blocks (frontend-audit Item 3):
//   - PII reveal/hide (.ffc-pii-reveal-btn) → POST …/candidates/{id}/reveal-pii
//   - Adjutancy swap   (.ffc-adjutancy-swap-btn) → PATCH …/classifications/{id}/adjutancy
//
// fetch() + DOM mutation are the only side effects, so we stub fetch and
// assert the request shape plus the in-place DOM updates.

import { describe, it, expect, beforeEach, afterEach, beforeAll, vi } from 'vitest';
import { loadScript } from './helpers.js';

const REVEAL_ROOT = 'https://example.test/wp-json/ffcertificate/v1/recruitment/candidates/';
const CLASS_ROOT = 'https://example.test/wp-json/ffcertificate/v1/recruitment/classifications/';
const NONCE = 'test-nonce-XYZ';

let fetchMock;

beforeAll(() => {
	// The IIFE captures window.ffcRecruitmentCandidateEdit by reference at
	// load time, so seed the config before loading the script once.
	window.ffcRecruitmentCandidateEdit = {
		revealRoot: REVEAL_ROOT,
		classRoot: CLASS_ROOT,
		nonce: NONCE,
		strings: { hide: 'Hide', reveal: 'Reveal', saved: 'Saved', error: 'Error' },
	};
	loadScript('assets/js/ffc-recruitment-candidate-edit.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	fetchMock = vi.fn();
	globalThis.fetch = fetchMock;
	window.fetch = fetchMock;
});

afterEach(() => {
	vi.restoreAllMocks();
});

const flush = () => Promise.resolve().then(() => Promise.resolve());

function response({ status = 200, ok, body }) {
	return Promise.resolve({
		status,
		ok: ok !== undefined ? ok : status >= 200 && status < 300,
		json: () => Promise.resolve(body),
	});
}

// ── PII reveal / hide ────────────────────────────────────────────────

function mountPii({ mask = '***', field = 'cpf', cid = '5' } = {}) {
	const parent = document.createElement('div');
	const btn = document.createElement('button');
	btn.className = 'ffc-pii-reveal-btn';
	btn.setAttribute('data-ffc-pii-candidate', cid);
	btn.setAttribute('data-ffc-pii-field', field);
	btn.textContent = 'Reveal';
	const ph = document.createElement('span');
	ph.className = 'ffc-pii-placeholder';
	ph.setAttribute('data-ffc-pii-field', field);
	ph.textContent = mask;
	parent.append(btn, ph);
	document.body.appendChild(parent);
	return { btn, ph };
}

describe('candidate-edit — PII reveal/hide', () => {
	it('reveals on success: swaps placeholder, flips to Hide, saves the mask', async () => {
		const { btn, ph } = mountPii({ mask: '***' });
		fetchMock.mockReturnValue(response({ status: 200, body: { value: '123.456.789-00' } }));

		btn.click();
		await flush();

		const [url, opts] = fetchMock.mock.calls[0];
		expect(url).toBe(REVEAL_ROOT + '5/reveal-pii');
		expect(opts.method).toBe('POST');
		expect(opts.headers['X-WP-Nonce']).toBe(NONCE);
		expect(ph.textContent).toBe('123.456.789-00');
		expect(btn.textContent).toBe('Hide');
		expect(btn.getAttribute('data-ffc-pii-revealed')).toBe('1');
		expect(btn.getAttribute('data-ffc-pii-mask')).toBe('***');
		expect(btn.disabled).toBe(false);
	});

	it('hides on a second click without calling fetch (restores the mask)', async () => {
		const { btn, ph } = mountPii();
		btn.setAttribute('data-ffc-pii-revealed', '1');
		btn.setAttribute('data-ffc-pii-mask', '***');
		btn.textContent = 'Hide';
		ph.textContent = '123.456.789-00';

		btn.click();
		await flush();

		expect(fetchMock).not.toHaveBeenCalled();
		expect(ph.textContent).toBe('***');
		expect(btn.textContent).toBe('Reveal');
		expect(btn.getAttribute('data-ffc-pii-revealed')).toBeNull();
	});

	it('surfaces the server message on a non-2xx response', async () => {
		const { btn, ph } = mountPii();
		fetchMock.mockReturnValue(response({ status: 403, body: { message: 'Forbidden' } }));

		btn.click();
		await flush();

		expect(ph.textContent).toBe('[Forbidden]');
		expect(btn.disabled).toBe(false);
	});

	it('shows the generic error and re-enables on a network failure', async () => {
		const { btn, ph } = mountPii();
		fetchMock.mockReturnValue(Promise.reject(new Error('boom')));

		btn.click();
		await flush();

		expect(ph.textContent).toBe('[Error]');
		expect(btn.disabled).toBe(false);
	});

	it('is a no-op when the placeholder for the field is missing', async () => {
		const btn = document.createElement('button');
		btn.className = 'ffc-pii-reveal-btn';
		btn.setAttribute('data-ffc-pii-candidate', '5');
		btn.setAttribute('data-ffc-pii-field', 'cpf');
		document.body.appendChild(btn);

		btn.click();
		await flush();

		expect(fetchMock).not.toHaveBeenCalled();
	});
});

// ── Adjutancy swap ───────────────────────────────────────────────────

function mountSwap({ cid = '9', value = '3' } = {}) {
	const wrap = document.createElement('span');
	wrap.className = 'ffc-adjutancy-swap';
	wrap.setAttribute('data-ffc-cls-id', cid);
	const btn = document.createElement('button');
	btn.className = 'ffc-adjutancy-swap-btn';
	const sel = document.createElement('select');
	sel.className = 'ffc-adjutancy-swap-select';
	const opt = document.createElement('option');
	opt.value = value;
	opt.selected = true;
	sel.appendChild(opt);
	const msg = document.createElement('span');
	msg.className = 'ffc-adjutancy-swap-msg';
	wrap.append(btn, sel, msg);
	document.body.appendChild(wrap);
	return { wrap, btn, sel, msg };
}

describe('candidate-edit — adjutancy swap', () => {
	it('PATCHes the selected adjutancy and shows Saved on success', async () => {
		const { btn, msg } = mountSwap({ cid: '9', value: '3' });
		fetchMock.mockReturnValue(response({ ok: true, body: { success: true } }));

		btn.click();
		await flush();

		const [url, opts] = fetchMock.mock.calls[0];
		expect(url).toBe(CLASS_ROOT + '9/adjutancy');
		expect(opts.method).toBe('PATCH');
		expect(opts.headers['X-WP-Nonce']).toBe(NONCE);
		expect(JSON.parse(opts.body)).toEqual({ adjutancy_id: 3 });
		expect(msg.textContent).toBe('Saved');
		expect(btn.disabled).toBe(false);
	});

	it('shows the error + server message when success is false', async () => {
		const { btn, msg } = mountSwap();
		fetchMock.mockReturnValue(response({ ok: false, status: 400, body: { message: 'nope' } }));

		btn.click();
		await flush();

		expect(msg.textContent).toBe('Error: nope');
		expect(btn.disabled).toBe(false);
	});

	it('shows the generic error on a network failure', async () => {
		const { btn, msg } = mountSwap();
		fetchMock.mockReturnValue(Promise.reject(new Error('down')));

		btn.click();
		await flush();

		expect(msg.textContent).toBe('Error');
		expect(btn.disabled).toBe(false);
	});

	it('is a no-op when the wrapper is missing', async () => {
		const btn = document.createElement('button');
		btn.className = 'ffc-adjutancy-swap-btn';
		document.body.appendChild(btn);

		btn.click();
		await flush();

		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('is a no-op when the wrapper lacks the select / msg / cls-id', async () => {
		const wrap = document.createElement('span');
		wrap.className = 'ffc-adjutancy-swap';
		// No data-ffc-cls-id, no select, no msg.
		const btn = document.createElement('button');
		btn.className = 'ffc-adjutancy-swap-btn';
		wrap.appendChild(btn);
		document.body.appendChild(wrap);

		btn.click();
		await flush();

		expect(fetchMock).not.toHaveBeenCalled();
	});
});
