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
