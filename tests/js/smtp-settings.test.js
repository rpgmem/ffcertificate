// Tests for assets/js/ffc-smtp-settings.js (36 LOC) — the admin email
// settings IIFE: master enable/disable switch (#emails_enabled) hides
// the per-context option rows as a group, and the SMTP-mode radio
// reveals/hides the #smtp-options block.
//
// The script registers handlers on parse, so it is loaded via
// `loadScript` after the v4 markup is mounted.
import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

describe('ffc-smtp-settings', () => {
	beforeEach(async () => {
		window.$.fx.off = true;
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

	it('re-hides #smtp-options when mode is switched back to non-custom', () => {
		const custom = document.querySelector('input[name="ffc_settings[smtp_mode]"][value="custom"]');
		custom.checked = true;
		window.$(custom).trigger('change');
		expect(document.getElementById('smtp-options').classList.contains('ffc-hidden')).toBe(false);

		// Switch back to WordPress mode → slideUp callback re-adds ffc-hidden.
		const wp = document.querySelector('input[name="ffc_settings[smtp_mode]"][value="wordpress"]');
		wp.checked = true;
		custom.checked = false;
		window.$(wp).trigger('change');
		expect(document.getElementById('smtp-options').classList.contains('ffc-hidden')).toBe(true);
	});

	it('ignores mode changes while the master switch is off', () => {
		const cb = document.getElementById('emails_enabled');
		cb.checked = false;
		window.$(cb).trigger('change');

		const custom = document.querySelector('input[name="ffc_settings[smtp_mode]"][value="custom"]');
		custom.checked = true;
		window.$(custom).trigger('change');

		// Master switch off → the mode handler returns early; block stays hidden.
		expect(document.getElementById('smtp-options').classList.contains('ffc-hidden')).toBe(true);
	});
});

describe('ffc-smtp-settings — initial visibility with custom mode', () => {
	it('reveals #smtp-options on load when emails are on AND mode=custom', async () => {
		window.$.fx.off = true;
		document.body.innerHTML = `
			<input type="checkbox" id="emails_enabled" checked />
			<tr class="ffc-email-option-row"><td>row</td></tr>
			<div id="smtp-mode-options">
				<label><input type="radio" name="ffc_settings[smtp_mode]" value="wordpress" />WordPress</label>
				<label><input type="radio" name="ffc_settings[smtp_mode]" value="custom" checked />Custom</label>
			</div>
			<div id="smtp-options" class="ffc-hidden" style="display:none"></div>
		`;
		loadScript('assets/js/ffc-smtp-settings.js');
		await new Promise((r) => setTimeout(r, 0));

		// applyVisibility() on load took the `on && modeIsCustom()` branch.
		expect(document.getElementById('smtp-options').classList.contains('ffc-hidden')).toBe(false);
	});
});
