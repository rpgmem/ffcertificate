// Tests for the small isolated scripts in `assets/js/` that are
// pure IIFE side-effect modules (no public global API).
//
//   - ffc-dark-mode.js          (32 LOC)  — toggles `.ffc-dark-mode` on <html>.
//   - ffc-smtp-settings.js      (36 LOC)  — mode toggle + disable-all in admin.
//
// (ffc-user-capabilities.js now has its own suite — see
//  tests/js/user-capabilities.test.js.)
//
// Each script registers handlers / applies state on parse. Tests
// reload the script via `vm.runInThisContext` with the desired
// environment in place, then assert against DOM / window state.
//
// Sprint C of #168.
import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

// ----------------------------------------------------------------------
// ffc-dark-mode.js
// ----------------------------------------------------------------------

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
});

// ----------------------------------------------------------------------
// ffc-user-capabilities.js — the per-user capability manager was redesigned
// (grouped cards, search, presets, slug chips, origin badges) and now has
// its own dedicated suite in tests/js/user-capabilities.test.js.
// ----------------------------------------------------------------------

// ----------------------------------------------------------------------
// ffc-smtp-settings.js
// ----------------------------------------------------------------------

describe('ffc-smtp-settings', () => {
	beforeEach(async () => {
		// Reflects the v4 markup — master switch is now #emails_enabled
		// (positive framing), and the option rows carry .ffc-email-option-row
		// so the script can hide them as a group.
		document.body.innerHTML = `
			<input type="checkbox" id="emails_enabled" checked />
			<tr class="ffc-email-option-row"><td>per-context toggle row</td></tr>
			<tr class="ffc-email-option-row">
				<td>
					<div id="smtp-mode-options">
						<label><input type="radio" name="ffc_settings[smtp_mode]" value="wordpress" checked />WordPress</label>
						<label><input type="radio" name="ffc_settings[smtp_mode]" value="custom" />Custom</label>
					</div>
				</td>
			</tr>
			<div id="smtp-options" class="ffc-hidden" style="display:none">
				<input id="smtp_host" />
				<select id="smtp_secure"><option>tls</option></select>
			</div>
		`;
		loadScript('assets/js/ffc-smtp-settings.js');
		await new Promise((r) => setTimeout(r, 0));
	});

	it("reveals #smtp-options when smtp_mode is changed to 'custom'", () => {
		const radio = document.querySelector('input[name="ffc_settings[smtp_mode]"][value="custom"]');
		radio.checked = true;
		window.$(radio).trigger('change');
		expect(document.getElementById('smtp-options').classList.contains('ffc-hidden')).toBe(false);
	});

	it('hides every email-option row when #emails_enabled is unchecked', () => {
		const cb = document.getElementById('emails_enabled');
		cb.checked = false;
		window.$(cb).trigger('change');
		document.querySelectorAll('.ffc-email-option-row').forEach((row) => {
			expect(row.style.display).toBe('none');
		});
		expect(document.getElementById('smtp-options').classList.contains('ffc-hidden')).toBe(true);
	});

	// Keeping the test that asserts the SMTP-mode toggle still acts on
	// the options block when the master switch is on — guards against
	// regressing the existing mode-radio behavior.
	it('keeps SMTP inputs reachable when emails are enabled and mode=custom', () => {
		const radio = document.querySelector('input[name="ffc_settings[smtp_mode]"][value="custom"]');
		radio.checked = true;
		window.$(radio).trigger('change');
		const inputs = document.querySelectorAll(
			'#smtp-mode-options input, #smtp-options input, #smtp-options select'
		);
		// v4 no longer disables inputs — visibility is the gate. Asserts
		// the inputs stay enabled (the master switch is on) and the
		// smtp-options block is no longer marked hidden.
		inputs.forEach((i) => expect(i.disabled).toBe(false));
		expect(document.getElementById('smtp-options').classList.contains('ffc-hidden')).toBe(false);
	});

	it('shows every email-option row on page load when the master switch is on', () => {
		document.querySelectorAll('.ffc-email-option-row').forEach((row) => {
			// jQuery .toggle(true) sets display: '' (empty string), not 'block'.
			expect(row.style.display).not.toBe('none');
		});
	});
});
