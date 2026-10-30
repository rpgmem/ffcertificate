// The scheduling rules on the client (#1859): the "All" label of the space
// filter, the closed-day rendering of the all-spaces view, the booking
// overlay (only open spaces for most people, every space marked for a bypass
// holder), the shared override warning with its 15-second countdown, and the
// resubmission with the confirmation flag — for audience calendars and for
// personal calendars.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

let api;

beforeAll(() => {
	window.ffcAudience = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		restUrl: '/wp-json/ffc/v1/audience/',
		nonce: 'n',
		locale: 'en-US',
		strings: {
			all: 'All',
			allOf: 'All %s',
			allEnvironments: 'All Environments',
			createBooking: 'Create booking',
			loading: 'Loading…',
			error: 'Error',
			bookingCreated: 'Booking created.',
			holiday: 'Holiday',
			closed: 'Closed',
			reasonClosed: 'Closed',
			reasonHoliday: 'Holiday',
			reasonOutsideHours: 'Outside hours',
			reasonPast: 'Past date',
			reasonBeyondWindow: 'Beyond the booking window',
			noOpenEnvironments: 'No space is open on this date.',
			descriptionRequired: 'Description must be 15–300 chars.',
			selectAudience: 'Select an audience.',
			override: { seconds: 15, title: 'Book outside the rules?', agree: 'I agree', agreeIn: 'I agree (%d)', cancel: 'Cancel' },
			months: ['January', 'February', 'March', 'April', 'May', 'June',
				'July', 'August', 'September', 'October', 'November', 'December'],
		},
	};
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-audience.js');
	loadScript('assets/js/ffc-audience-calendar.js');
	loadScript('assets/js/ffc-audience-booking-form.js');
	api = window.FFCAudience;
});

const OPEN = { start: '08:00', end: '18:00' };

// Two spaces of calendar 1. Both closed on Sunday; space 10 also closed on
// Saturday, space 11 opens on Saturday.
function availability() {
	return [
		{ id: 10, schedule_id: 1, closed_weekdays: [0, 6], hours: { 0: null, 1: OPEN, 2: OPEN, 3: OPEN, 4: OPEN, 5: OPEN, 6: null } },
		{ id: 11, schedule_id: 1, closed_weekdays: [0], hours: { 0: null, 1: OPEN, 2: OPEN, 3: OPEN, 4: OPEN, 5: OPEN, 6: OPEN } },
	];
}

function config(canBypass) {
	return {
		audiences: [],
		schedules: [{
			id: 1, name: 'Rooms', environmentLabel: 'Room', environmentLabelPlural: 'Rooms', futureDaysLimit: null,
			canBypass: !!canBypass,
			environments: [{ id: 10, name: 'Hall' }, { id: 11, name: 'Lab' }],
		}],
	};
}

function ymd(date) {
	return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
}

// The next given weekday, at least 7 days from now (never past).
function nextWeekday(weekday) {
	const d = new Date();
	d.setHours(0, 0, 0, 0);
	d.setDate(d.getDate() + 7);
	while (d.getDay() !== weekday) {
		d.setDate(d.getDate() + 1);
	}
	return d;
}

function flush() {
	return Promise.resolve().then(() => Promise.resolve()).then(() => Promise.resolve());
}

function fixture() {
	document.body.innerHTML = `
		<span class="ffc-current-month"></span>
		<select id="ffc-environment-select"><option value="">placeholder</option></select>
		<div id="ffc-calendar-days"></div>
		<div id="ffc-booking-modal" style="display:none">
			<button class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-cross ffc-modal-close" aria-label="Close"></button>
			<form id="ffc-booking-form">
				<input type="checkbox" id="booking-all-day" />
				<div id="booking-time-row"></div>
				<input id="booking-start-time" value="09:00" />
				<input id="booking-end-time" value="10:00" />
				<input id="booking-date" />
				<span class="ffc-booking-date-display"></span>
				<label for="booking-environment-id">Room</label>
				<select id="booking-environment-id"></select>
				<input type="radio" name="booking_type" value="audience" checked>
				<div id="booking-audiences"><label class="ffc-audience-picker-option"><input type="checkbox" value="3"><span class="ffc-audience-picker-name">G</span></label></div>
				<input id="booking-user-search" />
				<div id="booking-selected-users"></div>
				<input id="booking-user-ids" />
				<textarea id="booking-description">A valid description here</textarea>
				<span id="desc-char-count"></span>
				<div id="ffc-conflict-warning"></div>
				<div id="ffc-conflict-error"></div>
			</form>
			<button id="ffc-booking-submit" data-step="create">Create</button>
		</div>
	`;
}

