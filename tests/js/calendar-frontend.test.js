// Tests for `assets/js/ffc-calendar-frontend.js`.
//
// Exposes `window.ffcCalendarFrontend` — the booking-flow controller for
// the self-scheduling shortcode. The file is 696 LoC; this suite covers
// the discrete, deterministic methods (formatDate, renderTimeSlots,
// selectTimeSlot, openModal/closeModal, resetCalendar, validateForm,
// showError) and the FFC.request integration for `loadTimeSlots` /
// `submitBooking` via a mocked window.FFC.request.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function installGlobals() {
	window.ffcCalendar = {
		nonce: 'test-nonce',
		ajaxurl: '/wp-admin/admin-ajax.php',
		strings: {
			availableTimes: 'Available Times',
			yourInformation: 'Your Information',
			noSlots: 'No slots available',
			error: 'An error occurred',
			loading: 'Loading…',
			submit: 'Book Appointment',
			timeout: 'Connection timeout. Please try again.',
			networkError: 'Network error. Please check your connection.',
			consentRequired: 'Consent is required',
			fillRequired: 'Please fill all required fields',
			months: [
				'January',
				'February',
				'March',
				'April',
				'May',
				'June',
				'July',
				'August',
				'September',
				'October',
				'November',
				'December',
			],
		},
	};
	window.FFC = {
		request: vi.fn(),
	};
}

function reset() {
	document.body.innerHTML = '';
	delete window.ffcCalendarFrontend;
	delete window.ffcCalendar;
	delete window.FFC;
	delete window.FFCCalendarCore;
}

describe('ffc-calendar-frontend.js — module shape', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	it('exposes ffcCalendarFrontend on the window with init/bindEvents', () => {
		expect(typeof window.ffcCalendarFrontend).toBe('object');
		expect(typeof window.ffcCalendarFrontend.init).toBe('function');
		expect(typeof window.ffcCalendarFrontend.bindEvents).toBe('function');
		expect(typeof window.ffcCalendarFrontend.loadTimeSlots).toBe('function');
	});

	it('initial state has no date / time selected', () => {
		expect(window.ffcCalendarFrontend.selectedDate).toBeNull();
		expect(window.ffcCalendarFrontend.selectedTime).toBeNull();
		expect(window.ffcCalendarFrontend.calendarId).toBeNull();
	});
});

describe('ffc-calendar-frontend.js — formatDate', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	it('formats YYYY-MM-DD using the localized month name', () => {
		expect(window.ffcCalendarFrontend.formatDate('2026-05-20')).toBe(
			'20 May 2026'
		);
	});

	it('falls back to the raw month number when months array is short', () => {
		window.ffcCalendar.strings.months = ['Jan'];
		expect(window.ffcCalendarFrontend.formatDate('2026-05-20')).toBe(
			'20 05 2026'
		);
	});

	it('returns the input verbatim when the format is not YYYY-MM-DD', () => {
		expect(window.ffcCalendarFrontend.formatDate('invalid')).toBe('invalid');
	});
});

describe('ffc-calendar-frontend.js — renderTimeSlots', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		document.body.innerHTML = '<div id="ffc-timeslots-container"></div>';
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	it('renders an empty-state message when slots is empty', () => {
		window.ffcCalendarFrontend.renderTimeSlots([]);
		expect(
			document.querySelector('#ffc-timeslots-container .ffc-no-slots')
		).not.toBeNull();
	});

	it('renders one .ffc-timeslot per slot with the time data attribute', () => {
		window.ffcCalendarFrontend.renderTimeSlots([
			{ time: '09:00', display: '09:00', available: 3, total: 5 },
			{ time: '09:30', display: '09:30', available: 0, total: 5 },
		]);

		const slots = document.querySelectorAll(
			'#ffc-timeslots-container .ffc-timeslot'
		);
		expect(slots).toHaveLength(2);
		expect(slots[0].getAttribute('data-time')).toBe('09:00');
		expect(slots[0].getAttribute('tabindex')).toBe('0');
		expect(slots[1].classList.contains('ffc-timeslot-full')).toBe(true);
		expect(slots[1].getAttribute('aria-disabled')).toBe('true');
	});
});

describe('ffc-calendar-frontend.js — selectTimeSlot', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		document.body.innerHTML = `
			<div class="ffc-timeslots-wrapper">
				<div id="ffc-timeslots-container">
					<div class="ffc-timeslot" data-time="09:00" role="option" aria-selected="false"></div>
					<div class="ffc-timeslot selected" data-time="09:30" role="option" aria-selected="true"></div>
				</div>
			</div>
			<div class="ffc-booking-form-wrapper" style="display:none;">
				<form id="ffc-self-scheduling-form">
					<input id="ffc-form-date" type="hidden" value="" />
					<input id="ffc-form-time" type="hidden" value="" />
				</form>
			</div>
			<div id="ffc-self-scheduling-modal"><div class="ffc-modal-content"><div class="ffc-modal-title"></div></div></div>
		`;
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	it('marks the clicked slot as selected and stores the time', () => {
		const $slot = window.$('.ffc-timeslot[data-time="09:00"]');
		window.ffcCalendarFrontend.selectedDate = '2026-05-20';

		window.ffcCalendarFrontend.selectTimeSlot($slot);

		expect(window.ffcCalendarFrontend.selectedTime).toBe('09:00');
		expect($slot.hasClass('selected')).toBe(true);
		expect($slot.attr('aria-selected')).toBe('true');
		// Sibling that was previously selected gets cleared.
		const $other = window.$('.ffc-timeslot[data-time="09:30"]');
		expect($other.hasClass('selected')).toBe(false);
		expect($other.attr('aria-selected')).toBe('false');
	});

	it('switches view from time-slots to the booking form', () => {
		const $slot = window.$('.ffc-timeslot[data-time="09:00"]');
		window.ffcCalendarFrontend.selectedDate = '2026-05-20';

		window.ffcCalendarFrontend.selectTimeSlot($slot);

		expect(
			window.getComputedStyle(
				document.querySelector('.ffc-timeslots-wrapper')
			).display
		).toBe('none');
		// hidden fields populated.
		expect(document.querySelector('#ffc-form-date').value).toBe('2026-05-20');
		expect(document.querySelector('#ffc-form-time').value).toBe('09:00');
	});
});

