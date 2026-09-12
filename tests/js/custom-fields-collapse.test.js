// Tests for `assets/js/ffc-custom-fields-collapse.js` — the collapsible
// audience sections on the admin user-profile "FFC Custom Data" area.
import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-custom-fields-collapse.js';

function install() {
	document.body.innerHTML = `
		<h3 class="ffc-cf-toggle" data-target="sec1" aria-expanded="true">Heading</h3>
		<div id="sec1" class="ffc-cf-section-body">body</div>
	`;
}

beforeEach(() => {
	document.body.innerHTML = '';
});

describe('ffc-custom-fields-collapse', () => {
	it('toggles collapsed state + aria-expanded + body class on click', () => {
		install();
		loadScript(SCRIPT);
		const heading = document.querySelector('.ffc-cf-toggle');
		const body    = document.getElementById('sec1');

		heading.click();
		expect(heading.classList.contains('is-collapsed')).toBe(true);
		expect(heading.getAttribute('aria-expanded')).toBe('false');
		expect(body.classList.contains('is-collapsed')).toBe(true);

		heading.click();
		expect(heading.classList.contains('is-collapsed')).toBe(false);
		expect(heading.getAttribute('aria-expanded')).toBe('true');
		expect(body.classList.contains('is-collapsed')).toBe(false);
	});

	it('activates on Enter and Space keydown', () => {
		install();
		loadScript(SCRIPT);
		const heading = document.querySelector('.ffc-cf-toggle');

		heading.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
		expect(heading.classList.contains('is-collapsed')).toBe(true);

		heading.dispatchEvent(new window.KeyboardEvent('keydown', { key: ' ', bubbles: true }));
		expect(heading.classList.contains('is-collapsed')).toBe(false);
	});

	it('ignores other keys', () => {
		install();
		loadScript(SCRIPT);
		const heading = document.querySelector('.ffc-cf-toggle');
		heading.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
		expect(heading.classList.contains('is-collapsed')).toBe(false);
	});

	it('defers init to DOMContentLoaded when the document is still loading', () => {
		install();
		// Force the "loading" branch so the script wires DOMContentLoaded
		// instead of calling init() inline (jsdom reports 'complete' by default).
		const desc = Object.getOwnPropertyDescriptor(Document.prototype, 'readyState');
		Object.defineProperty(document, 'readyState', { configurable: true, get: () => 'loading' });
		try {
			loadScript(SCRIPT);
			const heading = document.querySelector('.ffc-cf-toggle');
			// Handler not wired yet — clicking does nothing until DOMContentLoaded.
			heading.click();
			expect(heading.classList.contains('is-collapsed')).toBe(false);
			document.dispatchEvent(new window.Event('DOMContentLoaded'));
			heading.click();
			expect(heading.classList.contains('is-collapsed')).toBe(true);
		} finally {
			if (desc) Object.defineProperty(document, 'readyState', desc);
			else delete document.readyState;
		}
	});
});

describe('ffc-custom-fields-collapse — required follows the section (#1120)', () => {
	// `.ffc-cf-section-body.collapsed` is `display: none`, and constraint
	// validation ignores visibility: an empty required field in a collapsed
	// section blocks the whole profile save, reported against a control the
	// operator cannot see or reach.
	function installWithFields() {
		document.body.innerHTML = `
			<h3 class="ffc-cf-toggle" data-target="sec1" aria-expanded="true">Heading</h3>
			<div id="sec1" class="ffc-cf-section-body">
				<input type="text" id="req" required>
				<input type="text" id="plain">
				<input type="time" id="wh" class="ffc-wh-entry1" required>
			</div>
		`;
	}

	it('strips required while collapsed and restores it on expand', () => {
		installWithFields();
		loadScript('assets/js/ffc-core.js');
		loadScript(SCRIPT);
		const heading = document.querySelector('.ffc-cf-toggle');

		heading.click();
		expect(document.getElementById('req').required).toBe(false);
		// The working-hours inputs have carried `required` since before
		// #1120 and are covered by the same sweep.
		expect(document.getElementById('wh').required).toBe(false);

		heading.click();
		expect(document.getElementById('req').required).toBe(true);
		expect(document.getElementById('wh').required).toBe(true);
	});

	it('never promotes a field that was not required', () => {
		installWithFields();
		loadScript('assets/js/ffc-core.js');
		loadScript(SCRIPT);
		const heading = document.querySelector('.ffc-cf-toggle');

		heading.click();
		heading.click();
		expect(document.getElementById('plain').required).toBe(false);
	});

	it('syncs from the markup on init when a section renders collapsed', () => {
		installWithFields();
		document.getElementById('sec1').classList.add('is-collapsed');
		loadScript('assets/js/ffc-core.js');
		loadScript(SCRIPT);

		expect(document.getElementById('req').required).toBe(false);
	});

	it('degrades quietly when ffc-core did not load', () => {
		installWithFields();
		const saved = window.FFC;
		delete window.FFC;
		loadScript(SCRIPT);

		// No throw, and the collapse itself still works.
		document.querySelector('.ffc-cf-toggle').click();
		expect(document.getElementById('sec1').classList.contains('is-collapsed')).toBe(true);
		window.FFC = saved;
	});
});