beforeEach(() => {
	window.$.fx.off = true;
	fixture();
	api.state.selectedSchedule = 1;
	api.state.selectedEnvironment = 0;
	api.state.bookings = {};
	api.state.holidays = {};
	api.state.closedWeekdays = [];
	api.state.availability = null;
	api.state.scheduleHolidays = {};
	api.state.globalHolidays = [];
	api.state.selectedUsers = {};
	api.closeModals = vi.fn();
});

afterEach(() => {
	vi.useRealTimers();
	vi.restoreAllMocks();
	document.querySelectorAll('.ffc-override-dialog').forEach((el) => el.remove());
	document.body.innerHTML = '';
});

describe('the space filter label', () => {
	it('reads "All" before the calendar\'s plural label', () => {
		api.state.config = config(false);
		api.updateEnvironmentSelect();
		expect(window.$('#ffc-environment-select option').first().text()).toBe('All Rooms');
	});

	it('follows the word order of the translation', () => {
		api.state.config = config(false);
		const saved = window.ffcAudience.strings.allOf;
		window.ffcAudience.strings.allOf = 'Todas as %s';
		api.updateEnvironmentSelect();
		expect(window.$('#ffc-environment-select option').first().text()).toBe('Todas as Rooms');
		window.ffcAudience.strings.allOf = saved;
	});
});

describe('the all-spaces month view', () => {
	// Renders the month of `target` (default: the next Sunday a week out).
	async function render(canBypass, extra, target) {
		api.state.config = config(canBypass);
		const sunday = target || nextWeekday(0);
		api.state.currentDate = new Date(sunday.getFullYear(), sunday.getMonth(), 1);
		vi.spyOn(window.FFC, 'rest').mockResolvedValue(Object.assign({
			bookings: [], holidays: [], closed_weekdays: [0], environments: availability(), schedule_holidays: {}, global_holidays: [],
		}, extra || {}));
		api.renderCalendar();
		await flush();
		return sunday;
	}

	it('renders a day closed when no space opens on it, and keeps it shut', async () => {
		const sunday = await render(false);
		expect(window.$('.ffc-day[data-date="' + ymd(sunday) + '"]').not('.ffc-other-month').length).toBe(1);
		const $cell = window.$('.ffc-day[data-date="' + ymd(sunday) + '"]').not('.ffc-other-month');
		expect($cell.hasClass('ffc-closed')).toBe(true);
		expect($cell.hasClass('ffc-disabled')).toBe(true);
		expect($cell.hasClass('ffc-available')).toBe(false);
	});

	it('keeps a day open when at least one space opens on it', async () => {
		const saturday = nextWeekday(6);
		await render(false, null, saturday);
		const $cell = window.$('.ffc-day[data-date="' + ymd(saturday) + '"]').not('.ffc-other-month');
		expect($cell.length).toBe(1);
		expect($cell.hasClass('ffc-closed')).toBe(false);
		expect($cell.hasClass('ffc-disabled')).toBe(false);
	});

	it('marks a calendar holiday that closes every space as a holiday', async () => {
		const monday = nextWeekday(1);
		await render(false, { schedule_holidays: { 1: [ymd(monday)] } }, monday);
		const $cell = window.$('.ffc-day[data-date="' + ymd(monday) + '"]').not('.ffc-other-month');
		expect($cell.length).toBe(1);
		expect($cell.hasClass('ffc-holiday')).toBe(true);
		expect($cell.hasClass('ffc-disabled')).toBe(true);
	});

	it('lets a bypass holder open a closed day', async () => {
		const sunday = await render(true);
		const $cell = window.$('.ffc-day[data-date="' + ymd(sunday) + '"]').not('.ffc-other-month');
		expect($cell.hasClass('ffc-closed')).toBe(true);
		expect($cell.hasClass('ffc-disabled')).toBe(false);
		expect($cell.hasClass('ffc-bypassable')).toBe(true);
	});
});

