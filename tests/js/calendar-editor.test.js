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
		},
	};
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
