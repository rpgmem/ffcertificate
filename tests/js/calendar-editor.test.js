// Tests for `assets/js/ffc-calendar-editor.js` — the working-hours
// editor for the self-scheduling calendar admin page.
//
// The script is a `$(document).ready(...)` IIFE that wires document-
// level delegates for: add working-hour row, remove working-hour row
// (with confirm), toggle cancellation hours, toggle scheduling
// visibility. Local `FFCCalendarEditor` object isn't exposed; tests
// drive the delegates.
//
// Sprint 3 of the JS coverage roadmap.
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(async () => {
	window.ffcSelfSchedulingEditor = {
		strings: {
			confirmDelete: 'Delete this row?',
			schedulingForced: 'Forced to Private.',
			schedulingDesc: 'Public or private.',
			confirmCleanup: 'Delete these appointments?',
			confirmCleanupAll: 'Delete ALL appointments?',
			deleting: 'Deleting…',
			errorDeleting: 'Error deleting appointments',
			errorServer: 'Error communicating with server',
		},
	};
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
	document.body.innerHTML = `
		<table>
			<tbody id="ffc-working-hours-list">
				<tr>
					<td><select name="ffc_calendar_working_hours[0][day]"><option value="1" selected>Mon</option></select></td>
					<td><input type="time" name="ffc_calendar_working_hours[0][start]" value="09:00" /></td>
					<td><input type="time" name="ffc_calendar_working_hours[0][end]" value="17:00" /></td>
					<td><button type="button" class="ffc-remove-hour">Remove</button></td>
				</tr>
			</tbody>
		</table>
		<button id="ffc-add-working-hour">Add</button>
		<input type="checkbox" id="allow_cancellation" />
		<div id="cancellation-hours-row" style="display:none"></div>
		<select id="ffc_visibility">
			<option value="public" selected>Public</option>
			<option value="private">Private</option>
		</select>
		<div id="ffc-scheduling-section"></div>
	`;
	loadScript('assets/js/ffc-calendar-editor.js');
	// Document.ready defers.
	await new Promise((r) => setTimeout(r, 0));
});

beforeEach(() => {
	// Reset to baseline: one row, cancellation off.
	document.getElementById('ffc-working-hours-list').innerHTML = `
		<tr>
			<td><select name="ffc_calendar_working_hours[0][day]"><option value="1" selected>Mon</option></select></td>
			<td><input type="time" name="ffc_calendar_working_hours[0][start]" value="09:00" /></td>
			<td><input type="time" name="ffc_calendar_working_hours[0][end]" value="17:00" /></td>
			<td><button type="button" class="ffc-remove-hour">Remove</button></td>
		</tr>
	`;
});

// ----------------------------------------------------------------------
// Add working hour
// ----------------------------------------------------------------------

describe('add working hour (#ffc-add-working-hour click)', () => {
	it('appends a new <tr> to #ffc-working-hours-list', () => {
		const before = document.querySelectorAll('#ffc-working-hours-list tr').length;
		document.getElementById('ffc-add-working-hour').click();
		const after = document.querySelectorAll('#ffc-working-hours-list tr').length;
		expect(after).toBe(before + 1);
	});

	it('includes a day select with all 7 weekdays in the new row', () => {
		document.getElementById('ffc-add-working-hour').click();
		const rows = document.querySelectorAll('#ffc-working-hours-list tr');
		const newRow = rows[rows.length - 1];
		const options = newRow.querySelectorAll('select option');
		expect(options.length).toBe(7);
	});

	it("defaults the new row's start/end to 09:00 / 17:00", () => {
		document.getElementById('ffc-add-working-hour').click();
		const rows = document.querySelectorAll('#ffc-working-hours-list tr');
		const newRow = rows[rows.length - 1];
		const starts = newRow.querySelectorAll('input[type="time"]');
		expect(starts[0].value).toBe('09:00');
		expect(starts[1].value).toBe('17:00');
	});

	it('increments the row counter so consecutive adds get distinct names', () => {
		document.getElementById('ffc-add-working-hour').click();
		document.getElementById('ffc-add-working-hour').click();
		const selects = document.querySelectorAll('#ffc-working-hours-list select');
		const names = Array.from(selects).map((s) => s.name);
		expect(new Set(names).size).toBe(names.length);
	});
});

