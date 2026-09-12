// Tests for assets/js/ffc-working-hours.js (99 LOC) — shared component
// powering the working_hours custom field on both the public rereg form
// and the admin user-profile page.
//
// The IIFE registers three delegated handlers (.ffc-wh-add /
// .ffc-wh-remove / change on row inputs) and one row-build helper. All
// state lives in the DOM + a hidden JSON input synced after every
// mutation.
//
// Sprint A of the JS coverage roadmap — closes the 0% blind spot.
import { describe, it, expect, beforeEach, beforeAll } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-working-hours.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcWorkingHours = undefined;
});

function mountWithRows(rows) {
	const tbody = rows
		.map(
			(r) => `
				<tr>
					<td><select class="ffc-wh-day">
						<option value="0">Sun</option>
						<option value="1"${r.day === 1 ? ' selected' : ''}>Mon</option>
						<option value="2"${r.day === 2 ? ' selected' : ''}>Tue</option>
					</select></td>
					<td><input type="time" class="ffc-wh-entry1" value="${r.entry1 || ''}"></td>
					<td><input type="time" class="ffc-wh-exit1" value="${r.exit1 || ''}"></td>
					<td><input type="time" class="ffc-wh-entry2" value="${r.entry2 || ''}"></td>
					<td><input type="time" class="ffc-wh-exit2" value="${r.exit2 || ''}"></td>
					<td><button type="button" class="ffc-wh-remove">x</button></td>
				</tr>`,
		)
		.join('');
	document.body.innerHTML = `
		<input type="hidden" id="my-target" name="working_hours" value="">
		<div class="ffc-working-hours" data-target="my-target">
			<table class="ffc-wh-table"><tbody>${tbody}</tbody></table>
			<button class="ffc-wh-add">+ Add</button>
		</div>
	`;
}

describe('ffc-working-hours — add row', () => {
	it('appends a default Monday row and syncs the hidden input', () => {
		mountWithRows([]);
		window.$('.ffc-wh-add').trigger('click');

		const $rows = window.$('.ffc-wh-table tbody tr');
		expect($rows.length).toBe(1);
		const hidden = JSON.parse(window.$('#my-target').val());
		expect(hidden).toHaveLength(1);
		expect(hidden[0]).toMatchObject({
			day: 1,
			entry1: '08:00',
			exit1: '12:00',
			entry2: '13:00',
			exit2: '17:00',
		});
	});

	it('uses the translated DAYS list when window.ffcWorkingHours.days is set', () => {
		// Reload the IIFE with custom labels to confirm the localised path
		// runs. We accept that the prior IIFE's delegated handler is still
		// bound and will also fire on the click (so the click yields 2 rows
		// — one English, one translated); the assertion only checks that
		// the translated labels appear somewhere in the rendered options.
		window.ffcWorkingHours = {
			days: [
				{ value: 0, label: 'Dom' },
				{ value: 1, label: 'Seg' },
			],
		};
		loadScript('assets/js/ffc-working-hours.js');
		mountWithRows([]);
		window.$('.ffc-wh-add').trigger('click');

		const optionLabels = window
			.$('.ffc-wh-table tbody tr .ffc-wh-day option')
			.map((_, el) => el.textContent)
			.get();
		expect(optionLabels).toContain('Dom');
		expect(optionLabels).toContain('Seg');
	});
});

describe('ffc-working-hours — remove row', () => {
	it('removes the clicked row and re-syncs hidden JSON', () => {
		mountWithRows([
			{ day: 1, entry1: '08:00', exit1: '12:00', entry2: '13:00', exit2: '17:00' },
			{ day: 2, entry1: '09:00', exit1: '', entry2: '', exit2: '18:00' },
		]);
		// Seed the hidden by triggering a change first.
		window.$('.ffc-wh-entry1').first().trigger('change');
		expect(JSON.parse(window.$('#my-target').val())).toHaveLength(2);

		window.$('.ffc-wh-remove').first().trigger('click');

		expect(window.$('.ffc-wh-table tbody tr').length).toBe(1);
		const hidden = JSON.parse(window.$('#my-target').val());
		expect(hidden).toHaveLength(1);
		expect(hidden[0].day).toBe(2);
	});
});

describe('ffc-working-hours — sync on change', () => {
	it('writes a JSON snapshot to the hidden target by id', () => {
		mountWithRows([
			{ day: 2, entry1: '09:00', exit1: '12:00', entry2: '13:00', exit2: '18:00' },
		]);
		window.$('.ffc-wh-exit2').val('19:00').trigger('change');

		const hidden = JSON.parse(window.$('#my-target').val());
		expect(hidden).toEqual([
			{
				day: 2,
				entry1: '09:00',
				exit1: '12:00',
				entry2: '13:00',
				exit2: '19:00',
			},
		]);
	});

	it('falls back to [name="…"] when the data-target id has no element', () => {
		// The wrapper's data-target points at a name attribute, not an id.
		document.body.innerHTML = `
			<input type="hidden" name="wh-by-name" value="">
			<div class="ffc-working-hours" data-target="wh-by-name">
				<table class="ffc-wh-table"><tbody>
					<tr>
						<td><select class="ffc-wh-day"><option value="3" selected>Wed</option></select></td>
						<td><input type="time" class="ffc-wh-entry1" value="07:30"></td>
						<td><input type="time" class="ffc-wh-exit1" value=""></td>
						<td><input type="time" class="ffc-wh-entry2" value=""></td>
						<td><input type="time" class="ffc-wh-exit2" value="16:30"></td>
						<td><button class="ffc-wh-remove">x</button></td>
					</tr>
				</tbody></table>
				<button class="ffc-wh-add">+</button>
			</div>
		`;
		window.$('.ffc-wh-day').trigger('change');

		const hidden = JSON.parse(window.$('[name="wh-by-name"]').val());
		expect(hidden).toEqual([
			{
				day: 3,
				entry1: '07:30',
				exit1: '',
				entry2: '',
				exit2: '16:30',
			},
		]);
	});

	it('emits empty strings for empty time inputs', () => {
		mountWithRows([
			{ day: 1, entry1: '08:00', exit1: '', entry2: '', exit2: '17:00' },
		]);
		window.$('.ffc-wh-entry1').trigger('change');

		const hidden = JSON.parse(window.$('#my-target').val());
		expect(hidden[0].exit1).toBe('');
		expect(hidden[0].entry2).toBe('');
	});
});

