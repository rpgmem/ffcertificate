// Tests for assets/js/ffc-geolocation-settings.js — the small admin
// page-init script that powers the "When GPS fails" steps and its
// "Customize per case" toggle (#1758).
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

function mountPresetFixture(preset = 'hybrid', custom = false) {
	document.body.innerHTML = `
		<fieldset id="ffc_gps_fallback_preset" ${custom ? 'disabled' : ''}>
			${['tolerant', 'hybrid', 'strict']
				.map((v) => `<input type="radio" name="gps_fallback_preset" value="${v}" ${preset === v ? 'checked' : ''}>`)
				.join('')}
		</fieldset>
		<input type="checkbox" id="ffc_gps_fallback_custom" ${custom ? 'checked' : ''}>
		<table class="ffc-gps-fallback-cases ffc-collapsed-target" data-ffc-master="ffc_gps_fallback_custom">
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

function checkedCases() {
	const map = {};
	window.$('.ffc-gps-fallback-cases input:checked').each(function () {
		const m = this.name.match(/gps_fallback_cases\[(.+)\]/);
		map[m[1]] = this.value;
	});
	return map;
}

function choose(value) {
	window.$(`input[name="gps_fallback_preset"][value="${value}"]`).prop('checked', true).trigger('change');
}

// ----------------------------------------------------------------------
// Steps + "Customize per case" toggle (#1758)
// ----------------------------------------------------------------------

describe('geolocation-settings — presets and the per-case grid', () => {
	it('bails when the steps control is not present', async () => {
		document.body.innerHTML = '<div>nothing</div>';
		expect(() => reload()).not.toThrow();
		await new Promise((r) => setTimeout(r, 0));
	});

	it('bails when the cases table is not present', async () => {
		document.body.innerHTML = '<fieldset id="ffc_gps_fallback_preset"></fieldset>';
		expect(() => reload()).not.toThrow();
		await new Promise((r) => setTimeout(r, 0));
	});

	it('snaps the grid to the step that is chosen', async () => {
		mountPresetFixture('hybrid');
		await reload();

		choose('strict');

		expect(Object.values(checkedCases())).toEqual(['block', 'block', 'block', 'block', 'block']);
	});

	it('snaps the hybrid matrix', async () => {
		mountPresetFixture('strict');
		await reload();

		choose('hybrid');

		expect(checkedCases()).toEqual({
			permission_denied: 'allow',
			no_api: 'allow',
			position_unavailable: 'block',
			timeout: 'block',
			safety_timer: 'block',
		});
	});

	it('disables the steps while customizing, without touching the grid', async () => {
		mountPresetFixture('hybrid');
		await reload();

		window.$('#ffc_gps_fallback_custom').prop('checked', true).trigger('change');

		expect(window.$('#ffc_gps_fallback_preset').prop('disabled')).toBe(true);
		expect(window.$('.ffc-gps-fallback-cases input:checked').length).toBe(0);
	});

	it('re-enables the steps and snaps the grid back when customizing is turned off', async () => {
		mountPresetFixture('tolerant', true);
		await reload();

		window.$('#ffc_gps_fallback_custom').prop('checked', false).trigger('change');

		expect(window.$('#ffc_gps_fallback_preset').prop('disabled')).toBe(false);
		expect(Object.values(checkedCases())).toEqual(['allow', 'allow', 'allow', 'allow', 'allow']);
	});

	it('no-ops when an unknown preset is chosen (no presetCases entry)', async () => {
		mountPresetFixture('hybrid');
		window.$('#ffc_gps_fallback_preset').append('<input type="radio" name="gps_fallback_preset" value="unknown">');
		await reload();

		choose('unknown');

		expect(window.$('.ffc-gps-fallback-cases input:checked').length).toBe(0);
	});

	it('handles missing window.ffcGeolocationSettings (defaults to empty map)', async () => {
		delete window.ffcGeolocationSettings;
		mountPresetFixture('hybrid');
		await reload();

		expect(() => choose('strict')).not.toThrow();
		expect(window.$('.ffc-gps-fallback-cases input:checked').length).toBe(0);
	});
});