// ----------------------------------------------------------------------
// Remove working hour
// ----------------------------------------------------------------------

describe('remove working hour (.ffc-remove-hour click)', () => {
	it('confirms before removing', () => {
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
		// Add 2 rows so removal is allowed.
		document.getElementById('ffc-add-working-hour').click();
		const before = document.querySelectorAll('#ffc-working-hours-list tr').length;
		document.querySelector('.ffc-remove-hour').click();
		const after = document.querySelectorAll('#ffc-working-hours-list tr').length;
		expect(confirmSpy).toHaveBeenCalled();
		// User declined → row count unchanged.
		expect(after).toBe(before);
		confirmSpy.mockRestore();
	});

	it('blocks removal when only one row remains (with alert)', () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const alertSpy = vi.spyOn(globalThis, 'alert').mockImplementation(() => {});
		const before = document.querySelectorAll('#ffc-working-hours-list tr').length;
		expect(before).toBe(1);
		document.querySelector('.ffc-remove-hour').click();
		expect(alertSpy).toHaveBeenCalled();
		const after = document.querySelectorAll('#ffc-working-hours-list tr').length;
		expect(after).toBe(1);
	});

	it('fades out and removes the row when confirmed AND >1 rows exist', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		document.getElementById('ffc-add-working-hour').click();
		expect(document.querySelectorAll('#ffc-working-hours-list tr').length).toBe(2);
		document.querySelectorAll('.ffc-remove-hour')[0].click();
		// fadeOut + remove takes ~400ms.
		await new Promise((r) => setTimeout(r, 500));
		expect(document.querySelectorAll('#ffc-working-hours-list tr').length).toBe(1);
	});
});

// ----------------------------------------------------------------------
// toggleCancellationHours (#allow_cancellation change)
// ----------------------------------------------------------------------

describe('toggle cancellation hours (#allow_cancellation change)', () => {
	beforeEach(() => {
		window.$.fx.off = true;
		// The handler toggles elements with class .ffc-cancellation-hours.
		window.$('#cancellation-hours-row').attr('class', 'ffc-cancellation-hours').hide();
	});

	it('shows the cancellation hours when checked, hides them when unchecked', () => {
		window.$('#allow_cancellation').prop('checked', true).trigger('change');
		expect(window.$('.ffc-cancellation-hours').css('display')).not.toBe('none');
		window.$('#allow_cancellation').prop('checked', false).trigger('change');
		expect(window.$('.ffc-cancellation-hours').css('display')).toBe('none');
	});
});

// ----------------------------------------------------------------------
// toggleSchedulingVisibility (#ffc_visibility change)
// ----------------------------------------------------------------------

describe('toggle scheduling visibility (#ffc_visibility change)', () => {
	beforeEach(() => {
		window.$.fx.off = true;
		document.getElementById('ffc-scheduling-section').innerHTML = `
			<select id="ffc_scheduling_visibility">
				<option value="public" selected>Public</option>
				<option value="private">Private</option>
			</select>
			<p id="ffc-scheduling-desc"></p>
		`;
		window.$('#ffc_visibility').val('public');
	});

	it('forces scheduling to private + disabled and injects a hidden input when visibility is private', () => {
		window.$('#ffc_visibility').val('private').trigger('change');
		expect(window.$('#ffc_scheduling_visibility').val()).toBe('private');
		expect(window.$('#ffc_scheduling_visibility').prop('disabled')).toBe(true);
		// Hidden input ensures the disabled select's value is still submitted.
		expect(window.$('#ffc_scheduling_visibility').next('input[type="hidden"]').length).toBe(1);
		expect(window.$('#ffc-scheduling-desc').text()).toContain('Forced to Private');
	});

	it('does not duplicate the hidden input on a second private toggle', () => {
		window.$('#ffc_visibility').val('private').trigger('change');
		window.$('#ffc_visibility').val('private').trigger('change');
		expect(window.$('#ffc_scheduling_visibility').next('input[type="hidden"]').length).toBe(1);
	});

	it('re-enables scheduling and removes the hidden input when visibility becomes public', () => {
		window.$('#ffc_visibility').val('private').trigger('change');
		window.$('#ffc_visibility').val('public').trigger('change');
		expect(window.$('#ffc_scheduling_visibility').prop('disabled')).toBe(false);
		expect(window.$('#ffc_scheduling_visibility').next('input[type="hidden"]').length).toBe(0);
		expect(window.$('#ffc-scheduling-desc').text()).toContain('Public or private');
	});
});