describe('the booking overlay', () => {
	function open(canBypass, date) {
		api.state.config = config(canBypass);
		api.state.availability = { 10: availability()[0], 11: availability()[1] };
		api.openBookingModal(ymd(date));
		return window.$('#booking-environment-id option');
	}

	it('lists only the spaces open on the date to a caller without the bypass', () => {
		const $opts = open(false, nextWeekday(6));
		expect($opts.map((_, el) => el.value).get()).toEqual(['11']);
	});

	it('says so when no space opens on the date', () => {
		const $opts = open(false, nextWeekday(0));
		expect($opts.length).toBe(0);
		expect(document.body.textContent).toContain('No space is open on this date.');
	});

	it('lists every space to a bypass holder, the closed ones marked with their reason', () => {
		const $opts = open(true, nextWeekday(6));
		expect($opts.length).toBe(2);
		const hall = $opts.filter('[value="10"]');
		expect(hall.hasClass('ffc-env-option-restricted')).toBe(true);
		expect(hall.attr('data-reasons')).toBe('closed_day');
		expect(hall.text()).toContain('Closed');
		expect($opts.filter('[value="11"]').hasClass('ffc-env-option-restricted')).toBe(false);
	});

	it('tags the selected space with the reasons, outside hours included', () => {
		open(true, nextWeekday(6));
		window.$('#booking-environment-id').val('11');
		window.$('#booking-start-time').val('17:00');
		window.$('#booking-end-time').val('19:00');
		api.updateEnvironmentStatus();
		const tags = window.$('#booking-environment-status .ffc-env-reason-tag');
		expect(tags.length).toBe(1);
		expect(tags.hasClass('ffc-env-reason-outside_hours')).toBe(true);
		expect(window.$('#booking-environment-status').attr('hidden')).toBeUndefined();
	});
});

describe('FFC.overrideConfirm — the warning', () => {
	it('lists every reason in one dialog', () => {
		vi.useFakeTimers();
		window.FFC.overrideConfirm({
			reasons: [{ code: 'holiday', message: 'This date is a holiday.' }, { code: 'conflict', message: 'Already booked.' }],
			strings: window.ffcAudience.strings.override,
		});
		const dialogs = document.querySelectorAll('.ffc-override-dialog');
		expect(dialogs.length).toBe(1);
		const items = Array.from(dialogs[0].querySelectorAll('.ffc-override-reasons li')).map((li) => li.textContent);
		expect(items).toEqual(['This date is a holiday.', 'Already booked.']);
		expect(dialogs[0].getAttribute('role')).toBe('alertdialog');
	});

	it('keeps "I agree" disabled for 15 seconds, announcing the start and the end only', async () => {
		vi.useFakeTimers();
		const promise = window.FFC.overrideConfirm({ reasons: ['R'], strings: { countdownStart: 'Wait %d', countdownEnd: 'Go' } });
		const dialog = document.querySelector('.ffc-override-dialog');
		const agree = dialog.querySelector('[data-ffc-override="agree"]');
		const live = dialog.querySelector('.ffc-override-countdown');

		expect(live.getAttribute('aria-live')).toBe('polite');
		expect(agree.disabled).toBe(true);
		expect(agree.textContent).toBe('I agree (15)');
		expect(live.textContent).toBe('Wait 15');
		expect(document.activeElement).toBe(dialog.querySelector('[data-ffc-override="cancel"]'));

		vi.advanceTimersByTime(14000);
		expect(agree.disabled).toBe(true);
		expect(agree.textContent).toBe('I agree (1)');
		expect(live.textContent).toBe('Wait 15');

		vi.advanceTimersByTime(1000);
		expect(agree.disabled).toBe(false);
		expect(agree.textContent).toBe('I agree');
		expect(live.textContent).toBe('Go');

		agree.click();
		await expect(promise).resolves.toBe(true);
		expect(document.querySelector('.ffc-override-dialog')).toBeNull();
	});

	it('ignores a click on the disabled button', async () => {
		vi.useFakeTimers();
		const promise = window.FFC.overrideConfirm({ reasons: ['R'] });
		const dialog = document.querySelector('.ffc-override-dialog');
		dialog.querySelector('[data-ffc-override="agree"]').click();
		expect(document.querySelector('.ffc-override-dialog')).not.toBeNull();
		dialog.querySelector('[data-ffc-override="cancel"]').click();
		await expect(promise).resolves.toBe(false);
	});

	it('asks for a reason when told to, and resolves with it once it is long enough', async () => {
		vi.useFakeTimers();
		const promise = window.FFC.overrideConfirm({
			reasons: ['R'],
			strings: { seconds: 0 },
			reasonField: { label: 'Reason', hint: 'Between 10 and 300 characters.', min: 10, max: 300 },
		});
		const dialog = document.querySelector('.ffc-override-dialog');
		const field = dialog.querySelector('.ffc-override-reason textarea');
		const agree = dialog.querySelector('[data-ffc-override="agree"]');

		expect(field.required).toBe(true);
		expect(field.maxLength).toBe(300);
		expect(document.activeElement).toBe(field);
		expect(dialog.querySelector('label').getAttribute('for')).toBe(field.id);
		expect(document.getElementById(field.getAttribute('aria-describedby')).textContent).toBe('Between 10 and 300 characters.');
		expect(agree.disabled).toBe(true);

		field.value = 'Too short';
		field.dispatchEvent(new Event('input'));
		expect(agree.disabled).toBe(true);

		field.value = '  The visiting committee needs the room.  ';
		field.dispatchEvent(new Event('input'));
		expect(agree.disabled).toBe(false);

		agree.click();
		await expect(promise).resolves.toBe('The visiting committee needs the room.');
	});

	it('cancels at once, with Cancel or Escape, and gives the focus back', async () => {
		vi.useFakeTimers();
		const trigger = document.createElement('button');
		document.body.appendChild(trigger);
		trigger.focus();

		const first = window.FFC.overrideConfirm({ reasons: ['R'] });
		document.querySelector('[data-ffc-override="cancel"]').click();
		await expect(first).resolves.toBe(false);
		expect(document.activeElement).toBe(trigger);

		const second = window.FFC.overrideConfirm({ reasons: ['R'] });
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
		await expect(second).resolves.toBe(false);
		expect(document.querySelector('.ffc-override-dialog')).toBeNull();
	});
});

