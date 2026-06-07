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
		expect(heading.classList.contains('collapsed')).toBe(true);
		expect(heading.getAttribute('aria-expanded')).toBe('false');
		expect(body.classList.contains('collapsed')).toBe(true);

		heading.click();
		expect(heading.classList.contains('collapsed')).toBe(false);
		expect(heading.getAttribute('aria-expanded')).toBe('true');
		expect(body.classList.contains('collapsed')).toBe(false);
	});

	it('activates on Enter and Space keydown', () => {
		install();
		loadScript(SCRIPT);
		const heading = document.querySelector('.ffc-cf-toggle');

		heading.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
		expect(heading.classList.contains('collapsed')).toBe(true);

		heading.dispatchEvent(new window.KeyboardEvent('keydown', { key: ' ', bubbles: true }));
		expect(heading.classList.contains('collapsed')).toBe(false);
	});

	it('ignores other keys', () => {
		install();
		loadScript(SCRIPT);
		const heading = document.querySelector('.ffc-cf-toggle');
		heading.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
		expect(heading.classList.contains('collapsed')).toBe(false);
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
			expect(heading.classList.contains('collapsed')).toBe(false);
			document.dispatchEvent(new window.Event('DOMContentLoaded'));
			heading.click();
			expect(heading.classList.contains('collapsed')).toBe(true);
		} finally {
			if (desc) Object.defineProperty(document, 'readyState', desc);
			else delete document.readyState;
		}
	});
});