// ----------------------------------------------------------------------
// initCleanup (.ffc-cleanup-btn click)
// ----------------------------------------------------------------------

describe('appointment cleanup (.ffc-cleanup-btn click)', () => {
	beforeEach(() => {
		window.$.fx.off = true;
		document.getElementById('ffc-scheduling-section').innerHTML = `
			<button class="ffc-cleanup-btn" data-action="old" data-calendar-id="7">Clean old</button>
			<button class="ffc-cleanup-btn" data-action="all" data-calendar-id="7">Clean all</button>
			<input type="hidden" id="ffc_cleanup_appointments_nonce" value="cleanup-nonce" />
		`;
	});

	it('bails when the confirm is declined (no AJAX)', () => {
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const reqSpy = vi.spyOn(window.FFC, 'request');
		window.$('.ffc-cleanup-btn[data-action="old"]').trigger('click');
		expect(reqSpy).not.toHaveBeenCalled();
	});

	it('uses the "all" confirm message for the delete-all button', () => {
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
		window.$('.ffc-cleanup-btn[data-action="all"]').trigger('click');
		expect(confirmSpy).toHaveBeenCalledWith('Delete ALL appointments?');
	});

	it('disables the button and POSTs the cleanup request when confirmed', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const reqSpy = vi.spyOn(window.FFC, 'request').mockReturnValue(new Promise(() => {}));
		const $btn = window.$('.ffc-cleanup-btn[data-action="old"]');
		$btn.trigger('click');
		expect(reqSpy).toHaveBeenCalled();
		expect(reqSpy.mock.calls[0][0]).toBe('ffc_cleanup_appointments');
		expect(reqSpy.mock.calls[0][1]).toMatchObject({ calendar_id: 7, cleanup_action: 'old' });
		expect($btn.prop('disabled')).toBe(true);
		expect($btn.text()).toBe('Deleting…');
	});

	it('alerts the server message and re-enables the button on a fromServer error', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.FFC, 'request').mockRejectedValue({ fromServer: true, message: 'Boom' });
		const $btn = window.$('.ffc-cleanup-btn[data-action="old"]');
		$btn.trigger('click');
		await Promise.resolve().then(() => Promise.resolve());
		expect(alertSpy).toHaveBeenCalledWith('Boom');
		expect($btn.prop('disabled')).toBe(false);
	});

	it('alerts the returned message and reloads on a successful cleanup', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		// jsdom does not implement navigation; stub reload so the success
		// branch can run without throwing.
		const reloadSpy = vi.fn();
		Object.defineProperty(window, 'location', {
			configurable: true,
			value: { ...window.location, reload: reloadSpy },
		});
		vi.spyOn(window.FFC, 'request').mockResolvedValue({ message: 'Done' });
		window.$('.ffc-cleanup-btn[data-action="old"]').trigger('click');
		await Promise.resolve().then(() => Promise.resolve());
		expect(alertSpy).toHaveBeenCalledWith('Done');
		expect(reloadSpy).toHaveBeenCalled();
	});

	it('alerts the generic server-communication error on a network failure', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.FFC, 'request').mockRejectedValue(new Error('net'));
		window.$('.ffc-cleanup-btn[data-action="old"]').trigger('click');
		await Promise.resolve().then(() => Promise.resolve());
		expect(alertSpy).toHaveBeenCalledWith('Error communicating with server');
	});
});
