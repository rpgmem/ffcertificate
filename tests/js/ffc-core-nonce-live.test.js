// Regression test for the stale-nonce bug exposed in 6.6.2.
//
// FFC.config.nonce used to be captured once at IIFE load. On
// full-page-cached sites (LiteSpeed/Varnish/WP Rocket),
// ffc-dynamic-fragments refreshed window.ffc_ajax.nonce to the
// per-visitor value AFTER the cached HTML loaded — but FFC.config.nonce
// kept the stale snapshot, so FFC.request() sent the wrong nonce and
// the server returned "Security check failed".
//
// Fix: getters resolve window.ffc_ajax on every read.
import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	delete window.FFC;
	delete window.ffc_ajax;
});

describe('FFC.config nonce live-resolution (fix for cached-page stale-nonce bug)', () => {
	it('reads window.ffc_ajax.nonce live, not a snapshot taken at script load', () => {
		// Stale value that simulates what the cached HTML carried.
		window.ffc_ajax = { nonce: 'stale-from-cache', ajax_url: '/x', strings: { copy: 'Copy' } };
		loadScript('assets/js/ffc-core.js');
		// Sanity: initial read is the stale value.
		expect(window.FFC.config.nonce).toBe('stale-from-cache');

		// ffc-dynamic-fragments would mutate the nonce in place here.
		window.ffc_ajax.nonce = 'fresh-from-dynamic-fragments';

		// Bug repro: snapshot would still report the stale value.
		// Fix: getter resolves window.ffc_ajax live on every read.
		expect(window.FFC.config.nonce).toBe('fresh-from-dynamic-fragments');
	});

	it('reads strings live too (same class of issue)', () => {
		window.ffc_ajax = { nonce: 'n', ajax_url: '/x', strings: { copy: 'Copy' } };
		loadScript('assets/js/ffc-core.js');
		expect(window.FFC.config.strings.copy).toBe('Copy');

		window.ffc_ajax.strings = { copy: 'Copiar' };
		expect(window.FFC.config.strings.copy).toBe('Copiar');
	});

	it('FFC.request() uses the fresh nonce after a dynamic-fragments refresh', async () => {
		window.ffc_ajax = { nonce: 'stale', ajax_url: '/x', strings: {} };
		loadScript('assets/js/ffc-core.js');

		// Mutate post-load (simulates dynamic-fragments).
		window.ffc_ajax.nonce = 'fresh';

		const calls = [];
		window.$.post = function (url, payload) {
			calls.push({ url, payload });
			const chain = { done() { return chain; }, fail() { return chain; } };
			return chain;
		};

		window.FFC.request('ffc_submit_form', 'form_id=1');
		expect(calls).toHaveLength(1);
		// Payload format: <form-serialise>&action=...&nonce=...
		expect(calls[0].payload).toContain('nonce=fresh');
		expect(calls[0].payload).not.toContain('nonce=stale');
	});

	it('falls back to empty string when ffc_ajax is undefined', () => {
		// Some admin pages enqueue ffc-core without localizing ffc_ajax.
		loadScript('assets/js/ffc-core.js');
		expect(window.FFC.config.nonce).toBe('');
		expect(window.FFC.config.strings).toEqual({});
	});
});
