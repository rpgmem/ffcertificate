// Coverage for assets/js/ffc-section-collapse.js — the settings-page
// helper that toggles `[data-ffc-section="key"]` rows/blocks from a
// `[data-ffc-section-master="key"]` checkbox (Cache / URL Shortener /
// Rate Limit groups). Pure jQuery delegate IIFE, no public API.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	document.body.innerHTML = '';
	window.$.fx.off = true;
});

afterEach(() => {
	vi.restoreAllMocks();
	document.body.innerHTML = '';
});

async function mountAndLoad(html) {
	document.body.innerHTML = html;
	loadScript('assets/js/ffc-section-collapse.js');
	// $(applyAll) runs the initial pass on jQuery's ready queue.
	await new Promise((r) => setTimeout(r, 0));
}

describe('ffc-section-collapse — aria-controls (#2344)', () => {
	it('names every section of a checkbox master, giving an id to one that has none', async () => {
		await mountAndLoad(`
			<input type="checkbox" id="m" data-ffc-section-master="ip_api" checked />
			<table><tr data-ffc-section="ip_api" id="kept"><td>a</td></tr><tr data-ffc-section="ip_api"><td>b</td></tr></table>
			<span id="chip" data-ffc-section-master="ip_api"></span>
		`);
		const rows = document.querySelectorAll('[data-ffc-section="ip_api"]');
		expect(rows[1].id).toBe('ffc-section-ip_api-2');
		expect(document.getElementById('m').getAttribute('aria-controls')).toBe('kept ffc-section-ip_api-2');
		expect(document.getElementById('m').hasAttribute('aria-expanded')).toBe(false);
		expect(document.getElementById('chip').hasAttribute('aria-controls')).toBe(false);
	});
});

describe('ffc-section-collapse — initial state', () => {
	it('shows sections whose master is checked on load', async () => {
		await mountAndLoad(`
			<input type="checkbox" data-ffc-section-master="cache" checked />
			<div data-ffc-section="cache" id="s1">body</div>
		`);
		expect(document.getElementById('s1').style.display).not.toBe('none');
	});

	it('hides sections whose master is unchecked on load', async () => {
		await mountAndLoad(`
			<input type="checkbox" data-ffc-section-master="cache" />
			<div data-ffc-section="cache" id="s1">body</div>
		`);
		expect(document.getElementById('s1').style.display).toBe('none');
	});
});

describe('ffc-section-collapse — change handler', () => {
	it('hides every section sharing the key when the master is toggled off', async () => {
		await mountAndLoad(`
			<input type="checkbox" data-ffc-section-master="rl" checked />
			<table><tbody><tr data-ffc-section="rl" id="a"><td>a</td></tr></tbody></table>
			<div data-ffc-section="rl" id="b">b</div>
		`);
		const master = document.querySelector('[data-ffc-section-master="rl"]');
		master.checked = false;
		window.$(master).trigger('change');
		expect(document.getElementById('a').style.display).toBe('none');
		expect(document.getElementById('b').style.display).toBe('none');
	});

	it('shows sections when the master is toggled on', async () => {
		await mountAndLoad(`
			<input type="checkbox" data-ffc-section-master="rl" />
			<div data-ffc-section="rl" id="s1">body</div>
		`);
		const master = document.querySelector('[data-ffc-section-master="rl"]');
		master.checked = true;
		window.$(master).trigger('change');
		expect(document.getElementById('s1').style.display).not.toBe('none');
	});

	it('leaves sections under a different key untouched', async () => {
		await mountAndLoad(`
			<input type="checkbox" data-ffc-section-master="k1" checked />
			<input type="checkbox" data-ffc-section-master="k2" checked />
			<div data-ffc-section="k1" id="s1">one</div>
			<div data-ffc-section="k2" id="s2">two</div>
		`);
		const m1 = document.querySelector('[data-ffc-section-master="k1"]');
		m1.checked = false;
		window.$(m1).trigger('change');
		expect(document.getElementById('s1').style.display).toBe('none');
		expect(document.getElementById('s2').style.display).not.toBe('none');
	});
});

describe('ffc-section-collapse — required fields (#2344)', () => {
	it('takes a hidden section\'s required fields with it, and gives them back', async () => {
		if (!window.FFC || !window.FFC.setRequiredWithin) {
			loadScript('assets/js/ffc-core.js');
		}
		await mountAndLoad(`
			<form>
				<input type="checkbox" data-ffc-section-master="ip" />
				<table data-ffc-section="ip"><tbody><tr><td>
					<input type="number" id="max" value="" required>
					<input type="number" id="free" value="">
				</td></tr></tbody></table>
			</form>
		`);
		const max = document.getElementById('max');
		const free = document.getElementById('free');
		// Off on load: a cleared required number no longer blocks the save.
		expect(max.required).toBe(false);
		expect(document.querySelector('form').checkValidity()).toBe(true);

		const master = document.querySelector('[data-ffc-section-master="ip"]');
		master.checked = true;
		window.$(master).trigger('change');
		expect(max.required).toBe(true);
		// A field that was never required is not promoted.
		expect(free.required).toBe(false);
	});
});
