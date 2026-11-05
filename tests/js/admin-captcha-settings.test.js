// Tests for `assets/js/ffc-admin-captcha-settings.js` (#2344): the widget
// settings panel on the Captcha tab is hidden under the math-only mode and
// shown under the others, as the mode changes, without a page reload.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-admin-captcha-settings.js';

function screen(checked) {
	document.body.innerHTML = ['math', 'altcha', 'both'].map((mode) =>
		'<label><input type="radio" name="ffc_settings[captcha_provider]" value="' + mode + '"' + (mode === checked ? ' checked' : '') + '> ' + mode + '</label>'
	).join('') + '<input type="text" name="ffc_settings[other]" id="other"><div id="ffc-captcha-widget-panel">widget settings</div>';
}

function pick(mode) {
	const radio = document.querySelector('input[value="' + mode + '"]');
	radio.checked = true;
	radio.dispatchEvent(new Event('change', { bubbles: true }));
}

const panel = () => document.getElementById('ffc-captcha-widget-panel');

let added = [];

beforeEach(() => {
	// Each load binds a listener on document; keep them to remove after.
	const original = document.addEventListener.bind(document);
	added = [];
	vi.spyOn(document, 'addEventListener').mockImplementation((type, fn, opts) => {
		added.push([type, fn, opts]);
		return original(type, fn, opts);
	});
});

afterEach(() => {
	vi.restoreAllMocks();
	added.forEach(([type, fn, opts]) => document.removeEventListener(type, fn, opts));
	document.body.innerHTML = '';
});

describe('ffc-admin-captcha-settings', () => {
	it('hides the panel on load when the mode is math only', () => {
		screen('math');
		loadScript(SCRIPT);
		expect(panel().hidden).toBe(true);
	});

	it('shows the panel on load under ALTCHA and under both', () => {
		screen('altcha');
		loadScript(SCRIPT);
		expect(panel().hidden).toBe(false);

		screen('both');
		panel().hidden = true;
		loadScript(SCRIPT);
		expect(panel().hidden).toBe(false);
	});

	it('follows the mode as it changes', () => {
		screen('altcha');
		loadScript(SCRIPT);

		pick('math');
		expect(panel().hidden).toBe(true);
		pick('both');
		expect(panel().hidden).toBe(false);
	});

	it('ignores a change to any other field', () => {
		screen('altcha');
		loadScript(SCRIPT);
		panel().hidden = true;

		document.getElementById('other').dispatchEvent(new Event('change', { bubbles: true }));
		expect(panel().hidden).toBe(true);
	});

	it('shows the panel when no mode is checked, since only math hides it', () => {
		screen(null);
		panel().hidden = true;
		loadScript(SCRIPT);
		expect(panel().hidden).toBe(false);
	});

	it('does nothing on a screen without the panel', () => {
		document.body.innerHTML = '<input type="radio" name="ffc_settings[captcha_provider]" value="math" checked>';
		expect(() => loadScript(SCRIPT)).not.toThrow();
		expect(() => pick('math')).not.toThrow();
	});

	it('waits for the document when it is still loading', () => {
		screen('math');
		vi.spyOn(document, 'readyState', 'get').mockReturnValue('loading');
		loadScript(SCRIPT);
		expect(panel().hidden).toBe(false);

		const ready = added.find(([type]) => type === 'DOMContentLoaded');
		expect(ready).toBeTruthy();
		ready[1]();
		expect(panel().hidden).toBe(true);
	});
});
