// Tests for `assets/js/ffc-copy-shortcode.js`.
//
// Copy-to-clipboard handler for the list-table shortcode buttons (the forms
// list and the audience calendar and environment lists, #1814),
// extracted from an inline <script> in class-ffc-form-list-columns.php
// (Item 10 of the frontend audit). In jsdom `document.readyState` is
// 'complete', so the script's readyState guard runs init() synchronously
// on load — no DOMContentLoaded dispatch needed.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-copy-shortcode.js';

function installButtons() {
	document.body.innerHTML = `
		<button type="button" class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-copy ffc-copy-shortcode" data-shortcode="[ffc_form id=42]" data-copied="Copiado!" aria-label="Copy shortcode"></button>
	`;
}

afterEach(() => {
	document.body.innerHTML = '';
	vi.restoreAllMocks();
	vi.useRealTimers();
});

describe('ffc-copy-shortcode', () => {
	it('copies the shortcode via the Clipboard API on click', () => {
		installButtons();
		const writeText = vi.fn(() => Promise.resolve());
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		});

		loadScript(SCRIPT);
		document.querySelector('.ffc-copy-shortcode').click();

		expect(writeText).toHaveBeenCalledWith('[ffc_form id=42]');
	});

	it('shows the check mark, announces the copy, and clears both after the timeout (#2344)', async () => {
		vi.useFakeTimers();
		installButtons();
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText: vi.fn(() => Promise.resolve()) },
			configurable: true,
		});

		loadScript(SCRIPT);
		const live = document.querySelector('.ffc-copy-shortcode-live');
		expect(live.getAttribute('aria-live')).toBe('polite');
		expect(live.textContent).toBe('');
		const btn = document.querySelector('.ffc-copy-shortcode');
		btn.click();
		await Promise.resolve();

		expect(btn.classList.contains('is-copied')).toBe(true);
		expect(btn.classList.contains('ffc-icon-checkmark')).toBe(true);
		expect(btn.classList.contains('ffc-icon-copy')).toBe(false);
		expect(live.textContent).toBe('Copiado!');
		vi.advanceTimersByTime(1500);
		expect(btn.classList.contains('is-copied')).toBe(false);
		expect(btn.classList.contains('ffc-icon-copy')).toBe(true);
		expect(live.textContent).toBe('');
	});

	it('claims nothing when the copy fails', async () => {
		installButtons();
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText: vi.fn(() => Promise.reject(new Error('denied'))) },
			configurable: true,
		});
		document.execCommand = vi.fn(() => false);

		loadScript(SCRIPT);
		const btn = document.querySelector('.ffc-copy-shortcode');
		btn.click();
		await new Promise((r) => setTimeout(r, 0));

		expect(document.execCommand).toHaveBeenCalledWith('copy');
		expect(btn.classList.contains('is-copied')).toBe(false);
		expect(document.querySelector('.ffc-copy-shortcode-live').textContent).toBe('');
	});

	it('falls back to execCommand when the Clipboard API is unavailable', () => {
		installButtons();
		Object.defineProperty(navigator, 'clipboard', {
			value: undefined,
			configurable: true,
		});
		const execCommand = vi.fn(() => true);
		document.execCommand = execCommand;

		loadScript(SCRIPT);
		document.querySelector('.ffc-copy-shortcode').click();

		expect(execCommand).toHaveBeenCalledWith('copy');
		// The temporary textarea is cleaned up.
		expect(document.querySelector('textarea')).toBeNull();
		expect(document.querySelector('.ffc-copy-shortcode').classList.contains('is-copied')).toBe(true);
	});

	it('defers init to DOMContentLoaded while the document is still loading', () => {
		installButtons();
		const writeText = vi.fn(() => Promise.resolve());
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		});
		const readyStateSpy = vi
			.spyOn(document, 'readyState', 'get')
			.mockReturnValue('loading');
		const addSpy = vi.spyOn(document, 'addEventListener');

		loadScript(SCRIPT);

		const dclCall = addSpy.mock.calls.find((c) => c[0] === 'DOMContentLoaded');
		expect(dclCall).toBeTruthy();
		readyStateSpy.mockRestore();
		dclCall[1]();

		document.querySelector('.ffc-copy-shortcode').click();
		expect(writeText).toHaveBeenCalledWith('[ffc_form id=42]');
	});

	it('no-ops when there are no copy buttons on the page', () => {
		document.body.innerHTML = '<div>no buttons</div>';
		const writeText = vi.fn(() => Promise.resolve());
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		});

		expect(() => loadScript(SCRIPT)).not.toThrow();
		expect(writeText).not.toHaveBeenCalled();
	});
});
