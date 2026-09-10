// Tests for assets/js/ffc-dark-mode.js (32 LOC) — a pure IIFE
// side-effect module that toggles `.ffc-dark-mode` on <html> based on
// the localized `window.ffcDarkMode.mode` ('off' / 'on' / 'auto').
//
// The script applies state on parse, so each test reloads it via
// `loadScript` (vm.runInThisContext) with the desired environment set.
import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

describe('ffc-dark-mode', () => {
	beforeEach(() => {
		document.documentElement.classList.remove('ffc-dark-mode');
		window.ffcDarkMode = undefined;
	});

	it("does nothing when the setting is 'off'", () => {
		window.ffcDarkMode = { mode: 'off' };
		loadScript('assets/js/ffc-dark-mode.js');
		expect(document.documentElement.classList.contains('ffc-dark-mode')).toBe(false);
	});

	it("does nothing when ffcDarkMode is undefined (default)", () => {
		loadScript('assets/js/ffc-dark-mode.js');
		expect(document.documentElement.classList.contains('ffc-dark-mode')).toBe(false);
	});

	it("adds the class when mode === 'on'", () => {
		window.ffcDarkMode = { mode: 'on' };
		loadScript('assets/js/ffc-dark-mode.js');
		expect(document.documentElement.classList.contains('ffc-dark-mode')).toBe(true);
	});

	it("applies the OS preference when mode === 'auto' and prefers-color-scheme matches", () => {
		// jsdom doesn't implement matchMedia by default — install a stub
		// that flags dark mode and exposes addEventListener.
		const listeners = [];
		window.matchMedia = (query) => ({
			matches: true,
			media: query,
			addEventListener(_, cb) { listeners.push(cb); },
			removeEventListener() {},
			addListener() {},
			removeListener() {},
			dispatchEvent() { return true; },
			onchange: null,
		});
		window.ffcDarkMode = { mode: 'auto' };
		loadScript('assets/js/ffc-dark-mode.js');
		expect(document.documentElement.classList.contains('ffc-dark-mode')).toBe(true);
		// And the change handler responds when the OS preference flips.
		expect(listeners.length).toBe(1);
		listeners[0]({ matches: false });
		expect(document.documentElement.classList.contains('ffc-dark-mode')).toBe(false);
	});

	// The live switch. The Dark Mode select auto-saves, so without a repaint the
	// option was written and the page kept its old theme until the next load —
	// in BOTH directions, which is why it read as a save that had failed.
	describe('live switch on ffc:setting-saved', () => {
		function save(key, value) {
			document.dispatchEvent(new window.CustomEvent('ffc:setting-saved', {
				detail: { key, value },
			}));
		}

		it('paints dark when the setting is saved as on, from a page loaded light', () => {
			window.ffcDarkMode = { mode: 'off' };
			loadScript('assets/js/ffc-dark-mode.js');
			expect(document.documentElement.classList.contains('ffc-dark-mode')).toBe(false);

			save('dark_mode', 'on');

			expect(document.documentElement.classList.contains('ffc-dark-mode')).toBe(true);
		});

		it('paints light when the setting is saved as off, from a page loaded dark', () => {
			window.ffcDarkMode = { mode: 'on' };
			loadScript('assets/js/ffc-dark-mode.js');
			expect(document.documentElement.classList.contains('ffc-dark-mode')).toBe(true);

			save('dark_mode', 'off');

			expect(document.documentElement.classList.contains('ffc-dark-mode')).toBe(false);
		});

		it('ignores every other autosaved key', () => {
			window.ffcDarkMode = { mode: 'on' };
			loadScript('assets/js/ffc-dark-mode.js');

			save('cleanup_days', '30');

			expect(document.documentElement.classList.contains('ffc-dark-mode')).toBe(true);
		});

		// 'auto' → 'off' has to stop following the OS as well. Leaving the
		// listener attached repaints the page dark on the next OS change,
		// against a setting that says light.
		it('stops following the OS when auto is switched off', () => {
			const listeners = [];
			window.matchMedia = (query) => ({
				matches: true,
				media: query,
				addEventListener(_, cb) { listeners.push(cb); },
				removeEventListener(_, cb) {
					const i = listeners.indexOf(cb);
					if (i > -1) { listeners.splice(i, 1); }
				},
				addListener() {},
				removeListener() {},
				dispatchEvent() { return true; },
				onchange: null,
			});
			window.ffcDarkMode = { mode: 'auto' };
			loadScript('assets/js/ffc-dark-mode.js');
			expect(listeners.length).toBe(1);

			save('dark_mode', 'off');

			expect(document.documentElement.classList.contains('ffc-dark-mode')).toBe(false);
			expect(listeners.length).toBe(0);
		});
	});
});
