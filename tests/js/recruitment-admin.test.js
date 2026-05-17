// Tests for the recruitment admin JS bundle. Two delegated handlers
// are covered:
//
//   1. `form[data-ffc-create-endpoint]` submit handler — POSTs FormData
//      to the localized REST root + endpoint path, reloads on success
//      (response `id`), alerts on failure.
//   2. `input[data-ffc-color-endpoint][data-ffc-entity-id]` change
//      handler — PATCHes a `{color}` payload via X-HTTP-Method-Override
//      and updates the sibling `[data-ffc-color-hex]` element.
//
// The IIFE registers document-level listeners that survive across
// tests, so the bundle is loaded ONCE at the file's top level
// (matching how WordPress enqueues it). Each test resets DOM + mocks
// in `beforeEach`.

import { describe, it, expect, beforeEach, afterEach, beforeAll, vi } from 'vitest';
import { loadScript } from './helpers.js';

const REST_ROOT = 'https://example.test/wp-json/ffcertificate/v1/recruitment/';
const NONCE = 'test-nonce-XYZ';

let fetchMock;
let reloadMock;
let originalLocation;

beforeAll(() => {
	// Seed the localized config and load the bundle once. The IIFE
	// registers document-level handlers; subsequent tests just swap
	// out DOM + mock state.
	window.ffcRecruitmentAdmin = {
		restRoot: REST_ROOT,
		nonce: NONCE,
	};
	loadScript('assets/js/ffc-recruitment-admin.js');
});

beforeEach(() => {
	document.body.innerHTML = '';

	fetchMock = vi.fn();
	globalThis.fetch = fetchMock;
	window.fetch = fetchMock;

	// Replace window.location with a stub whose `reload` is a spy. jsdom
	// throws when you assign a string to location.href in tests; replacing
	// the whole object is safer.
	originalLocation = window.location;
	reloadMock = vi.fn();
	delete window.location;
	window.location = { reload: reloadMock };

	window.alert = vi.fn();
});

afterEach(() => {
	window.location = originalLocation;
});

function mockJsonResponse(body, status = 200) {
	return Promise.resolve({
		status,
		json: () => Promise.resolve(body),
	});
}

describe('ffc-recruitment-admin: ffcRecruitmentAdmin.fetch helper', () => {
	it('prepends restRoot and injects the X-WP-Nonce header', async () => {
		fetchMock.mockReturnValue(mockJsonResponse({ ok: true }));

		await window.ffcRecruitmentAdmin.fetch('notices', { method: 'GET' });

		expect(fetchMock).toHaveBeenCalledTimes(1);
		const [url, opts] = fetchMock.mock.calls[0];
		expect(url).toBe(REST_ROOT + 'notices');
		expect(opts.headers['X-WP-Nonce']).toBe(NONCE);
		expect(opts.credentials).toBe('same-origin');
		expect(opts.method).toBe('GET');
	});

	it('returns { status, body } when fetch resolves', async () => {
		fetchMock.mockReturnValue(mockJsonResponse({ id: 42, color: '#abc' }, 201));

		const res = await window.ffcRecruitmentAdmin.fetch('reasons', { method: 'POST' });

		expect(res).toEqual({ status: 201, body: { id: 42, color: '#abc' } });
	});

	it('coerces a non-JSON body to null without throwing', async () => {
		fetchMock.mockReturnValue(
			Promise.resolve({
				status: 500,
				json: () => Promise.reject(new Error('not json')),
			})
		);

		const res = await window.ffcRecruitmentAdmin.fetch('boom', { method: 'GET' });

		expect(res.status).toBe(500);
		expect(res.body).toBeNull();
	});
});

