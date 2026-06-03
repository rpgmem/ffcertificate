// Tests for `assets/js/ffc-form-list-copy-shortcode.js`.
//
// Copy-to-clipboard handler for the forms list-table shortcode buttons,
// extracted from an inline <script> in class-ffc-form-list-columns.php
// (Item 10 of the frontend audit). In jsdom `document.readyState` is
// 'complete', so the script's readyState guard runs init() synchronously
// on load — no DOMContentLoaded dispatch needed.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-form-list-copy-shortcode.js';

function installButtons() {
	document.body.innerHTML = `
		<button type="button" class="ffc-copy-shortcode" data-shortcode="[ffc_form id=42]">Copy</button>
	`;
}

afterEach(() => {
	document.body.innerHTML = '';
	vi.restoreAllMocks();
	vi.useRealTimers();
});

describe('ffc-form-list-copy-shortcode', () => {
	it('copies the shortcode via the Clipboard API on click', () => {
		installButtons();
		const writeText = vi.fn();
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		});

		loadScript(SCRIPT);
		document.querySelector('.ffc-copy-shortcode').click();

		expect(writeText).toHaveBeenCalledWith('[ffc_form id=42]');
	});

	it('flashes the .copied class and clears it after the timeout', () => {
		vi.useFakeTimers();
		installButtons();
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText: vi.fn() },
			configurable: true,
		});

		loadScript(SCRIPT);
		const btn = document.querySelector('.ffc-copy-shortcode');
		btn.click();

		expect(btn.classList.contains('copied')).toBe(true);
		vi.advanceTimersByTime(1500);
		expect(btn.classList.contains('copied')).toBe(false);
	});

	it('falls back to execCommand when the Clipboard API is unavailable', () => {
		installButtons();
		Object.defineProperty(navigator, 'clipboard', {
			value: undefined,
			configurable: true,
		});
		const execCommand = vi.fn();
		document.execCommand = execCommand;

		loadScript(SCRIPT);
		document.querySelector('.ffc-copy-shortcode').click();

		expect(execCommand).toHaveBeenCalledWith('copy');
		// The temporary textarea is cleaned up.
		expect(document.querySelector('textarea')).toBeNull();
	});

	it('no-ops when there are no copy buttons on the page', () => {
		document.body.innerHTML = '<div>no buttons</div>';
		const writeText = vi.fn();
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		});

		expect(() => loadScript(SCRIPT)).not.toThrow();
		expect(writeText).not.toHaveBeenCalled();
	});
});
