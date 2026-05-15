// Tests for the FFC.request helper introduced in 6.5.4 as the new
// promise-based AJAX chokepoint for the plugin's admin/frontend code.
//
// Wraps jQuery.post so callers get a native Promise resolving with
// response.data on success or rejecting with an Error on protocol
// failure ({success:false} from the server) or network failure.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'core-nonce',
		strings: {
			error: 'Generic error',
			connectionError: 'Lost connection',
		},
	};
	loadScript('assets/js/ffc-core.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('FFC.request — payload composition', () => {
	it('POSTs to ajaxUrl with action + nonce + data', async () => {
		const chain = { done: () => chain, fail: () => chain };
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation((url, payload) => {
			chain.done = function (cb) { cb({ success: true, data: { ok: 1 } }); return chain; };
			chain.fail = function () { return chain; };
			return chain;
		});

		const result = await window.FFC.request('ffc_some_action', { qty: 3 });

		expect(postSpy).toHaveBeenCalledOnce();
		const [url, payload] = postSpy.mock.calls[0];
		expect(url).toBe('/wp-admin/admin-ajax.php');
		expect(payload).toMatchObject({
			action: 'ffc_some_action',
			nonce: 'core-nonce',
			qty: 3,
		});
		expect(result).toEqual({ ok: 1 });
	});

	it('honours an override nonce / override ajaxUrl', async () => {
		const chain = { done: () => chain, fail: () => chain };
		vi.spyOn(window.$, 'post').mockImplementation((url, payload) => {
			chain.done = function (cb) { cb({ success: true, data: 'ok' }); return chain; };
			return chain;
		});

		await window.FFC.request('a', {}, { nonce: 'other', ajaxUrl: '/custom-ajax.php' });

		const calls = window.$.post.mock.calls;
		expect(calls[0][0]).toBe('/custom-ajax.php');
		expect(calls[0][1].nonce).toBe('other');
	});

	it('preserves a data.nonce set by the caller when no options.nonce is given', async () => {
		// Regression test for the bug where ffc-form-list-features.js,
		// ffc-admin-autosave.js, ffc-admin-activity-log.js,
		// ffc-admin-migrations.js, ffc-admin-submissions-bulk.js, and
		// ffc-cache-actions.js all passed their per-action nonce via
		// `data.nonce` — which used to be silently overwritten by the
		// global FFC.config.nonce (an `ffc_admin_pdf_nonce` value that
		// can't verify against any of those endpoints' actions). Server
		// returned 403 → jQuery .fail() → user saw "Connection error".
		const chain = { done: () => chain, fail: () => chain };
		vi.spyOn(window.$, 'post').mockImplementation((url, payload) => {
			chain.done = function (cb) { cb({ success: true, data: 'ok' }); return chain; };
			return chain;
		});

		await window.FFC.request('ffc_update_form_feature', { nonce: 'per-action-nonce', form_id: 5 });

		const payload = window.$.post.mock.calls[0][1];
		expect(payload.nonce).toBe('per-action-nonce');
		expect(payload.form_id).toBe(5);
	});

	it('options.nonce wins over data.nonce wins over config.nonce', async () => {
		const chain = { done: () => chain, fail: () => chain };
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation((url, payload) => {
			chain.done = function (cb) { cb({ success: true, data: 'ok' }); return chain; };
			return chain;
		});

		// options.nonce + data.nonce both set → options wins.
		await window.FFC.request('a', { nonce: 'data-n' }, { nonce: 'opt-n' });
		expect(postSpy.mock.calls.at(-1)[1].nonce).toBe('opt-n');

		// Only data.nonce → data wins over the core-nonce default.
		await window.FFC.request('a', { nonce: 'data-only' });
		expect(postSpy.mock.calls.at(-1)[1].nonce).toBe('data-only');

		// Neither → config.nonce default.
		await window.FFC.request('a', {});
		expect(postSpy.mock.calls.at(-1)[1].nonce).toBe('core-nonce');
	});

	it('rejects with a server-supplied message on response.success=false', async () => {
		const chain = { done: () => chain, fail: () => chain };
		vi.spyOn(window.$, 'post').mockImplementation(() => {
			chain.done = function (cb) { cb({ success: false, data: { message: 'Quota exceeded' } }); return chain; };
			return chain;
		});

		await expect(window.FFC.request('a')).rejects.toThrow('Quota exceeded');
	});

	it('falls back to the localised generic error when server omits a message', async () => {
		const chain = { done: () => chain, fail: () => chain };
		vi.spyOn(window.$, 'post').mockImplementation(() => {
			chain.done = function (cb) { cb({ success: false }); return chain; };
			return chain;
		});

		await expect(window.FFC.request('a')).rejects.toThrow('Generic error');
	});

	it('rejects with the localised connection error on network failure', async () => {
		const chain = { done: () => chain, fail: () => chain };
		vi.spyOn(window.$, 'post').mockImplementation(() => {
			chain.done = () => chain;
			chain.fail = function (cb) { cb(); return chain; };
			return chain;
		});

		await expect(window.FFC.request('a')).rejects.toThrow('Lost connection');
	});

	it('treats a null response body as a failure', async () => {
		const chain = { done: () => chain, fail: () => chain };
		vi.spyOn(window.$, 'post').mockImplementation(() => {
			chain.done = function (cb) { cb(null); return chain; };
			return chain;
		});

		await expect(window.FFC.request('a')).rejects.toThrow('Generic error');
	});
});