// ---------------------------------------------------------------------------
// #1128 — the submit guard on a half-filled row
// ---------------------------------------------------------------------------
//
// The rule: the first shift's entry and the last shift's exit are required,
// the middle is optional. A row with NO time at all is not a mistake — it is
// "I do not work this day" — so only a half-filled row blocks.
//
// This runs BEFORE the POST on purpose. Once the form submits, the profile
// screen reloads from the database and whatever the operator typed is gone, so
// "signal and let them fix it" is only meaningful here; the server-side
// sanitizer stays as the backstop for a direct POST.

function mountInForm(rows, wrap) {
	mountWithRows(rows);
	const inner = document.body.innerHTML;
	document.body.innerHTML = wrap
		? `<form id="f"><div id="sec" class="ffc-cf-section-body is-collapsed">${inner}</div></form>`
		: `<form id="f">${inner}</form>`;
}

function submit() {
	const ev = window.$.Event('submit');
	window.$('#f').trigger(ev);
	return ev;
}

describe('ffc-working-hours — submit guard (#1128)', () => {
	it('lets a complete row through', () => {
		mountInForm([{ day: 1, entry1: '08:00', exit1: '', entry2: '', exit2: '17:00' }]);
		expect(submit().isDefaultPrevented()).toBe(false);
		expect(window.$('.ffc-wh-row-error').length).toBe(0);
	});

	it('lets a row with no times at all through — that is "I do not work this day"', () => {
		mountInForm([{ day: 1, entry1: '', exit1: '', entry2: '', exit2: '' }]);
		expect(submit().isDefaultPrevented()).toBe(false);
		expect(window.$('.ffc-wh-row-error').length).toBe(0);
	});

	it('blocks a row missing the first entry', () => {
		mountInForm([{ day: 1, entry1: '', exit1: '', entry2: '', exit2: '17:00' }]);
		expect(submit().isDefaultPrevented()).toBe(true);
	});

	it('blocks a row missing the last exit', () => {
		mountInForm([{ day: 1, entry1: '08:00', exit1: '', entry2: '', exit2: '' }]);
		expect(submit().isDefaultPrevented()).toBe(true);
	});

	it('blocks a row carrying only the optional middle shift', () => {
		mountInForm([{ day: 1, entry1: '', exit1: '12:00', entry2: '', exit2: '' }]);
		expect(submit().isDefaultPrevented()).toBe(true);
	});

	it('names what is missing on the offending row', () => {
		mountInForm([{ day: 1, entry1: '', exit1: '', entry2: '', exit2: '17:00' }]);
		submit();

		const $err = window.$('.ffc-wh-row-error');
		expect($err.length).toBe(1);
		expect($err.text()).toContain('Entry 1');
		expect($err.text()).not.toContain('Exit 2');
	});

	it('marks the offending row and leaves the valid one alone', () => {
		mountInForm([
			{ day: 1, entry1: '08:00', exit1: '', entry2: '', exit2: '17:00' },
			{ day: 2, entry1: '', exit1: '', entry2: '', exit2: '17:00' },
		]);
		submit();

		const $rows = window.$('.ffc-wh-table tbody tr:not(.ffc-wh-row-error)');
		expect($rows.eq(0).hasClass('ffc-wh-row-invalid')).toBe(false);
		expect($rows.eq(1).hasClass('ffc-wh-row-invalid')).toBe(true);
	});

	it('clears the previous message on the next submit', () => {
		mountInForm([{ day: 1, entry1: '', exit1: '', entry2: '', exit2: '17:00' }]);
		submit();
		expect(window.$('.ffc-wh-row-error').length).toBe(1);

		window.$('.ffc-wh-entry1').val('08:00');
		expect(submit().isDefaultPrevented()).toBe(false);
		expect(window.$('.ffc-wh-row-error').length).toBe(0);
		expect(window.$('.ffc-wh-row-invalid').length).toBe(0);
	});

	// The defect #1117 set out to remove: refusing a submit while pointing at a
	// control nobody can see. The guard has to open the section first.
	it('expands a collapsed section before pointing at the row', () => {
		mountInForm([{ day: 1, entry1: '', exit1: '', entry2: '', exit2: '17:00' }], true);
		expect(window.$('#sec').hasClass('is-collapsed')).toBe(true);

		submit();

		expect(window.$('#sec').hasClass('is-collapsed')).toBe(false);
	});

	it('uses the localized strings when supplied', () => {
		window.ffcWorkingHours = { strings: { entry1: 'Entrada 1', exit2: 'Saída 2', incomplete: 'Falta: %s' } };
		loadScript('assets/js/ffc-working-hours.js');

		mountInForm([{ day: 1, entry1: '', exit1: '', entry2: '', exit2: '17:00' }]);
		submit();

		expect(window.$('.ffc-wh-row-error').text()).toBe('Falta: Entrada 1');
	});
});