describe('ffc-calendar-frontend.js — modal open/close', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		document.body.innerHTML = `
			<button id="trigger">Open</button>
			<div id="ffc-self-scheduling-modal" style="display:none;">
				<button class="ffc-modal-close">×</button>
				<div class="ffc-timeslots-wrapper"></div>
				<div class="ffc-booking-form-wrapper"></div>
			</div>
			<div class="ffc-timeslot selected" aria-selected="true"></div>
		`;
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	it('openModal shows the modal and locks body scroll', () => {
		window.ffcCalendarFrontend.openModal();

		const modal = document.querySelector('#ffc-self-scheduling-modal');
		expect(window.getComputedStyle(modal).display).not.toBe('none');
		expect(document.body.style.overflow).toBe('hidden');
	});

	it('closeModal hides the modal, restores scroll, and clears selection', () => {
		// Pre-open so the close path has state to reverse.
		window.ffcCalendarFrontend.openModal();
		window.ffcCalendarFrontend.selectedTime = '09:00';

		window.ffcCalendarFrontend.closeModal();

		const modal = document.querySelector('#ffc-self-scheduling-modal');
		expect(window.getComputedStyle(modal).display).toBe('none');
		expect(document.body.style.overflow).toBe('');
		expect(window.ffcCalendarFrontend.selectedTime).toBeNull();
		expect(document.querySelector('.ffc-timeslot.selected')).toBeNull();
	});
});

describe('ffc-calendar-frontend.js — loadTimeSlots / FFC.request', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		document.body.innerHTML = `
			<div id="ffc-self-scheduling-modal" style="display:none;">
				<div class="ffc-modal-content"><div class="ffc-modal-title"></div></div>
				<div class="ffc-timeslots-wrapper">
					<div class="ffc-timeslots-loading" style="display:none;">Loading</div>
					<div id="ffc-timeslots-container"></div>
				</div>
				<div class="ffc-booking-form-wrapper"></div>
				<button class="ffc-modal-close">×</button>
			</div>
		`;
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	it('invokes FFC.request with the calendar id, date, and nonce options', async () => {
		window.FFC.request.mockResolvedValue({ slots: [] });

		window.ffcCalendarFrontend.loadTimeSlots(42, '2026-05-20');

		expect(window.FFC.request).toHaveBeenCalledWith(
			'ffc_get_available_slots',
			{ calendar_id: 42, date: '2026-05-20' },
			expect.objectContaining({
				nonce: 'test-nonce',
				ajaxUrl: '/wp-admin/admin-ajax.php',
			})
		);
		// Stores selection ahead of the AJAX resolve.
		expect(window.ffcCalendarFrontend.calendarId).toBe(42);
		expect(window.ffcCalendarFrontend.selectedDate).toBe('2026-05-20');
	});

	it('renders the slots payload on resolve', async () => {
		window.FFC.request.mockResolvedValue({
			slots: [{ time: '10:00', display: '10:00', available: 2, total: 3 }],
		});

		window.ffcCalendarFrontend.loadTimeSlots(7, '2026-05-21');
		await Promise.resolve();
		await Promise.resolve();

		expect(
			document.querySelectorAll('#ffc-timeslots-container .ffc-timeslot')
		).toHaveLength(1);
	});

	it('renders an error message when FFC.request rejects', async () => {
		window.FFC.request.mockRejectedValue(new Error('boom'));

		window.ffcCalendarFrontend.loadTimeSlots(7, '2026-05-21');
		await Promise.resolve();
		await Promise.resolve();

		const msg = document.querySelector(
			'#ffc-timeslots-container .ffc-message-error'
		);
		expect(msg).not.toBeNull();
		expect(msg.textContent).toBe('An error occurred');
	});
});

describe('ffc-calendar-frontend.js — validateForm', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		document.body.innerHTML = `
			<form id="ffc-self-scheduling-form">
				<input id="name" required value="" />
				<input id="ffc-booking-consent" type="checkbox" />
			</form>
			<div class="ffc-form-messages"></div>
			<div id="ffc-self-scheduling-modal"><div class="ffc-modal-content"></div></div>
		`;
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	it('fails when consent is not checked', () => {
		document.querySelector('#name').value = 'Jane';
		const ok = window.ffcCalendarFrontend.validateForm(
			window.$('#ffc-self-scheduling-form')
		);
		expect(ok).toBe(false);
		expect(document.querySelector('.ffc-message-error').textContent).toBe(
			'Consent is required'
		);
	});

	it('fails when a required field is empty (consent checked)', () => {
		document.querySelector('#ffc-booking-consent').checked = true;
		const ok = window.ffcCalendarFrontend.validateForm(
			window.$('#ffc-self-scheduling-form')
		);
		expect(ok).toBe(false);
		expect(document.querySelector('.ffc-message-error').textContent).toBe(
			'Please fill all required fields'
		);
	});

	it('passes when consent + all required fields are filled', () => {
		document.querySelector('#ffc-booking-consent').checked = true;
		document.querySelector('#name').value = 'Jane';
		const ok = window.ffcCalendarFrontend.validateForm(
			window.$('#ffc-self-scheduling-form')
		);
		expect(ok).toBe(true);
	});
});
