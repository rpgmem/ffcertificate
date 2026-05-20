// 6.6.3 — auto-recover from stale-nonce server response.
//
// When the server rejects with wp_send_json_error including
// `refresh_nonce: true` + `new_nonce: 'XXX'`, FFC.request should:
//   - update window.ffc_ajax.nonce in place;
//   - retry the same call exactly once with the fresh nonce;
//   - NOT retry a second time if the retry also fails (no ping-pong);
//   - NOT retry if the server didn't ask for it.
//
// Pinned because this is the iOS Safari / cached-HTML / Private Relay
// safety net for the "Security check failed" symptom.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	delete window.FFC;
	delete window.ffc_ajax;
});

function setupAjax(window_ffc_ajax) {
	window.ffc_ajax = window_ffc_ajax;
	loadScript('assets/js/ffc-core.js');
}

// Helper: replace jQuery.post with a queue of responses. Each call
// pops one response from the queue and feeds .done(cb) with it.
function queuePostResponses(responses) {
	const calls = [];
	let idx = 0;
	const original = window.$.post;
	window.$.post = function (url, payload) {
		calls.push({ url, payload });
		const res = responses[idx++];
		const chain = {
			done(cb) { if (res && res.success !== undefined) cb(res); return chain; },
			fail(cb) { if (res && res.failXhr) cb(res.failXhr); return chain; },
		};
		return chain;
	};
	return { calls, restore: () => { window.$.post = original; } };
}

describe('FFC.request — stale nonce auto-recovery', () => {
	it('updates ffc_ajax.nonce and retries when server returns refresh_nonce', async () => {
		setupAjax({ nonce: 'stale-nonce', ajax_url: '/x', strings: {} });
		const { calls, restore } = queuePostResponses([
			{ success: false, data: { message: 'Security check failed.', refresh_nonce: true, new_nonce: 'fresh-nonce' } },
			{ success: true, data: { ok: 1 } },
		]);
		const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

		const result = await window.FFC.request('ffc_submit_form', 'form_id=1');

		expect(result).toEqual({ ok: 1 });
		expect(calls).toHaveLength(2);
		expect(calls[0].payload).toContain('nonce=stale-nonce');
		expect(calls[1].payload).toContain('nonce=fresh-nonce');
		// window.ffc_ajax.nonce mutated so other callers (and the next
		// non-retried call) see the fresh value too.
		expect(window.ffc_ajax.nonce).toBe('fresh-nonce');
		expect(warnSpy).toHaveBeenCalledWith(expect.stringContaining('ffc_submit_form'));
		restore();
	});

	it('retries at most once even if the retry also fails with refresh_nonce', async () => {
		setupAjax({ nonce: 'stale-nonce', ajax_url: '/x', strings: {} });
		const { calls, restore } = queuePostResponses([
			{ success: false, data: { message: 'fail', refresh_nonce: true, new_nonce: 'A' } },
			{ success: false, data: { message: 'fail', refresh_nonce: true, new_nonce: 'B' } },
			{ success: false, data: { message: 'fail', refresh_nonce: true, new_nonce: 'C' } },
		]);
		vi.spyOn(console, 'warn').mockImplementation(() => {});

		await expect(window.FFC.request('ffc_submit_form', 'form_id=1')).rejects.toThrow('fail');

		// Exactly 2 calls: initial + 1 retry. Never 3.
		expect(calls).toHaveLength(2);
		restore();
	});

	it('does NOT retry when the server omits refresh_nonce (e.g. CAPTCHA failure)', async () => {
		setupAjax({ nonce: 'n', ajax_url: '/x', strings: {} });
		const { calls, restore } = queuePostResponses([
			{ success: false, data: { message: 'Invalid CAPTCHA', refresh_captcha: true } },
		]);

		await expect(window.FFC.request('ffc_submit_form', 'form_id=1')).rejects.toThrow('Invalid CAPTCHA');
		expect(calls).toHaveLength(1);
		restore();
	});

	it('does NOT retry when refresh_nonce is true but new_nonce is missing', async () => {
		setupAjax({ nonce: 'n', ajax_url: '/x', strings: {} });
		const { calls, restore } = queuePostResponses([
			{ success: false, data: { message: 'fail', refresh_nonce: true /* no new_nonce */ } },
		]);

		await expect(window.FFC.request('ffc_submit_form', 'form_id=1')).rejects.toThrow('fail');
		expect(calls).toHaveLength(1);
		restore();
	});

	it('preserves the error payload on the final rejection so callers can read err.data', async () => {
		setupAjax({ nonce: 'n', ajax_url: '/x', strings: {} });
		const { restore } = queuePostResponses([
			{ success: false, data: { message: 'fail', refresh_nonce: true, new_nonce: 'A' } },
			{ success: false, data: { message: 'still failed', code: 'final_error' } },
		]);
		vi.spyOn(console, 'warn').mockImplementation(() => {});

		try {
			await window.FFC.request('ffc_submit_form', 'form_id=1');
			throw new Error('should have rejected');
		} catch (err) {
			expect(err.message).toBe('still failed');
			expect(err.data && err.data.code).toBe('final_error');
		}
		restore();
	});
});
