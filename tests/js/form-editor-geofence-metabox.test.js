// Tests for `assets/js/ffc-form-editor-geofence-metabox.js`.
//
// Two selector-guarded jQuery initializers extracted from inline <script>
// blocks in class-ffc-form-editor-geofence-metabox.php (Item 10 of the
// frontend audit): the Date/Time "during" row dual-gate and the
// geolocation area-source toggles. jsdom has no layout, so visibility is
// asserted via css('display') rather than :visible (per CLAUDE.md).
import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-form-editor-geofence-metabox.js';

async function loadOnReady() {
	loadScript(SCRIPT);
	await new Promise((r) => setTimeout(r, 0));
}

beforeEach(() => {
	window.$.fx.off = true;
	document.body.innerHTML = '';
});

describe('ffc-form-editor-geofence-metabox — during-row dual gate', () => {
	function installDateTime({ multi, daily }) {
		// Wrap the <tr> in a full table — jsdom drops a bare <tr> set via
		// innerHTML on <body>.
		document.body.innerHTML = `
			<input type="checkbox" id="ffc_geofence_multi_day" ${multi ? 'checked' : ''}>
			<input type="radio" name="ffc_geofence[time_mode]" value="span" ${daily ? '' : 'checked'}>
			<input type="radio" name="ffc_geofence[time_mode]" value="daily" ${daily ? 'checked' : ''}>
			<table><tbody>
				<tr id="ffc-datetime-hide-mode-during-row"><td>during</td></tr>
			</tbody></table>
		`;
	}

	it('hides the during-row unless multi-day AND daily on init', async () => {
		installDateTime({ multi: true, daily: false });
		await loadOnReady();
		expect(window.$('#ffc-datetime-hide-mode-during-row').css('display')).toBe('none');
	});

	it('shows the during-row when both multi-day and daily are set', async () => {
		installDateTime({ multi: true, daily: true });
		await loadOnReady();
		expect(window.$('#ffc-datetime-hide-mode-during-row').css('display')).not.toBe('none');
	});

	it('re-syncs the during-row when time_mode changes at runtime', async () => {
		installDateTime({ multi: true, daily: false });
		await loadOnReady();
		// Flip to daily — now multi && daily → row shows.
		window.$('input[name="ffc_geofence[time_mode]"][value="span"]').prop('checked', false);
		window.$('input[name="ffc_geofence[time_mode]"][value="daily"]')
			.prop('checked', true)
			.trigger('change');
		expect(window.$('#ffc-datetime-hide-mode-during-row').css('display')).not.toBe('none');
	});
});

describe('ffc-form-editor-geofence-metabox — geo area-source toggle', () => {
	function installGeo(source) {
		// The handler walks up to the enclosing <td>, so the radios + panels
		// must live inside a real table cell (jsdom drops a bare <td>).
		document.body.innerHTML = `
			<table><tbody><tr><td>
				<input type="radio" name="ffc_geofence[geo_area_source]" value="locations" ${source === 'locations' ? 'checked' : ''}>
				<input type="radio" name="ffc_geofence[geo_area_source]" value="custom" ${source === 'custom' ? 'checked' : ''}>
				<div class="ffc-geo-source-locations">locations</div>
				<div class="ffc-geo-source-custom">custom</div>
			</td></tr></tbody></table>
		`;
	}

	it('shows the custom editor and hides the locations list when source=custom', async () => {
		installGeo('custom');
		await loadOnReady();
		expect(window.$('.ffc-geo-source-custom').css('display')).not.toBe('none');
		expect(window.$('.ffc-geo-source-locations').css('display')).toBe('none');
	});

	it('flips panels when the source radio changes', async () => {
		installGeo('custom');
		await loadOnReady();
		window.$('input[name="ffc_geofence[geo_area_source]"][value="custom"]').prop('checked', false);
		window.$('input[name="ffc_geofence[geo_area_source]"][value="locations"]')
			.prop('checked', true)
			.trigger('change');
		expect(window.$('.ffc-geo-source-locations').css('display')).not.toBe('none');
		expect(window.$('.ffc-geo-source-custom').css('display')).toBe('none');
	});
});