describe('ffc-recruitment-admin: create-form submit handler', () => {
	it('POSTs FormData to <restRoot><endpoint> and reloads on success', async () => {
		fetchMock.mockReturnValue(mockJsonResponse({ id: 7 }));

		document.body.innerHTML = `
			<form id="ffc-create-reason" method="post" data-ffc-create-endpoint="reasons">
				<input name="slug" value="absent">
				<input name="label" value="Absent">
				<button type="submit">Create</button>
			</form>
		`;

		const form = document.getElementById('ffc-create-reason');
		// Fire a bubbling submit so the document-level delegated listener catches it.
		form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

		// fetch is called synchronously; reload happens after the promise chain.
		expect(fetchMock).toHaveBeenCalledTimes(1);
		const [url, opts] = fetchMock.mock.calls[0];
		expect(url).toBe(REST_ROOT + 'reasons');
		expect(opts.method).toBe('POST');
		expect(opts.body).toBeInstanceOf(FormData);
		expect(opts.body.get('slug')).toBe('absent');
		expect(opts.body.get('label')).toBe('Absent');

		// Flush the promise chain.
		await new Promise((r) => setTimeout(r, 0));
		expect(reloadMock).toHaveBeenCalledTimes(1);
		expect(window.alert).not.toHaveBeenCalled();
	});

	it('alerts the server message on failure (no `id` in response)', async () => {
		fetchMock.mockReturnValue(mockJsonResponse({ message: 'Slug already exists' }, 422));

		document.body.innerHTML = `
			<form data-ffc-create-endpoint="notices">
				<input name="code" value="N1">
				<input name="name" value="Notice 1">
			</form>
		`;

		document.querySelector('form').dispatchEvent(
			new Event('submit', { bubbles: true, cancelable: true })
		);

		await new Promise((r) => setTimeout(r, 0));

		expect(reloadMock).not.toHaveBeenCalled();
		expect(window.alert).toHaveBeenCalledWith('Slug already exists');
	});

	it('falls back to JSON.stringify(body) when the response has no message', async () => {
		fetchMock.mockReturnValue(mockJsonResponse({ unexpected: 'shape' }, 500));

		document.body.innerHTML = `
			<form data-ffc-create-endpoint="adjutancies">
				<input name="slug" value="x">
			</form>
		`;

		document.querySelector('form').dispatchEvent(
			new Event('submit', { bubbles: true, cancelable: true })
		);

		await new Promise((r) => setTimeout(r, 0));

		expect(window.alert).toHaveBeenCalledWith(JSON.stringify({ unexpected: 'shape' }));
	});

	it('ignores submit events on forms without data-ffc-create-endpoint', () => {
		document.body.innerHTML = `
			<form id="other">
				<input name="x" value="y">
			</form>
		`;

		const event = new Event('submit', { bubbles: true, cancelable: true });
		document.getElementById('other').dispatchEvent(event);

		expect(fetchMock).not.toHaveBeenCalled();
		// The handler does not preventDefault on forms it ignores.
		expect(event.defaultPrevented).toBe(false);
	});

	it('preventDefaults on matching forms so the native submit does not navigate', () => {
		fetchMock.mockReturnValue(mockJsonResponse({ id: 1 }));

		document.body.innerHTML = `
			<form data-ffc-create-endpoint="reasons" action="/should-not-fire">
				<input name="slug" value="x">
			</form>
		`;

		const event = new Event('submit', { bubbles: true, cancelable: true });
		document.querySelector('form').dispatchEvent(event);

		expect(event.defaultPrevented).toBe(true);
	});
});

describe('ffc-recruitment-admin: color-picker change handler', () => {
	function mountPicker(endpoint, id, color = '#888888') {
		document.body.innerHTML = `
			<div class="wrap">
				<input type="color"
				       value="${color}"
				       data-ffc-color-endpoint="${endpoint}"
				       data-ffc-entity-id="${id}">
				<code data-ffc-color-hex>${color}</code>
			</div>
		`;
		return document.querySelector('input[type="color"]');
	}

	it('PATCHes the new color via X-HTTP-Method-Override and updates sibling hex', async () => {
		fetchMock.mockReturnValue(mockJsonResponse({ color: '#abcdef' }));

		const input = mountPicker('reasons', 42, '#000000');
		input.value = '#abcdef';
		input.dispatchEvent(new Event('change', { bubbles: true }));

		expect(fetchMock).toHaveBeenCalledTimes(1);
		const [url, opts] = fetchMock.mock.calls[0];
		expect(url).toBe(REST_ROOT + 'reasons/42');
		expect(opts.method).toBe('POST');
		expect(opts.headers['X-HTTP-Method-Override']).toBe('PATCH');
		expect(opts.headers['X-WP-Nonce']).toBe(NONCE);
		expect(opts.body).toBeInstanceOf(FormData);
		expect(opts.body.get('color')).toBe('#abcdef');

		await new Promise((r) => setTimeout(r, 0));
		expect(input.value).toBe('#abcdef');
		expect(document.querySelector('[data-ffc-color-hex]').textContent).toBe('#abcdef');
		expect(window.alert).not.toHaveBeenCalled();
	});

	it('works for the adjutancies endpoint (irregular plural)', async () => {
		fetchMock.mockReturnValue(mockJsonResponse({ color: '#123456' }));

		const input = mountPicker('adjutancies', 9, '#777777');
		input.value = '#123456';
		input.dispatchEvent(new Event('change', { bubbles: true }));

		const [url] = fetchMock.mock.calls[0];
		expect(url).toBe(REST_ROOT + 'adjutancies/9');

		await new Promise((r) => setTimeout(r, 0));
		expect(document.querySelector('[data-ffc-color-hex]').textContent).toBe('#123456');
	});

	it('alerts on failure when no `color` is returned', async () => {
		fetchMock.mockReturnValue(mockJsonResponse({ message: 'Bad color' }, 422));

		const input = mountPicker('reasons', 1);
		input.dispatchEvent(new Event('change', { bubbles: true }));

		await new Promise((r) => setTimeout(r, 0));
		expect(window.alert).toHaveBeenCalledWith('Bad color');
	});

	it('ignores change events on inputs without the data attributes', () => {
		document.body.innerHTML = `<input type="color" value="#abc">`;

		document.querySelector('input').dispatchEvent(
			new Event('change', { bubbles: true })
		);

		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('handles a missing sibling hex element gracefully (no throw)', async () => {
		fetchMock.mockReturnValue(mockJsonResponse({ color: '#999999' }));

		document.body.innerHTML = `
			<input type="color"
			       value="#000"
			       data-ffc-color-endpoint="reasons"
			       data-ffc-entity-id="3">
		`;

		const input = document.querySelector('input');
		expect(() => {
			input.dispatchEvent(new Event('change', { bubbles: true }));
		}).not.toThrow();

		await new Promise((r) => setTimeout(r, 0));
		expect(input.value).toBe('#999999');
	});
});
