// Tests for the jQuery shell in assets/js/ffc-geofence-admin.js (191 LOC).
//
// The pure validator (analyzeDateTimeOrder) lives in
// ffc-geofence-validation.js and is covered by geofence-admin.test.js.
// This file covers the surrounding admin shell that was sitting at 0%:
//
//   - DateTime enable toggle (disables / re-enables tab inputs)
//   - Time-mode row visibility (shown only when date_start !== date_end)
//   - "Display during" hide-mode row toggle (visible only in daily mode)
//   - Geo enable toggle + auto-enable GPS when both methods are off
//   - Alert + revert when the operator tries to disable both GPS and IP
//   - Live validity refresh adds .ffc-input-invalid via the validator
//
// Sprint A of the JS coverage roadmap — closes the 0% blind spot on the
// admin shell. The validator is exercised separately to keep that
// pure-helper suite small and fast.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function mountMetabox() {
	document.body.innerHTML = `
		<div id="ffc-geofence">
			<div id="ffc-tab-datetime">
				<label><input type="checkbox" name="ffc_geofence[datetime_enabled]"> Enable</label>
				<tr><td><input type="date" name="ffc_geofence[date_start]" value=""></td></tr>
				<tr><td><input type="date" name="ffc_geofence[date_end]" value=""></td></tr>
				<tr><td><input type="time" name="ffc_geofence[time_start]" value=""></td></tr>
				<tr><td><input type="time" name="ffc_geofence[time_end]" value=""></td></tr>
				<tr id="ffc-time-mode-row" style="display:none">
					<td>
						<label><input type="radio" name="ffc_geofence[time_mode]" value="daily" checked></label>
						<label><input type="radio" name="ffc_geofence[time_mode]" value="span"></label>
					</td>
				</tr>
				<tr id="ffc-datetime-hide-mode-during-row">
					<td><select name="ffc_geofence[hide_mode_during]"><option value="hidden">hidden</option></select></td>
				</tr>
				<p class="ffc-datetime-order-error ffc-hidden"></p>
			</div>

			<div id="ffc-tab-geolocation">
				<label><input type="checkbox" name="ffc_geofence[geo_enabled]"></label>
				<label><input type="checkbox" name="ffc_geofence[geo_gps_enabled]"></label>
				<label><input type="checkbox" name="ffc_geofence[geo_ip_enabled]"></label>
				<textarea name="ffc_geofence[geo_allowlist]"></textarea>
			</div>
		</div>
	`;
}

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffc_geofence_admin = { alert_message: 'Pick at least one method.' };
	// jsdom lacks layout, so jQuery's slide animations never complete on
	// their own. Disable the FX queue so slideUp/slideDown apply display
	// immediately and tests can assert on css('display').
	window.$.fx.off = true;
	// The shell depends on the extracted validator.
	loadScript('assets/js/ffc-geofence-validation.js');
});

// DateTime master-toggle visibility is no longer this file's concern
// after #238 Sprint 3 — the production PHP wraps the sub-rows in a
// `.ffc-collapsed-target` tbody and ffc-admin.js handles the collapse.
// geofence-admin.js retains the time-mode-row visibility logic (driven
// by the date_start/date_end values, not by the master toggle), so the
// only thing we verify here is that the master toggle no longer
// disables sibling inputs as a side effect.
describe('ffc-geofence-admin — datetime enable toggle (post-Sprint 3)', () => {
	it('does NOT disable sibling inputs when the checkbox is unchecked on load', async () => {
		mountMetabox();
		loadScript('assets/js/ffc-geofence-admin.js');
		await new Promise((r) => setTimeout(r, 0));

		// The disable-on-master-off logic moved to a wrapper-collapse model.
		// This script must not touch the `disabled` property on siblings.
		expect(window.$('input[name="ffc_geofence[date_start]"]').prop('disabled')).toBe(false);
		expect(window.$('input[name="ffc_geofence[time_end]"]').prop('disabled')).toBe(false);
	});

	it('does NOT disable sibling inputs when the checkbox is checked either', async () => {
		mountMetabox();
		loadScript('assets/js/ffc-geofence-admin.js');
		await new Promise((r) => setTimeout(r, 0));

		window.$('input[name="ffc_geofence[datetime_enabled]"]').prop('checked', true).trigger('change');
		expect(window.$('input[name="ffc_geofence[date_start]"]').prop('disabled')).toBe(false);
	});
});

