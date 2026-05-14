// Tests for assets/js/ffc-geolocation-settings.js — the small admin
// page-init script that powers the "When GPS fails" preset combobox.
// (Auto-save wiring for `data-ffc-autosave-key` inputs moved to
// ffc-admin-autosave.js — see admin-autosave.test.js.)
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcGeolocationSettings = {
		presetCases: {
			tolerant: {
				permission_denied: 'allow',
				no_api: 'allow',
				position_unavailable: 'allow',
				timeout: 'allow',
				safety_timer: 'allow',
			},
			hybrid: {
				permission_denied: 'allow',
				no_api: 'allow',
				position_unavailable: 'block',
				timeout: 'block',
				safety_timer: 'block',
			},
			strict: {
				permission_denied: 'block',
				no_api: 'block',
				position_unavailable: 'block',
				timeout: 'block',
				safety_timer: 'block',
			},
		},
	};
});

afterEach(() => {
	vi.restoreAllMocks();
	delete window.FFC;
});

async function reload() {
	loadScript('assets/js/ffc-geolocation-settings.js');
	await new Promise((r) => setTimeout(r, 0));
}

function mountPresetFixture(preset = 'hybrid') {
	document.body.innerHTML = `
		<select id="ffc_gps_fallback_preset">
			<option value="tolerant" ${preset === 'tolerant' ? 'selected' : ''}>T</option>
			<option value="hybrid" ${preset === 'hybrid' ? 'selected' : ''}>H</option>
			<option value="strict" ${preset === 'strict' ? 'selected' : ''}>S</option>
			<option value="custom" ${preset === 'custom' ? 'selected' : ''}>C</option>
		</select>
		<table class="ffc-gps-fallback-cases" style="${preset !== 'custom' ? 'display:none' : ''}">
			<tbody>
				${['permission_denied', 'no_api', 'position_unavailable', 'timeout', 'safety_timer']
					.map(
						(k) => `
							<tr>
								<td>${k}</td>
								<td><input type="radio" name="gps_fallback_cases[${k}]" value="allow"></td>
								<td><input type="radio" name="gps_fallback_cases[${k}]" value="block"></td>
							</tr>`,
					)
					.join('')}
			</tbody>
		</table>
	`;
}

// ----------------------------------------------------------------------
// Preset toggle behaviour
// ----------------------------------------------------------------------

describe('geolocation-settings — preset toggle', () => {
	it('bails when the preset combobox is not present', async () => {
		document.body.innerHTML = '<div>nothing</div>';
		expect(() => reload()).not.toThrow();
		await new Promise((r) => setTimeout(r, 0));
	});

	it('bails when the cases table is not present', async () => {
		document.body.innerHTML = '<select id="ffc_gps_fallback_preset"></select>';
		expect(() => reload()).not.toThrow();
		await new Promise((r) => setTimeout(r, 0));
	});

	it('hides the table when switching to a named preset and snaps radios', async () => {
		mountPresetFixture('custom');
		await reload();
		// Sanity — table starts visible because preset is custom.
		expect(window.$('.ffc-gps-fallback-cases').css('display')).not.toBe('none');

		window.$('#ffc_gps_fallback_preset').val('strict').trigger('change');

		expect(window.$('.ffc-gps-fallback-cases').css('display')).toBe('none');
		// All radios snap to the strict matrix (all block).
		const checked = window
			.$('.ffc-gps-fallback-cases input:checked')
			.map((_, el) => el.value)
			.get();
		expect(checked).toEqual(['block', 'block', 'block', 'block', 'block']);
	});

	it('shows the table when switching to Custom without snapping anything', async () => {
		mountPresetFixture('hybrid');
		await reload();
		// Initially hidden because preset != custom.
		expect(window.$('.ffc-gps-fallback-cases').css('display')).toBe('none');

		window.$('#ffc_gps_fallback_preset').val('custom').trigger('change');

		expect(window.$('.ffc-gps-fallback-cases').css('display')).not.toBe('none');
		// Custom doesn't change radio state — nothing was pre-checked, so
		// nothing is checked now.
		expect(window.$('.ffc-gps-fallback-cases input:checked').length).toBe(0);
	});

	it('snaps the hybrid matrix when switching from custom → hybrid', async () => {
		mountPresetFixture('custom');
		await reload();

		window.$('#ffc_gps_fallback_preset').val('hybrid').trigger('change');

		// Hybrid: first two allow, last three block.
		const map = {};
		window
			.$('.ffc-gps-fallback-cases input:checked')
			.each(function () {
				const m = this.name.match(/gps_fallback_cases\[(.+)\]/);
				map[m[1]] = this.value;
			});
		expect(map).toEqual({
			permission_denied: 'allow',
			no_api: 'allow',
			position_unavailable: 'block',
			timeout: 'block',
			safety_timer: 'block',
		});
	});

	it('no-ops when an unknown preset is selected (no presetCases entry)', async () => {
		mountPresetFixture('hybrid');
		await reload();

		window.$('#ffc_gps_fallback_preset').val('unknown').trigger('change');

		// Table hidden because isCustom=false; no checked radios (nothing was
		// snapped because the preset's matrix is missing).
		expect(window.$('.ffc-gps-fallback-cases').css('display')).toBe('none');
		expect(window.$('.ffc-gps-fallback-cases input:checked').length).toBe(0);
	});

	it('handles missing window.ffcGeolocationSettings (defaults to empty map)', async () => {
		delete window.ffcGeolocationSettings;
		mountPresetFixture('hybrid');
		await reload();

		// Triggering a change should not throw — the presetCases is empty.
		expect(() => {
			window.$('#ffc_gps_fallback_preset').val('strict').trigger('change');
		}).not.toThrow();
	});
});