describe('the audience booking resubmits with the confirmation', () => {
	it('opens the warning on a 409 and sends again with confirm_override', async () => {
		api.state.config = config(true);
		window.$('#booking-environment-id').html('<option value="10">Hall</option>').val('10');
		window.$('#booking-date').val(ymd(nextWeekday(0)));
		window.$('#booking-audiences input[value="3"]').prop('checked', true);

		const rest = vi.spyOn(window.FFC, 'rest')
			.mockImplementationOnce(() => {
				const err = new Error('409');
				err.xhr = { responseJSON: { code: 'override_confirmation_required', reasons: [{ code: 'closed_day', message: 'Closed.' }] } };
				return Promise.reject(err);
			})
			.mockResolvedValueOnce({ success: true });
		const confirm = vi.spyOn(window.FFC, 'overrideConfirm').mockResolvedValue(true);
		vi.spyOn(window, 'alert').mockImplementation(() => {});
		api.renderCalendar = vi.fn();

		api.createBooking();
		await flush();
		await flush();

		expect(confirm).toHaveBeenCalledTimes(1);
		expect(confirm.mock.calls[0][0].reasons[0].code).toBe('closed_day');
		expect(rest).toHaveBeenCalledTimes(2);
		expect(rest.mock.calls[0][1].data.confirm_override).toBeUndefined();
		expect(rest.mock.calls[1][1].data.confirm_override).toBe(true);
	});

	it('asks for a reason when the 409 says days would be taken, and sends it', async () => {
		api.state.config = config(true);
		window.$('#booking-environment-id').html('<option value="10">Hall</option>').val('10');
		window.$('#booking-date').val(ymd(nextWeekday(0)));
		window.$('#booking-audiences input[value="3"]').prop('checked', true);

		const rest = vi.spyOn(window.FFC, 'rest')
			.mockImplementationOnce(() => {
				const err = new Error('409');
				err.xhr = { responseJSON: { code: 'override_confirmation_required', reasons: ['Booked.'], reason_required: { min: 10, max: 300 } } };
				return Promise.reject(err);
			})
			.mockResolvedValueOnce({ success: true });
		const confirm = vi.spyOn(window.FFC, 'overrideConfirm').mockResolvedValue('The visiting committee needs the room.');
		vi.spyOn(window, 'alert').mockImplementation(() => {});
		api.renderCalendar = vi.fn();

		api.createBooking();
		await flush();
		await flush();

		const asked = confirm.mock.calls[0][0].reasonField;
		expect(asked.min).toBe(10);
		expect(asked.max).toBe(300);
		expect(rest.mock.calls[1][1].data.override_reason).toBe('The visiting committee needs the room.');
	});

	it('sends nothing more when the warning is cancelled', async () => {
		api.state.config = config(true);
		window.$('#booking-environment-id').html('<option value="10">Hall</option>').val('10');
		window.$('#booking-date').val(ymd(nextWeekday(0)));
		window.$('#booking-audiences input[value="3"]').prop('checked', true);

		const rest = vi.spyOn(window.FFC, 'rest').mockImplementation(() => {
			const err = new Error('409');
			err.xhr = { responseJSON: { code: 'override_confirmation_required', reasons: ['x'] } };
			return Promise.reject(err);
		});
		vi.spyOn(window.FFC, 'overrideConfirm').mockResolvedValue(false);

		api.createBooking();
		await flush();
		await flush();

		expect(rest).toHaveBeenCalledTimes(1);
		expect(window.$('#ffc-booking-submit').prop('disabled')).toBe(false);
	});
});
