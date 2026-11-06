// The public CSV screen takes the access hash out of the address bar once
// the page has read it (#2341), and keeps every other part of the URL.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	window.ffc_csv_download = { ajax_url: '/x', strings: {} };
	window.ffc_ajax = { ajax_url: '/x', nonce: '', strings: {} };
	document.body.innerHTML = '<div class="ffc-public-csv-download"><form><input name="hash" value="abc"></form></div>';
});

afterEach(() => {
	vi.restoreAllMocks();
	window.history.replaceState(null, '', '/');
	document.body.innerHTML = '';
});

async function load() {
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-csv-download.js');
	await new Promise((r) => setTimeout(r, 0));
}

describe('access hash in the address bar', () => {
	it('removes the hash and keeps the form id, other parameters and the fragment', async () => {
		window.history.replaceState(null, '', '/operator/?form_id=42&hash=secret123&lang=pt#top');
		await load();
		expect(window.location.pathname + window.location.search + window.location.hash).toBe('/operator/?form_id=42&lang=pt#top');
		expect(window.$('input[name="hash"]').val()).toBe('abc');
	});

	it('leaves a URL without a hash alone', async () => {
		window.history.replaceState(null, '', '/operator/?form_id=42');
		const spy = vi.spyOn(window.history, 'replaceState');
		await load();
		expect(spy).not.toHaveBeenCalled();
		expect(window.location.search).toBe('?form_id=42');
	});
});
