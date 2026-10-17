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
			deleting: 'Deleting…',
			cancelling: 'Cancelling…',
			cleanup: {
				cancel: 'Cancel',
				goBack: 'Go back',
				old: {
					title: 'Delete past appointments',
					body: '%1$d past appointment(s) of the calendar "%2$s" will be deleted for good.',
					confirm: 'Delete %d appointment(s)',
				},
				future: {
					title: 'Cancel future appointments',
					body: '%1$d appointment(s) in "%2$s" will be cancelled and notified.',
					bodySilent: '%1$d appointment(s) in "%2$s" will be cancelled; nobody notified.',
					confirm: 'Cancel %d and notify',
					confirmSilent: 'Cancel %d appointment(s)',
					type: 'To confirm, type %d',
				},
				all: {
					title: 'Delete all appointments',
					body: 'All %1$d appointment(s) of "%2$s" will be deleted.',
					confirm: 'Delete %d appointment(s)',
					type: 'To confirm, type %d',
				},
			},
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
					<td><select name="ffc_self_scheduling_working_hours[0][day]"><option value="1" selected>Mon</option></select></td>
					<td><input type="time" name="ffc_self_scheduling_working_hours[0][start]" value="09:00" /></td>
					<td><input type="time" name="ffc_self_scheduling_working_hours[0][end]" value="17:00" /></td>
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
			<td><select name="ffc_self_scheduling_working_hours[0][day]"><option value="1" selected>Mon</option></select></td>
			<td><input type="time" name="ffc_self_scheduling_working_hours[0][start]" value="09:00" /></td>
			<td><input type="time" name="ffc_self_scheduling_working_hours[0][end]" value="17:00" /></td>
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

	it('names the new row with the key the save handler reads (#1629)', () => {
		// The rows were once named `ffc_calendar_working_hours[…]`, a key
		// `SelfSchedulingSaveHandler::save_working_hours()` never reads, so
		// every row added here was dropped on save.
		document.getElementById('ffc-add-working-hour').click();
		const rows = document.querySelectorAll('#ffc-working-hours-list tr');
		const names = Array.from(rows[rows.length - 1].querySelectorAll('[name]')).map((el) => el.getAttribute('name'));
		expect(names).toHaveLength(3);
		names.forEach((name) => expect(name).toMatch(/^ffc_self_scheduling_working_hours\[\d+\]\[(day|start|end)\]$/));
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
// toggleWaitlistCapacity (#waitlist_enabled change) — #941 phase 2
// ----------------------------------------------------------------------

describe('toggle waitlist capacity (#waitlist_enabled change)', () => {
	beforeEach(() => {
		window.$.fx.off = true;
		// The handler (delegated on document) toggles .ffc-waitlist-capacity.
		window.$('body').append(
			'<input type="checkbox" id="waitlist_enabled" />' +
			'<div class="ffc-waitlist-capacity" style="display:none"></div>'
		);
	});

	it('shows the capacity row when checked, hides it when unchecked', () => {
		window.$('#waitlist_enabled').prop('checked', true).trigger('change');
		expect(window.$('.ffc-waitlist-capacity').css('display')).not.toBe('none');
		window.$('#waitlist_enabled').prop('checked', false).trigger('change');
		expect(window.$('.ffc-waitlist-capacity').css('display')).toBe('none');
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
// initCleanup (.ffc-cleanup-btn click) and its dialog (#1872)
// ----------------------------------------------------------------------

describe('appointment cleanup (.ffc-cleanup-btn click)', () => {
	const dialog = () => document.querySelector('.ffc-cleanup-dialog');
	const part = (name) => dialog().querySelector('[data-ffc-cleanup="' + name + '"]');
	const typeInto = (value) => {
		const input = part('typed');
		input.value = value;
		input.dispatchEvent(new window.Event('input', { bubbles: true }));
	};
	const key = (k, extra) => {
		document.dispatchEvent(new window.KeyboardEvent('keydown', Object.assign({ key: k, bubbles: true, cancelable: true }, extra || {})));
	};
	const flush = () => Promise.resolve().then(() => Promise.resolve());

	beforeEach(() => {
		window.$.fx.off = true;
		document.querySelectorAll('.ffc-cleanup-dialog').forEach((d) => d.remove());
		document.getElementById('ffc-scheduling-section').innerHTML = `
			<div class="ffc-cleanup-appointments ffc-cleanup-danger" data-calendar-title="DRE Centro">
				<button class="ffc-cleanup-btn" data-action="old" data-count="29" data-calendar-id="7">Clean old</button>
				<button class="ffc-cleanup-btn" data-action="future" data-count="12" data-notify="1" data-calendar-id="7">Cancel future</button>
				<button class="ffc-cleanup-btn" data-action="all" data-count="48" data-calendar-id="7">Clean all</button>
			</div>
			<input type="hidden" id="ffc_cleanup_appointments_nonce" value="cleanup-nonce" />
		`;
	});

	it('opens the plugin dialog instead of the browser confirm()', () => {
		const confirmSpy = vi.spyOn(window, 'confirm');
		window.$('.ffc-cleanup-btn[data-action="old"]').trigger('click');
		expect(confirmSpy).not.toHaveBeenCalled();
		expect(dialog()).not.toBeNull();
		expect(dialog().getAttribute('role')).toBe('alertdialog');
		expect(dialog().getAttribute('aria-modal')).toBe('true');
		expect(dialog().textContent).toContain('29 past appointment(s) of the calendar "DRE Centro" will be deleted for good.');
		part('cancel').click();
	});

	it('confirms "past" without typing: the button is live and focus starts on Cancel', async () => {
		const reqSpy = vi.spyOn(window.FFC, 'request').mockReturnValue(new Promise(() => {}));
		const $btn = window.$('.ffc-cleanup-btn[data-action="old"]');
		$btn.trigger('click');
		expect(part('typed')).toBeNull();
		expect(part('confirm').disabled).toBe(false);
		expect(document.activeElement).toBe(part('cancel'));
		expect(part('confirm').textContent).toBe('Delete 29 appointment(s)');

		part('confirm').click();
		await flush();
		expect(dialog()).toBeNull();
		expect(reqSpy).toHaveBeenCalledTimes(1);
		expect(reqSpy.mock.calls[0][0]).toBe('ffc_cleanup_appointments');
		expect(reqSpy.mock.calls[0][1]).toMatchObject({ calendar_id: 7, cleanup_action: 'old', expected: 29 });
		expect(reqSpy.mock.calls[0][2]).toEqual({ nonce: 'cleanup-nonce' });
		expect($btn.prop('disabled')).toBe(true);
		expect($btn.text()).toBe('Deleting…');
	});

	it('gates "delete all" on typing the exact count', async () => {
		const reqSpy = vi.spyOn(window.FFC, 'request').mockReturnValue(new Promise(() => {}));
		window.$('.ffc-cleanup-btn[data-action="all"]').trigger('click');
		const confirmBtn = part('confirm');
		expect(document.activeElement).toBe(part('typed'));
		expect(dialog().textContent).toContain('To confirm, type 48');
		expect(confirmBtn.disabled).toBe(true);
		expect(confirmBtn.getAttribute('aria-disabled')).toBe('true');

		confirmBtn.click();
		await flush();
		expect(reqSpy).not.toHaveBeenCalled();

		typeInto('4');
		expect(confirmBtn.disabled).toBe(true);
		typeInto('480');
		expect(confirmBtn.disabled).toBe(true);
		typeInto(' 48 ');
		expect(confirmBtn.disabled).toBe(false);

		confirmBtn.click();
		await flush();
		expect(reqSpy.mock.calls[0][1]).toMatchObject({ cleanup_action: 'all', expected: 48 });
	});

	it('gates "cancel future" on the typed count, and Enter confirms once it matches', async () => {
		const reqSpy = vi.spyOn(window.FFC, 'request').mockReturnValue(new Promise(() => {}));
		const $btn = window.$('.ffc-cleanup-btn[data-action="future"]');
		$btn.trigger('click');
		expect(dialog().classList.contains('ffc-cleanup-dialog-caution')).toBe(true);
		expect(part('cancel').textContent).toBe('Go back');
		expect(part('confirm').textContent).toBe('Cancel 12 and notify');
		expect(part('confirm').disabled).toBe(true);

		part('typed').focus();
		key('Enter');
		await flush();
		expect(reqSpy).not.toHaveBeenCalled();
		expect(dialog()).not.toBeNull();

		typeInto('12');
		part('typed').focus();
		key('Enter');
		await flush();
		expect(dialog()).toBeNull();
		expect(reqSpy.mock.calls[0][1]).toMatchObject({ cleanup_action: 'future', expected: 12 });
		expect($btn.text()).toBe('Cancelling…');
	});

	it('does not promise a notice the calendar will not send', () => {
		window.$('.ffc-cleanup-btn[data-action="future"]').attr('data-notify', '0').removeData('notify').trigger('click');
		expect(dialog().textContent).toContain('nobody notified');
		expect(part('confirm').textContent).toBe('Cancel 12 appointment(s)');
		part('cancel').click();
	});

	it('Escape cancels, sends nothing and returns focus to the button', async () => {
		const reqSpy = vi.spyOn(window.FFC, 'request');
		const btn = document.querySelector('.ffc-cleanup-btn[data-action="all"]');
		btn.focus();
		window.$(btn).trigger('click');
		key('Escape');
		await flush();
		expect(dialog()).toBeNull();
		expect(reqSpy).not.toHaveBeenCalled();
		expect(document.activeElement).toBe(btn);
	});

	it('the backdrop and Cancel answer no', async () => {
		const reqSpy = vi.spyOn(window.FFC, 'request');
		window.$('.ffc-cleanup-btn[data-action="old"]').trigger('click');
		dialog().querySelector('.ffc-modal-backdrop').click();
		await flush();
		expect(dialog()).toBeNull();
		window.$('.ffc-cleanup-btn[data-action="old"]').trigger('click');
		part('cancel').click();
		await flush();
		expect(dialog()).toBeNull();
		expect(reqSpy).not.toHaveBeenCalled();
	});

	it('keeps Tab inside the dialog, skipping the disabled button', () => {
		window.$('.ffc-cleanup-btn[data-action="all"]').trigger('click');
		const input = part('typed');
		const cancel = part('cancel');
		cancel.focus();
		key('Tab');
		expect(document.activeElement).toBe(input);
		input.focus();
		key('Tab', { shiftKey: true });
		expect(document.activeElement).toBe(cancel);

		typeInto('48');
		part('confirm').focus();
		key('Tab');
		expect(document.activeElement).toBe(input);
		key('Escape');
	});

	it('alerts the server message and re-enables the button on a fromServer error', async () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.FFC, 'request').mockRejectedValue({ fromServer: true, message: 'Boom' });
		const $btn = window.$('.ffc-cleanup-btn[data-action="old"]');
		$btn.trigger('click');
		part('confirm').click();
		await flush();
		await flush();
		expect(alertSpy).toHaveBeenCalledWith('Boom');
		expect($btn.prop('disabled')).toBe(false);
	});

	it('alerts the returned message and reloads on a successful cleanup', async () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		const reloadSpy = vi.fn();
		Object.defineProperty(window, 'location', {
			configurable: true,
			value: { ...window.location, reload: reloadSpy },
		});
		vi.spyOn(window.FFC, 'request').mockResolvedValue({ message: 'Done' });
		window.$('.ffc-cleanup-btn[data-action="old"]').trigger('click');
		part('confirm').click();
		await flush();
		await flush();
		expect(alertSpy).toHaveBeenCalledWith('Done');
		expect(reloadSpy).toHaveBeenCalled();
	});

	it('alerts the generic server-communication error on a network failure', async () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.FFC, 'request').mockRejectedValue(new Error('net'));
		window.$('.ffc-cleanup-btn[data-action="old"]').trigger('click');
		part('confirm').click();
		await flush();
		await flush();
		expect(alertSpy).toHaveBeenCalledWith('Error communicating with server');
	});
});

// ----------------------------------------------------------------------
// Custom scheduling mode (#941)
// ----------------------------------------------------------------------

describe('custom scheduling mode (#941)', () => {
	beforeEach(() => {
		window.$.fx.off = true;
		document.getElementById('ffc-scheduling-section').innerHTML = `
			<div class="ffc-regular-only" id="reg-section">regular</div>
			<div class="ffc-custom-only" id="cust-section">
				<table><tbody id="ffc-custom-slots-list"></tbody></table>
				<button id="ffc-add-custom-slot">Add Block</button>
			</div>
			<label><input type="radio" class="ffc-schedule-type-radio" name="m" value="regular" checked /></label>
			<label><input type="radio" class="ffc-schedule-type-radio" name="m" value="custom" /></label>
		`;
	});

	it('shows regular and hides custom when the regular radio is active', () => {
		window.$('.ffc-schedule-type-radio[value="regular"]').prop('checked', true).trigger('change');
		expect(window.$('#reg-section').css('display')).not.toBe('none');
		expect(window.$('#cust-section').css('display')).toBe('none');
	});

	it('shows custom and hides regular when the custom radio is selected', () => {
		window.$('.ffc-schedule-type-radio[value="custom"]').prop('checked', true).trigger('change');
		expect(window.$('#cust-section').css('display')).not.toBe('none');
		expect(window.$('#reg-section').css('display')).toBe('none');
	});

	it('appends a block row with the custom_slots field names', () => {
		const before = document.querySelectorAll('#ffc-custom-slots-list tr').length;
		document.getElementById('ffc-add-custom-slot').click();
		const rows = document.querySelectorAll('#ffc-custom-slots-list tr');
		expect(rows.length).toBe(before + 1);
		const dateInput = rows[rows.length - 1].querySelector('input[type="date"]');
		expect(dateInput.name).toMatch(/ffc_self_scheduling_custom_slots\[\d+\]\[date\]/);
		// capacity defaults to 1
		expect(rows[rows.length - 1].querySelector('input[type="number"]').value).toBe('1');
	});

	it('removes a block row on the remove button', async () => {
		document.getElementById('ffc-add-custom-slot').click();
		document.getElementById('ffc-add-custom-slot').click();
		expect(document.querySelectorAll('#ffc-custom-slots-list tr').length).toBe(2);
		document.querySelector('.ffc-remove-slot').click();
		await new Promise((r) => setTimeout(r, 300));
		expect(document.querySelectorAll('#ffc-custom-slots-list tr').length).toBe(1);
	});
});

// ----------------------------------------------------------------------
// `required` follows visibility (#1117)
// ----------------------------------------------------------------------

describe('required follows the blocks the editor shows and hides', () => {
	// A `required` field inside a `display:none` block still blocks the
	// submit, and the browser reports it against a control nobody can see.
	// Four blocks here are JS-toggled, so the attribute has to travel with
	// them.
	function mountModes() {
		document.body.innerHTML = `
			<form id="post">
				<label><input type="radio" name="mode" value="regular" class="ffc-schedule-type-radio" checked></label>
				<label><input type="radio" name="mode" value="custom" class="ffc-schedule-type-radio"></label>

				<div id="ffc-working-hours-wrapper" class="ffc-regular-only">
					<table><tbody id="ffc-working-hours-list">
						<tr><td><input type="time" name="wh[0][start]" value="09:00" required /></td></tr>
					</tbody></table>
				</div>

				<div class="ffc-custom-only">
					<table><tbody id="ffc-custom-slots-list"></tbody></table>
					<input type="number" id="max_blocks_per_user" value="0" min="0" required>
				</div>

				<input type="checkbox" id="allow_cancellation">
				<input type="checkbox" id="waitlist_enabled">

				<table><tbody>
					<tr class="ffc-regular-only"><td><input type="number" id="slot_duration" value="30" min="5" required></td></tr>
					<tr class="ffc-cancellation-hours" style="display:none"><td><input type="number" id="cancellation_min_hours" value="24" min="0" required></td></tr>
					<tr class="ffc-waitlist-capacity" style="display:none"><td><input type="number" id="waitlist_capacity" value="0" min="0" required></td></tr>
				</tbody></table>
			</form>
		`;
	}

	function req(id) {
		return document.getElementById(id).required;
	}

	it('drops required from the regular block when custom mode is picked, and restores it', () => {
		mountModes();
		window.$('.ffc-schedule-type-radio[value="custom"]').prop('checked', true).trigger('change');

		expect(req('slot_duration')).toBe(false);
		expect(req('max_blocks_per_user')).toBe(true);

		window.$('.ffc-schedule-type-radio[value="regular"]').prop('checked', true).trigger('change');
		expect(req('slot_duration')).toBe(true);
		expect(req('max_blocks_per_user')).toBe(false);
	});

	it('covers the working-hours rows, whose required predates #1117', () => {
		mountModes();
		const start = () => document.querySelector('#ffc-working-hours-list input[type="time"]').required;
		expect(start()).toBe(true);

		// This is the latent bug the sync closes: a stored row with an empty
		// time jammed the save of any calendar switched to custom mode.
		window.$('.ffc-schedule-type-radio[value="custom"]').prop('checked', true).trigger('change');
		expect(start()).toBe(false);

		window.$('.ffc-schedule-type-radio[value="regular"]').prop('checked', true).trigger('change');
		expect(start()).toBe(true);
	});

	it('follows the cancellation and waitlist toggles', () => {
		mountModes();
		// Both render hidden, so the toggle handler is what enables them.
		window.$('#allow_cancellation').prop('checked', true).trigger('change');
		expect(req('cancellation_min_hours')).toBe(true);
		expect(req('waitlist_capacity')).toBe(false);

		window.$('#waitlist_enabled').prop('checked', true).trigger('change');
		expect(req('waitlist_capacity')).toBe(true);

		window.$('#allow_cancellation').prop('checked', false).trigger('change');
		expect(req('cancellation_min_hours')).toBe(false);
		expect(req('waitlist_capacity')).toBe(true);
	});

	it('marks a newly added custom block capacity as required', () => {
		mountModes();
		document.body.insertAdjacentHTML('beforeend', '<button id="ffc-add-custom-slot">Add</button>');
		window.$('.ffc-schedule-type-radio[value="custom"]').prop('checked', true).trigger('change');

		document.getElementById('ffc-add-custom-slot').click();
		const capacity = document.querySelector('#ffc-custom-slots-list input[type="number"]');
		expect(capacity.required).toBe(true);
	});
});