describe('ffc-geofence-admin — time-mode row visibility', () => {
	it('hides the row when start and end dates are equal', async () => {
		mountMetabox();
		// Pre-populate equal dates.
		window.$('input[name="ffc_geofence[date_start]"]').val('2026-06-01');
		window.$('input[name="ffc_geofence[date_end]"]').val('2026-06-01');
		loadScript('assets/js/ffc-geofence-admin.js');
		await new Promise((r) => setTimeout(r, 0));

		// slideUp drives display:none — confirm it's no longer visible.
		expect(window.$('#ffc-time-mode-row').is(':visible')).toBe(false);
	});

	it('shows the row when start and end dates differ', async () => {
		// Pre-populate distinct dates so the IIFE's load-time call to
		// toggleTimeModeRow() immediately drives slideDown.
		mountMetabox();
		window.$('input[name="ffc_geofence[date_start]"]').val('2026-06-01');
		window.$('input[name="ffc_geofence[date_end]"]').val('2026-06-30');
		loadScript('assets/js/ffc-geofence-admin.js');
		await new Promise((r) => setTimeout(r, 0));

		expect(window.$('#ffc-time-mode-row').css('display')).not.toBe('none');
	});
});

describe('ffc-geofence-admin — during-mode row', () => {
	// The row has one owner, ffc-form-editor-geofence-metabox.js, which applies
	// the server's rule (multi-day AND daily). This file used to toggle it on
	// time_mode alone, and the two answered the same change differently
	// depending on load order (#1614).
	it('leaves the during-row alone when time_mode changes', async () => {
		mountMetabox();
		loadScript('assets/js/ffc-geofence-admin.js');
		await new Promise((r) => setTimeout(r, 0));
		const $row = window.$('#ffc-datetime-hide-mode-during-row');
		const before = $row.attr('class') || '';

		window.$('input[name="ffc_geofence[time_mode]"][value="daily"]').prop('checked', false);
		window.$('input[name="ffc_geofence[time_mode]"][value="span"]').prop('checked', true).trigger('change');

		expect($row.attr('class') || '').toBe(before);
		expect($row.attr('style')).toBeUndefined();
	});
});

describe('ffc-geofence-admin — geo enable + method validation', () => {
	it('auto-enables GPS when geo is enabled with both methods off', async () => {
		mountMetabox();
		loadScript('assets/js/ffc-geofence-admin.js');
		await new Promise((r) => setTimeout(r, 0));

		window.$('input[name="ffc_geofence[geo_enabled]"]').prop('checked', true).trigger('change');

		expect(window.$('input[name="ffc_geofence[geo_gps_enabled]"]').is(':checked')).toBe(true);
	});

	it('alerts and reverts when the operator unchecks the last enabled method', async () => {
		mountMetabox();
		// Enable geo + GPS; IP off. Unchecking GPS would zero both methods.
		window.$('input[name="ffc_geofence[geo_enabled]"]').prop('checked', true);
		window.$('input[name="ffc_geofence[geo_gps_enabled]"]').prop('checked', true);
		loadScript('assets/js/ffc-geofence-admin.js');
		await new Promise((r) => setTimeout(r, 0));

		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		const $gps = window.$('input[name="ffc_geofence[geo_gps_enabled]"]');
		$gps.prop('checked', false).trigger('change');

		expect(alertSpy).toHaveBeenCalledWith('Pick at least one method.');
		// Revert: the handler re-checks the box.
		expect($gps.is(':checked')).toBe(true);
	});
});

describe('ffc-geofence-admin — live validity refresh', () => {
	it('adds .ffc-input-invalid on the date inputs when end < start', async () => {
		mountMetabox();
		loadScript('assets/js/ffc-geofence-admin.js');
		await new Promise((r) => setTimeout(r, 0));

		window
			.$('input[name="ffc_geofence[date_start]"]')
			.val('2026-06-30')
			.trigger('input');
		window
			.$('input[name="ffc_geofence[date_end]"]')
			.val('2026-06-01')
			.trigger('input');

		expect(window.$('input[name="ffc_geofence[date_start]"]').hasClass('ffc-input-invalid')).toBe(true);
		expect(window.$('input[name="ffc_geofence[date_end]"]').hasClass('ffc-input-invalid')).toBe(true);
		// The inline error paragraph carries the first error message and
		// loses the shared hidden class.
		const $msg = window.$('p.ffc-datetime-order-error');
		expect($msg.hasClass('ffc-hidden')).toBe(false);
		expect($msg.text().length).toBeGreaterThan(0);
	});

	it('clears the invalid state once the dates are reordered', async () => {
		mountMetabox();
		loadScript('assets/js/ffc-geofence-admin.js');
		await new Promise((r) => setTimeout(r, 0));

		window.$('input[name="ffc_geofence[date_start]"]').val('2026-06-30').trigger('input');
		window.$('input[name="ffc_geofence[date_end]"]').val('2026-06-01').trigger('input');
		// Fix the order.
		window.$('input[name="ffc_geofence[date_end]"]').val('2026-07-01').trigger('input');

		expect(window.$('input[name="ffc_geofence[date_start]"]').hasClass('ffc-input-invalid')).toBe(false);
		expect(window.$('input[name="ffc_geofence[date_end]"]').hasClass('ffc-input-invalid')).toBe(false);
		expect(window.$('p.ffc-datetime-order-error').hasClass('ffc-hidden')).toBe(true);
	});
});
