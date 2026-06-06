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
