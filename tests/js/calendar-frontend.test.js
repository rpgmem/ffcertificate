// Tests for `assets/js/ffc-calendar-frontend.js`.
//
// Exposes `window.ffcCalendarFrontend` — the booking-flow controller for
// the self-scheduling shortcode. The file is 696 LoC; this suite covers
// the discrete, deterministic methods (formatDate, renderTimeSlots,
// selectTimeSlot, openModal/closeModal, resetCalendar, validateForm,
// showError) and the FFC.request integration for `loadTimeSlots` /
// `submitBooking` via a mocked window.FFC.request.
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// The real FFC.notice() (#1842), kept across the suite's FFC mock: every
// notice the calendar prints goes through it.
let realNotice;
let realCore;
beforeAll(() => {
	window.ffc_ajax = window.ffc_ajax || { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
	realNotice = window.FFC.notice;
	realCore = window.FFC;
});

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
		notice: realNotice,
		buttonCategories: realCore.buttonCategories,
		button: realCore.button,
		buttonHtml: realCore.buttonHtml,
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

	it('renders a full slot with waitlist_available as a selectable waitlist option (#941)', () => {
		window.ffcCalendarFrontend.renderTimeSlots([
			{ time: '09:00', display: '09:00', available: 0, total: 5, waitlist_available: true },
		]);

		const slot = document.querySelector('#ffc-timeslots-container .ffc-timeslot');
		expect(slot.classList.contains('ffc-timeslot-waitlist')).toBe(true);
		expect(slot.classList.contains('ffc-timeslot-full')).toBe(false);
		// Selectable: keyboard-focusable, not aria-disabled, flagged as waitlist.
		expect(slot.getAttribute('tabindex')).toBe('0');
		expect(slot.getAttribute('aria-disabled')).toBeNull();
		expect(slot.getAttribute('data-waitlist')).toBe('1');
	});

	it('renders a full slot without a waitlist as a disabled full cell (#941)', () => {
		window.ffcCalendarFrontend.renderTimeSlots([
			{ time: '09:00', display: '09:00', available: 0, total: 5, waitlist_available: false },
		]);

		const slot = document.querySelector('#ffc-timeslots-container .ffc-timeslot');
		expect(slot.classList.contains('ffc-timeslot-full')).toBe(true);
		expect(slot.classList.contains('ffc-timeslot-waitlist')).toBe(false);
		expect(slot.getAttribute('data-waitlist')).toBeNull();
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
		expect($slot.hasClass('is-selected')).toBe(true);
		expect($slot.attr('aria-selected')).toBe('true');
		// Sibling that was previously selected gets cleared.
		const $other = window.$('.ffc-timeslot[data-time="09:30"]');
		expect($other.hasClass('is-selected')).toBe(false);
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

describe('ffc-calendar-frontend.js — waitlist and e-mail notices (#1842)', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		document.body.innerHTML = `
			<div class="ffc-booking-form-wrapper" style="display:none;"><form></form></div>
			<div class="ffc-confirmation-wrapper" style="display:none;">
				<div class="ffc-appointment-details"></div>
				<div class="ffc-confirmation-email" style="display: none;"><div data-ffc-notice="booking-email-sent"></div></div>
			</div>
			<div id="ffc-self-scheduling-modal"><div class="ffc-modal-content"><div class="ffc-modal-title"></div></div></div>
		`;
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	it('prepends one neutral waitlist notice and hides it for a normal slot', () => {
		const ctl = window.ffcCalendarFrontend;
		window.ffcCalendar.strings.waitlistNotice = '<b>Full</b>, you join the queue.';
		ctl.selectedWaitlist = true;
		ctl.showBookingForm();
		ctl.showBookingForm();

		const notices = document.querySelectorAll('.ffc-booking-form-wrapper [data-ffc-notice="waitlist"]');
		expect(notices.length).toBe(1);
		const notice = notices[0];
		expect(notice.className).toBe('ffc-notice ffc-notice-neutral');
		expect(notice.getAttribute('role')).toBe('status');
		expect(notice.querySelector('.ffc-icon-users')).not.toBeNull();
		expect(notice.querySelector('b')).toBeNull();
		expect(notice.textContent).toBe('<b>Full</b>, you join the queue.');
		expect(notice.getAttribute('style') || '').not.toContain('color');
		expect(document.querySelector('.ffc-booking-form-wrapper').firstElementChild).toBe(notice);

		ctl.selectedWaitlist = false;
		ctl.showBookingForm();
		expect(notice.style.display).toBe('none');
	});

	it('reveals the e-mail notice only when the server says it was sent', () => {
		const ctl = window.ffcCalendarFrontend;
		window.$.fn.animate = function () { return this; };
		ctl.selectedDate = '2026-05-20';
		ctl.selectedTime = '09:00';
		ctl.showConfirmation({ email_sent: false });
		expect(document.querySelector('.ffc-confirmation-email').style.display).toBe('none');
		ctl.showConfirmation({ email_sent: true });
		expect(document.querySelector('.ffc-confirmation-email').style.display).not.toBe('none');
	});

	// Either receipt control is the same action, so both are the family's
	// primary button with the download icon (#1874).
	it('draws the receipt as the primary download button, as a button or as a link', () => {
		const ctl = window.ffcCalendarFrontend;
		window.$.fn.animate = function () { return this; };
		window.ffcCalendar.strings.downloadReceipt = 'Download receipt';
		ctl.selectedDate = '2026-05-20';
		ctl.selectedTime = '09:00';

		ctl.showConfirmation({ pdf_data: { a: 1 } });
		const btn = document.querySelector('.ffc-receipt-actions button');
		expect(btn.className).toBe('ffc-btn ffc-btn-primary ffc-icon-download ffc-download-receipt-btn');
		expect(btn.textContent).toBe('Download receipt');
		expect(window.$(btn).data('pdfData')).toEqual({ a: 1 });

		ctl.showConfirmation({ receipt_url: 'https://example.test/r?x=1&y=2' });
		const link = document.querySelector('.ffc-receipt-actions a');
		expect(link.className).toBe('ffc-btn ffc-btn-primary ffc-icon-download');
		expect(link.getAttribute('href')).toBe('https://example.test/r?x=1&y=2');
		expect(link.getAttribute('target')).toBe('_blank');
		expect(link.getAttribute('rel')).toBe('noopener noreferrer');
	});
});

describe('ffc-calendar-frontend.js — modal open/close', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		document.body.innerHTML = `
			<button id="trigger">Open</button>
			<div id="ffc-self-scheduling-modal" style="display:none;">
				<button class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-cross ffc-modal-close" aria-label="Close"></button>
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
		expect(document.querySelector('.ffc-timeslot.is-selected')).toBeNull();
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
				<button class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-cross ffc-modal-close" aria-label="Close"></button>
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
			'#ffc-timeslots-container .ffc-notice.ffc-notice-danger[role="alert"]'
		);
		expect(msg).not.toBeNull();
		expect(msg.querySelector('.ffc-icon-error')).not.toBeNull();
		expect(msg.textContent).toBe('An error occurred');
		expect(msg.outerHTML).not.toContain('style=');
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
		// The real core, for FFC.fieldMessage() (#1844).
		loadScript('assets/js/ffc-core.js');
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	it('fails when consent is not checked', () => {
		document.querySelector('#name').value = 'Jane';
		const ok = window.ffcCalendarFrontend.validateForm(
			window.$('#ffc-self-scheduling-form')
		);
		expect(ok).toBe(false);
		const notice = document.querySelector('.ffc-form-messages .ffc-notice.ffc-notice-danger');
		expect(notice.getAttribute('role')).toBe('note');
		expect(notice.textContent).toBe('Consent is required');
		// …and under the box itself, in the field-message standard (#1844).
		const box = document.querySelector('#ffc-booking-consent');
		expect(box.getAttribute('aria-invalid')).toBe('true');
		const msg = document.getElementById(box.getAttribute('data-ffc-message-id'));
		expect(msg.textContent).toBe('Consent is required');
		expect(msg.getAttribute('role')).toBe('alert');
	});

	it('fails when a required field is empty (consent checked)', () => {
		document.querySelector('#ffc-booking-consent').checked = true;
		const ok = window.ffcCalendarFrontend.validateForm(
			window.$('#ffc-self-scheduling-form')
		);
		expect(ok).toBe(false);
		expect(document.querySelector('.ffc-form-messages .ffc-notice.ffc-notice-danger').textContent).toBe(
			'Please fill all required fields'
		);
		const name = document.querySelector('#name');
		expect(name.classList.contains('is-invalid')).toBe(true);
		expect(document.getElementById('ffc-msg-name').textContent).toBe('This field is required.');
		expect(name.getAttribute('aria-describedby')).toBe('ffc-msg-name');
	});

	it('withdraws the required message once the field is filled', () => {
		window.ffcCalendarFrontend.validateForm(window.$('#ffc-self-scheduling-form'));
		expect(document.getElementById('ffc-msg-name')).not.toBeNull();
		document.querySelector('#name').value = 'Jane';
		document.querySelector('#ffc-booking-consent').checked = true;
		expect(window.ffcCalendarFrontend.validateForm(window.$('#ffc-self-scheduling-form'))).toBe(true);
		expect(document.querySelector('.ffc-field-message')).toBeNull();
		expect(document.querySelector('#name').hasAttribute('aria-invalid')).toBe(false);
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

describe('ffc-calendar-frontend.js — submitBooking nonce plumbing', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		document.body.innerHTML = `
			<form id="ffc-self-scheduling-form">
				<input type="hidden" name="nonce" value="STALE-form-nonce" />
				<input type="hidden" name="action" value="ffc_book_appointment" />
				<input type="hidden" name="date" value="2026-05-20" />
				<input type="hidden" name="time" value="09:00" />
				<input type="checkbox" id="ffc-booking-consent" checked />
			</form>
			<div class="ffc-form-messages"></div>
			<div id="ffc-self-scheduling-modal"><div class="ffc-modal-content"></div></div>
		`;
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	// Regression: booking submitted the serialized form (which carries its own
	// `nonce` field) with no explicit nonce, so FFC.request appended the
	// frontend/empty fallback as the LAST `nonce=` param and check_ajax_referer
	// rejected every booking with a 403. The fix passes ffcCalendar.nonce and
	// strips the form-baked nonce/action from the payload.
	it('sends the self-scheduling nonce and strips the form-baked nonce/action', () => {
		// Never resolves — we only assert on the outgoing request.
		window.FFC.request.mockReturnValue(new Promise(() => {}));

		window.ffcCalendarFrontend.submitBooking(window.$('#ffc-self-scheduling-form'));

		expect(window.FFC.request).toHaveBeenCalledTimes(1);
		const [action, payload, options] = window.FFC.request.mock.calls[0];

		expect(action).toBe('ffc_book_appointment');
		expect(options).toEqual(
			expect.objectContaining({
				nonce: 'test-nonce',
				ajaxUrl: '/wp-admin/admin-ajax.php',
			})
		);
		expect(typeof payload).toBe('string');
		expect(payload).not.toMatch(/(^|&)nonce=/);
		expect(payload).not.toMatch(/(^|&)action=/);
		expect(payload).toContain('date=2026-05-20');
	});
});

describe('ffc-calendar-frontend.js — captcha refresh delegates to the shared UI (#1305)', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		// Both, in dependency order — which is the point. In production
		// `ffc-calendar-frontend` declares `ffc-frontend-helpers` as a
		// dependency (class-ffc-self-scheduling-shortcode.php), and that is
		// what makes the shared implementation reachable here instead of a
		// private copy.
		loadScript('assets/js/ffc-field-behaviours.js');
		loadScript('assets/js/ffc-frontend-helpers.js');
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	/**
	 * This file used to carry its own `refreshCaptcha`, and the four tests here
	 * pinned the #1056 scoping lesson on it. The copy is gone: this bundle
	 * already declared `ffc-frontend-helpers` as a dependency, so it was
	 * duplication rather than isolation — and duplication is why the ALTCHA fix
	 * would otherwise have had to be written twice.
	 *
	 * The scoping lesson did not go with it. It is pinned on the shared
	 * implementation now, in `frontend-helpers-masks-and-ui.test.js`, where the
	 * code that owns it lives. What is left to check here is the delegation.
	 */
	it('no longer carries a private copy', () => {
		expect(window.ffcCalendarFrontend.refreshCaptcha).toBeUndefined();
	});

	it('reaches the shared implementation with the server payload', () => {
		document.body.innerHTML = `
			<form class="ffc-booking-form" id="form-a">
				<div class="ffc-captcha-row">
					<span class="ffc-captcha-label-text">1 + 1</span>
					<input type="number" name="ffc_captcha_ans" value="11">
					<input type="hidden" name="ffc_captcha_hash" value="token-a">
				</div>
			</form>`;

		window.FFC.Frontend.UI.refreshCaptcha(window.$('#form-a'), {
			provider: 'math',
			new_label: '9 + 9',
			new_hash: 'token-a2',
		});

		expect(window.$('#form-a').find('.ffc-captcha-label-text').text()).toBe('9 + 9');
		expect(window.$('#form-a').find('input[name="ffc_captcha_hash"]').val()).toBe('token-a2');
		expect(window.$('#form-a').find('input[name="ffc_captcha_ans"]').val()).toBe('');
	});
});

describe('ffc-calendar-frontend.js — the bypass override warning (#1859)', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		document.body.innerHTML = `
			<form id="ffc-self-scheduling-form">
				<input type="hidden" name="nonce" value="n" />
				<input type="hidden" name="action" value="ffc_book_appointment" />
				<input type="hidden" name="calendar_id" value="4" />
				<input type="hidden" name="date" value="2026-05-23" />
				<input type="hidden" name="time" value="09:00" />
				<input type="checkbox" id="ffc-booking-consent" checked />
				<button type="submit">Book</button>
			</form>
			<div class="ffc-form-messages"></div>
			<div id="ffc-self-scheduling-modal"><div class="ffc-modal-content"></div></div>
		`;
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	function flush() {
		return Promise.resolve().then(() => Promise.resolve()).then(() => Promise.resolve());
	}

	it('asks for the reasons first and submits with the confirmation once agreed', async () => {
		window.ffcCalendarFrontend.config = { canBypass: true };
		window.FFC.overrideConfirm = vi.fn().mockResolvedValue(true);
		window.FFC.request
			.mockResolvedValueOnce({ reasons: [{ code: 'too_far', message: 'Too far.' }] })
			.mockReturnValueOnce(new Promise(() => {}));

		window.ffcCalendarFrontend.submitBooking(window.$('#ffc-self-scheduling-form'));
		await flush();

		expect(window.FFC.request.mock.calls[0][0]).toBe('ffc_check_booking_override');
		expect(window.FFC.request.mock.calls[0][1]).toEqual({ calendar_id: '4', date: '2026-05-23', time: '09:00' });
		expect(window.FFC.overrideConfirm).toHaveBeenCalledTimes(1);
		expect(window.FFC.overrideConfirm.mock.calls[0][0].reasons[0].code).toBe('too_far');
		expect(window.FFC.request.mock.calls[1][0]).toBe('ffc_book_appointment');
		expect(window.FFC.request.mock.calls[1][1]).toContain('confirm_override=1');
	});

	it('submits nothing when the warning is cancelled', async () => {
		window.ffcCalendarFrontend.config = { canBypass: true };
		window.FFC.overrideConfirm = vi.fn().mockResolvedValue(false);
		window.FFC.request.mockResolvedValueOnce({ reasons: ['x'] });

		window.ffcCalendarFrontend.submitBooking(window.$('#ffc-self-scheduling-form'));
		await flush();

		expect(window.FFC.request).toHaveBeenCalledTimes(1);
		expect(window.$('#ffc-self-scheduling-form button[type="submit"]').prop('disabled')).toBe(false);
	});

	it('submits straight away when nothing is broken, or without the bypass', async () => {
		window.ffcCalendarFrontend.config = { canBypass: true };
		window.FFC.overrideConfirm = vi.fn();
		window.FFC.request
			.mockResolvedValueOnce({ reasons: [] })
			.mockReturnValueOnce(new Promise(() => {}));

		window.ffcCalendarFrontend.submitBooking(window.$('#ffc-self-scheduling-form'));
		await flush();

		expect(window.FFC.overrideConfirm).not.toHaveBeenCalled();
		expect(window.FFC.request.mock.calls[1][1]).not.toContain('confirm_override');
	});

	it('shows the reasons the server returned when the rules changed after the check', async () => {
		window.ffcCalendarFrontend.config = { canBypass: false };
		window.FFC.overrideConfirm = vi.fn().mockResolvedValue(false);
		const err = new Error('confirm');
		err.fromServer = true;
		err.data = { code: 'override_confirmation_required', reasons: [{ code: 'daily_limit', message: 'Full.' }] };
		window.FFC.request.mockRejectedValueOnce(err);

		window.ffcCalendarFrontend.submitBooking(window.$('#ffc-self-scheduling-form'));
		await flush();

		expect(window.FFC.overrideConfirm).toHaveBeenCalledTimes(1);
		expect(window.FFC.overrideConfirm.mock.calls[0][0].reasons[0].code).toBe('daily_limit');
	});
});

describe('ffc-calendar-frontend.js — a closed day names itself (#2218)', () => {
	let options;
	beforeEach(() => {
		reset();
		installGlobals();
		options = null;
		window.FFCCalendarCore = function (_$container, opts) {
			options = opts;
			this.options = opts;
			this.refresh = () => {};
		};
		window.FFC.request.mockReturnValue(new Promise(() => {}));
		document.body.innerHTML =
			'<div id="ffc-calendar-container-7"></div>' +
			'<script type="application/json" id="ffc-calendar-config-7">{"calendarId":7,"workingDays":[1,2,3,4,5]}</script>';
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	it('draws the closed cell name, escaped', () => {
		window.ffcCalendar.strings.closedCell = 'Encerrado <b>';
		window.ffcCalendarFrontend.initCalendarCore();
		const html = options.getDayContent('2026-06-14', new Date(2026, 5, 14), false, true);
		const $badge = window.$('<div>').html(html).find('.ffc-day-badge.ffc-badge-closed');
		expect($badge.text()).toBe('Encerrado <b>');
		expect($badge.find('b').length).toBe(0);
	});

	it('names an available day only when a name is set, and never over its bookings (#2221)', () => {
		const monday = new Date();
		monday.setDate(monday.getDate() + ((8 - monday.getDay()) % 7 || 7));
		const dateStr = monday.toISOString().slice(0, 10);
		window.ffcCalendarFrontend.initCalendarCore();
		expect(options.getDayContent(dateStr, monday, false, false)).toBe('');

		window.ffcCalendar.strings.availableCell = 'Livre <i>';
		window.ffcCalendarFrontend.initCalendarCore();
		const $badge = window.$('<div>').html(options.getDayContent(dateStr, monday, false, false)).find('.ffc-badge-available');
		expect($badge.text()).toBe('Livre <i>');
		expect($badge.find('i').length).toBe(0);

		const sunday = new Date(monday);
		sunday.setDate(monday.getDate() - 1);
		expect(options.getDayContent(sunday.toISOString().slice(0, 10), sunday, false, false)).toBe('');
	});

	it('draws nothing when the switch is off, and a holiday stays a holiday', () => {
		window.ffcCalendar.strings.closedCell = 'Fechado';
		window.ffcCalendar.strings.showClosedLabel = false;
		window.ffcCalendarFrontend.initCalendarCore();
		expect(options.getDayContent('2026-06-14', new Date(2026, 5, 14), false, true)).toBe('');
		expect(options.getDayContent('2026-06-15', new Date(2026, 5, 15), true, false)).toContain('ffc-badge-holiday');
	});

	it("takes this calendar's own names over the general ones (#2223)", () => {
		window.ffcCalendar.strings.closedCell = 'Fechado';
		window.ffcCalendar.strings.booked = 'Ocupado';
		document.getElementById('ffc-calendar-config-7').textContent = JSON.stringify({
			calendarId: 7,
			workingDays: [1, 2, 3, 4, 5],
			labels: { closedCell: 'Sem atendimento', showClosedLabel: true, bookings: 'sessões' },
		});
		window.ffcCalendarFrontend.initCalendarCore();

		expect(options.getDayContent('2026-06-14', new Date(2026, 5, 14), false, true)).toContain('Sem atendimento');
		expect(options.legendItems[1].label).toBe('Ocupado');
		expect(options.strings.bookings).toBe('sessões');
		expect(window.ffcCalendar.strings.closedCell).toBe('Fechado');
	});
});

// The whole booking modal, for the delegated handlers bindEvents() wires.
const MODAL_FIXTURE = `
	<button id="trigger">Open</button>
	<div class="ffc-audience-calendar"></div>
	<div class="ffc-calendar-container"></div>
	<div id="ffc-self-scheduling-modal" style="display:none;">
		<div class="ffc-modal-backdrop">
			<div class="ffc-modal-content">
				<button class="ffc-modal-close">x</button>
				<div class="ffc-modal-title"></div>
				<div class="ffc-timeslots-wrapper">
					<div id="ffc-timeslots-container">
						<div class="ffc-timeslot ffc-timeslot-full" data-time="08:30"></div>
						<div class="ffc-timeslot" tabindex="0" data-time="09:00"></div>
						<div class="ffc-timeslot" tabindex="0" data-time="09:30" data-waitlist="1"></div>
					</div>
				</div>
				<div class="ffc-booking-form-wrapper" style="display:none;">
					<form id="ffc-self-scheduling-form">
						<input type="hidden" name="nonce" value="stale" />
						<input type="hidden" name="action" value="ffc_book_appointment" />
						<input type="hidden" name="calendar_id" value="7" />
						<input type="hidden" id="ffc-form-date" name="date" />
						<input type="hidden" id="ffc-form-time" name="time" />
						<input id="ffc-booking-name" name="name" required value="Jane" />
						<input id="ffc-booking-email" name="email" value="jane@example.test" />
						<input id="ffc-booking-cpf-rf" name="cpf_rf" />
						<input type="checkbox" id="ffc-booking-consent" checked />
						<button type="submit">Book</button>
						<button type="button" class="ffc-btn-back">Back</button>
					</form>
					<div class="ffc-form-messages"></div>
				</div>
			</div>
		</div>
	</div>
	<div class="ffc-confirmation-wrapper" style="display:none;">
		<div class="ffc-confirmation-success"><h3>Booked</h3></div>
		<div class="ffc-appointment-details"></div>
		<div class="ffc-confirmation-email" style="display:none;"></div>
		<button class="ffc-btn-new-booking">New booking</button>
	</div>
`;

function tick() {
	return new Promise((r) => setTimeout(r, 0));
}

function flushPromises() {
	return Promise.resolve().then(() => Promise.resolve()).then(() => Promise.resolve()).then(() => Promise.resolve());
}

// jsdom has no layout, so jQuery's `:visible` is false for everything;
// this gives one element a client rect so it counts as visible.
function makeVisible(el) {
	el.getClientRects = () => [{ width: 1, height: 1 }];
}

function display(selector) {
	return document.querySelector(selector).style.display;
}

// Load the script, let its document-ready init run, then drop every
// delegated handler earlier loads left on `document` and bind once, so
// each event below reaches exactly one controller.
async function loadWithEvents() {
	loadScript('assets/js/ffc-calendar-frontend.js');
	await tick();
	window.$(document).off();
	const masks = window.FFC.Frontend && window.FFC.Frontend.Masks;
	if (masks) {
		// Earlier loads' document-ready inits reach the same global mock.
		masks.applyCpfRf.mockClear();
	}
	window.ffcCalendarFrontend.bindEvents();
	return window.ffcCalendarFrontend;
}

describe('ffc-calendar-frontend.js — delegated events', () => {
	let applyCpfRf;
	let animateCalls;
	beforeEach(() => {
		reset();
		installGlobals();
		window.$.fx.off = true;
		animateCalls = [];
		window.$.fn.animate = function (props, _duration, done) {
			animateCalls.push(props);
			if (done) {
				this.each(function () { done.call(this); });
			}
			return this;
		};
		applyCpfRf = vi.fn();
		window.FFC.Frontend = { Masks: { applyCpfRf }, UI: { refreshCaptcha: vi.fn() } };
		document.body.innerHTML = MODAL_FIXTURE;
	});

	it('applies the CPF/RF mask at bind time and again when the field takes focus', async () => {
		await loadWithEvents();
		expect(applyCpfRf).toHaveBeenCalledTimes(1);
		expect(applyCpfRf.mock.calls[0][0][0]).toBe(document.getElementById('ffc-booking-cpf-rf'));

		window.$(document.getElementById('ffc-booking-cpf-rf')).trigger('focus');
		expect(applyCpfRf.mock.calls.length).toBeGreaterThanOrEqual(2);
		expect(applyCpfRf.mock.calls.at(-1)[0][0]).toBe(document.getElementById('ffc-booking-cpf-rf'));
	});

	it('selects a slot on click, ignores a full slot, and flags a waitlist slot', async () => {
		const ctl = await loadWithEvents();
		ctl.selectedDate = '2026-05-20';

		window.$('.ffc-timeslot[data-time="08:30"]').trigger('click');
		expect(ctl.selectedTime).toBeNull();

		window.$('.ffc-timeslot[data-time="09:30"]').trigger('click');
		expect(ctl.selectedTime).toBe('09:30');
		expect(ctl.selectedWaitlist).toBe(true);
		expect(document.getElementById('ffc-form-time').value).toBe('09:30');
		expect(display('.ffc-booking-form-wrapper')).not.toBe('none');
		expect(document.querySelector('.ffc-modal-title').textContent).toBe('Your Information');
	});

	it('selects a slot with Enter or Space and ignores other keys', async () => {
		const ctl = await loadWithEvents();
		const $slot = window.$('.ffc-timeslot[data-time="09:00"]');

		const other = window.$.Event('keydown', { key: 'a' });
		$slot.trigger(other);
		expect(ctl.selectedTime).toBeNull();
		expect(other.isDefaultPrevented()).toBe(false);

		const space = window.$.Event('keydown', { key: ' ' });
		$slot.trigger(space);
		expect(ctl.selectedTime).toBe('09:00');
		expect(space.isDefaultPrevented()).toBe(true);

		ctl.selectedTime = null;
		$slot.trigger(window.$.Event('keydown', { key: 'Enter' }));
		expect(ctl.selectedTime).toBe('09:00');
	});

	it('moves focus to the first visible editable input when the form shows', async () => {
		const ctl = await loadWithEvents();
		makeVisible(document.getElementById('ffc-booking-name'));
		ctl.openModal();
		applyCpfRf.mockClear();

		ctl.showBookingForm();

		expect(document.activeElement).toBe(document.getElementById('ffc-booking-name'));
		expect(applyCpfRf).toHaveBeenCalledTimes(1);
	});

	it('submits the booking form through submitBooking instead of the browser', async () => {
		await loadWithEvents();
		window.FFC.request.mockReturnValue(new Promise(() => {}));
		const ev = window.$.Event('submit');

		window.$('#ffc-self-scheduling-form').trigger(ev);

		expect(ev.isDefaultPrevented()).toBe(true);
		expect(window.FFC.request).toHaveBeenCalledTimes(1);
		expect(window.FFC.request.mock.calls[0][0]).toBe('ffc_book_appointment');
		expect(window.$('#ffc-self-scheduling-form button[type="submit"]').prop('disabled')).toBe(true);
		expect(window.$('#ffc-self-scheduling-form button[type="submit"]').text()).toBe('Loading…');
	});

	it('goes back from the form to the slots, restoring the title and focusing the first free slot', async () => {
		const ctl = await loadWithEvents();
		ctl.openModal();
		ctl.selectedDate = '2026-05-20';
		ctl.selectedTime = '09:00';
		window.$('.ffc-booking-form-wrapper').show();
		window.$('.ffc-timeslots-wrapper').hide();
		window.$('.ffc-timeslot[data-time="09:00"]').addClass('is-selected');

		window.$('.ffc-btn-back').trigger('click');

		expect(display('.ffc-booking-form-wrapper')).toBe('none');
		expect(display('.ffc-timeslots-wrapper')).not.toBe('none');
		expect(document.querySelector('.ffc-timeslot.is-selected')).toBeNull();
		expect(ctl.selectedTime).toBeNull();
		expect(document.querySelector('.ffc-modal-title').textContent).toBe('Available Times — 20 May 2026');
		expect(document.activeElement).toBe(document.querySelector('.ffc-timeslot[data-time="09:00"]'));
	});

	it('resets the whole flow on "new booking"', async () => {
		const ctl = await loadWithEvents();
		ctl.selectedDate = '2026-05-20';
		ctl.selectedTime = '09:00';
		document.getElementById('ffc-booking-name').value = 'Changed';
		document.querySelector('.ffc-form-messages').innerHTML = '<p>old</p>';
		const $btn = window.$('#ffc-self-scheduling-form button[type="submit"]');
		$btn.prop('disabled', true).text('Loading…').data('submitting', true);
		window.$('.ffc-confirmation-wrapper').show();
		window.$('.ffc-calendar-container').hide();

		window.$('.ffc-btn-new-booking').trigger('click');

		expect(display('.ffc-confirmation-wrapper')).toBe('none');
		expect(display('.ffc-calendar-container')).not.toBe('none');
		expect(document.getElementById('ffc-booking-name').value).toBe('Jane');
		expect(document.querySelector('.ffc-form-messages').innerHTML).toBe('');
		expect($btn.prop('disabled')).toBe(false);
		expect($btn.text()).toBe('Book Appointment');
		expect($btn.data('submitting')).toBe(false);
		expect(ctl.selectedDate).toBeNull();
		expect(ctl.selectedTime).toBeNull();
		expect(animateCalls.at(-1)).toEqual({ scrollTop: -50 });
	});

	it('closes on the backdrop and the close button, but not on a click inside the content', async () => {
		const ctl = await loadWithEvents();
		ctl.openModal();
		ctl.selectedTime = '09:00';

		window.$('.ffc-modal-title').trigger('click');
		expect(display('#ffc-self-scheduling-modal')).not.toBe('none');
		expect(ctl.selectedTime).toBe('09:00');

		window.$('.ffc-modal-backdrop').trigger('click');
		expect(display('#ffc-self-scheduling-modal')).toBe('none');
		expect(ctl.selectedTime).toBeNull();

		ctl.openModal();
		window.$('.ffc-modal-close').trigger('click');
		expect(display('#ffc-self-scheduling-modal')).toBe('none');
	});

	it('closes on Escape only while the modal is visible, returning focus to the trigger', async () => {
		const ctl = await loadWithEvents();
		document.getElementById('trigger').focus();
		ctl.openModal();
		expect(document.activeElement).toBe(document.querySelector('.ffc-modal-close'));

		window.$(document).trigger(window.$.Event('keydown', { key: 'Escape' }));
		// Not visible to jQuery (no layout), so nothing closes.
		expect(display('#ffc-self-scheduling-modal')).not.toBe('none');

		makeVisible(document.getElementById('ffc-self-scheduling-modal'));
		window.$(document).trigger(window.$.Event('keydown', { key: 'Escape' }));
		expect(display('#ffc-self-scheduling-modal')).toBe('none');
		expect(document.activeElement).toBe(document.getElementById('trigger'));
	});

	it('traps Tab and Shift+Tab inside the modal', async () => {
		const ctl = await loadWithEvents();
		const close = document.querySelector('.ffc-modal-close');
		const back = document.querySelector('.ffc-btn-back');
		makeVisible(close);
		makeVisible(back);
		ctl.openModal();
		window.$('.ffc-booking-form-wrapper').show();
		const $modal = window.$('#ffc-self-scheduling-modal');

		const other = window.$.Event('keydown', { key: 'a' });
		$modal.trigger(other);
		expect(other.isDefaultPrevented()).toBe(false);

		back.focus();
		const tab = window.$.Event('keydown', { key: 'Tab', shiftKey: false });
		$modal.trigger(tab);
		expect(tab.isDefaultPrevented()).toBe(true);
		expect(document.activeElement).toBe(close);

		const shiftTab = window.$.Event('keydown', { key: 'Tab', shiftKey: true });
		$modal.trigger(shiftTab);
		expect(shiftTab.isDefaultPrevented()).toBe(true);
		expect(document.activeElement).toBe(back);

		// Tab from the middle of the cycle is left to the browser.
		const mid = window.$.Event('keydown', { key: 'Tab', shiftKey: true });
		$modal.trigger(mid);
		expect(mid.isDefaultPrevented()).toBe(false);
	});

	it('downloads the receipt from the manual button, straight away without a pre-flight', async () => {
		await loadWithEvents();
		window.ffcGeneratePDF = vi.fn();
		document.querySelector('.ffc-appointment-details').innerHTML =
			'<div class="ffc-receipt-actions"><button class="ffc-download-receipt-btn">Get</button></div>';
		const $btn = window.$('.ffc-download-receipt-btn');

		$btn.trigger('click');
		expect(window.ffcGeneratePDF).not.toHaveBeenCalled();

		$btn.data('pdfData', { html: '<p>r</p>' }).data('appointmentId', 9);
		$btn.trigger('click');
		expect(window.ffcGeneratePDF).toHaveBeenCalledWith({ html: '<p>r</p>' }, 'appointment_receipt.pdf');
		delete window.ffcGeneratePDF;
	});
});

describe('ffc-calendar-frontend.js — downloadReceipt and the pre-flight (#2040)', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		document.body.innerHTML = '<div class="ffc-receipt-actions"></div>';
		loadScript('assets/js/ffc-calendar-frontend.js');
		window.ffcGeneratePDF = vi.fn();
	});

	it('hands the download to the pre-flight, which runs it with the receipt filename', () => {
		const beforeDownload = vi.fn();
		window.FFC.Preflight = { beforeDownload };
		window.ffcCalendar.preflight = { browserCheck: true, strings: { title: 'Switch browser' } };
		const $actions = window.$('.ffc-receipt-actions');

		window.ffcCalendarFrontend.downloadReceipt($actions, { filename: 'r-9.pdf' }, 9);

		expect(window.ffcGeneratePDF).not.toHaveBeenCalled();
		expect(beforeDownload).toHaveBeenCalledTimes(1);
		const args = beforeDownload.mock.calls[0][0];
		expect(args.wrapper).toBe($actions);
		expect(args.trigger).toBe('.ffc-download-receipt-btn');
		expect(args.telemetry).toEqual({ type: 'receipt', id: 9 });
		expect(args.browserCheck).toBe(true);
		expect(args.strings).toEqual({ title: 'Switch browser' });

		args.go();
		expect(window.ffcGeneratePDF).toHaveBeenCalledWith({ filename: 'r-9.pdf' }, 'r-9.pdf');
	});

	it('passes an empty pre-flight config when the page localised none', () => {
		const beforeDownload = vi.fn();
		window.FFC.Preflight = { beforeDownload };

		window.ffcCalendarFrontend.downloadReceipt(window.$('.ffc-receipt-actions'), {}, 3);

		expect(beforeDownload.mock.calls[0][0].browserCheck).toBeUndefined();
		expect(beforeDownload.mock.calls[0][0].strings).toBeUndefined();
	});
});

describe('ffc-calendar-frontend.js — showConfirmation details', () => {
	let animateCalls;
	beforeEach(() => {
		reset();
		installGlobals();
		Object.assign(window.ffcCalendar.strings, {
			date: 'Date',
			time: 'Time',
			name: 'Name',
			email: 'Email',
			status: 'Status',
			confirmed: 'Confirmed',
			pendingApproval: 'Pending approval',
			confirmationCodeHelp: 'Keep this code.',
			downloadReceipt: 'Download receipt',
		});
		animateCalls = [];
		window.$.fn.animate = function (props, _duration, done) {
			animateCalls.push(props);
			if (done) {
				this.each(function () { done.call(this); });
			}
			return this;
		};
		document.body.innerHTML = MODAL_FIXTURE;
		loadScript('assets/js/ffc-calendar-frontend.js');
		const ctl = window.ffcCalendarFrontend;
		ctl.selectedDate = '2026-05-20';
		ctl.selectedTime = '09:00';
	});

	function details() {
		return document.querySelector('.ffc-appointment-details');
	}

	it('escapes the visitor name and the validation code, and prints the code block', () => {
		document.getElementById('ffc-booking-name').value = '<img src=x onerror="1">&\'';
		window.ffcCalendarFrontend.showConfirmation({ validation_code: '<b>AB-12</b>' });

		expect(details().querySelector('img')).toBeNull();
		expect(details().textContent).toContain('Name: <img src=x onerror="1">&\'');
		expect(details().textContent).toContain('Date: 20 May 2026');
		expect(details().textContent).toContain('Time: 09:00');
		expect(details().textContent).toContain('Status: Confirmed');
		const code = details().querySelector('.ffc-confirmation-code .ffc-code-value');
		expect(code.textContent).toBe('<b>AB-12</b>');
		expect(code.querySelector('b')).toBeNull();
		expect(details().querySelector('.ffc-code-help').textContent).toBe('Keep this code.');
		expect(details().querySelector('.ffc-confirmation-code').textContent).toContain('Validation Code:');
	});

	it('names the status: waitlisted wins over approval, approval over confirmed', () => {
		const ctl = window.ffcCalendarFrontend;
		ctl.showConfirmation({ waitlisted: true, requires_approval: true });
		expect(details().textContent).toContain('Status: On waitlist');

		ctl.showConfirmation({ requires_approval: true });
		expect(details().textContent).toContain('Status: Pending approval');
		expect(details().querySelector('.ffc-confirmation-code')).toBeNull();
	});

	it('hides the modal and calendar, shows the confirmation and focuses its heading', () => {
		window.$('#ffc-self-scheduling-modal').show();
		document.body.style.overflow = 'hidden';

		window.ffcCalendarFrontend.showConfirmation({});

		expect(display('#ffc-self-scheduling-modal')).toBe('none');
		expect(document.body.style.overflow).toBe('');
		expect(display('.ffc-calendar-container')).toBe('none');
		expect(display('.ffc-confirmation-wrapper')).not.toBe('none');
		expect(animateCalls[0]).toEqual({ scrollTop: -100 });
		const h3 = document.querySelector('.ffc-confirmation-success h3');
		expect(h3.getAttribute('tabindex')).toBe('-1');
		expect(document.activeElement).toBe(h3);
	});

	it('downloads the receipt by itself half a second after confirming', () => {
		vi.useFakeTimers();
		try {
			window.ffcGeneratePDF = vi.fn();
			window.ffcCalendarFrontend.showConfirmation({ pdf_data: { filename: 'rcpt.pdf' }, appointment_id: 5 });
			expect(window.ffcGeneratePDF).not.toHaveBeenCalled();
			vi.advanceTimersByTime(500);
			expect(window.ffcGeneratePDF).toHaveBeenCalledWith({ filename: 'rcpt.pdf' }, 'rcpt.pdf');
			expect(window.$('.ffc-download-receipt-btn').data('appointmentId')).toBe(5);
		} finally {
			vi.useRealTimers();
			delete window.ffcGeneratePDF;
		}
	});
});

describe('ffc-calendar-frontend.js — submitBooking and sendBooking outcomes', () => {
	let refreshCaptcha;
	beforeEach(() => {
		reset();
		installGlobals();
		refreshCaptcha = vi.fn();
		window.FFC.Frontend = { UI: { refreshCaptcha } };
		window.FFC.fieldMessage = vi.fn();
		window.FFC.clearFieldMessage = vi.fn();
		window.FFC.requiredMessage = () => 'Required.';
		document.body.innerHTML = MODAL_FIXTURE;
		document.getElementById('ffc-form-date').value = '2026-05-20';
		document.getElementById('ffc-form-time').value = '09:00';
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	const $form = () => window.$('#ffc-self-scheduling-form');
	const $btn = () => window.$('#ffc-self-scheduling-form button[type="submit"]');
	const message = () => document.querySelector('.ffc-form-messages').textContent;

	function serverError(data, msg) {
		const err = new Error(msg || '');
		err.message = msg || '';
		err.fromServer = true;
		err.data = data;
		return err;
	}

	it('does nothing while a submission is already in flight', () => {
		document.querySelector('.ffc-form-messages').innerHTML = '<p>keep</p>';
		$btn().data('submitting', true);

		window.ffcCalendarFrontend.submitBooking($form());

		expect(window.FFC.request).not.toHaveBeenCalled();
		expect(message()).toBe('keep');
	});

	it('does not send an invalid form', () => {
		document.getElementById('ffc-booking-name').value = '';

		window.ffcCalendarFrontend.submitBooking($form());

		expect(window.FFC.request).not.toHaveBeenCalled();
		expect(window.FFC.fieldMessage).toHaveBeenCalledWith(document.getElementById('ffc-booking-name'), 'error', 'Required.');
		expect(message()).toBe('Please fill all required fields');
		expect($btn().prop('disabled')).toBe(false);
	});

	it('still submits, unconfirmed, when the override check itself fails', async () => {
		window.ffcCalendarFrontend.config = { canBypass: true };
		window.FFC.request
			.mockRejectedValueOnce(new Error('network'))
			.mockReturnValueOnce(new Promise(() => {}));

		window.ffcCalendarFrontend.submitBooking($form());
		await flushPromises();

		expect(window.FFC.request).toHaveBeenCalledTimes(2);
		expect(window.FFC.request.mock.calls[1][0]).toBe('ffc_book_appointment');
		expect(window.FFC.request.mock.calls[1][1]).not.toContain('confirm_override');
	});

	it('shows the confirmation on success and clears the in-flight flag', async () => {
		const ctl = window.ffcCalendarFrontend;
		const show = vi.spyOn(ctl, 'showConfirmation').mockImplementation(() => {});
		window.FFC.request.mockResolvedValueOnce({ appointment_id: 3 });
		$btn().data('submitting', true);

		ctl.sendBooking($form(), false);
		await flushPromises();

		expect(window.FFC.request.mock.calls[0][2]).toEqual({ nonce: 'test-nonce', ajaxUrl: '/wp-admin/admin-ajax.php', timeout: 30000 });
		expect(show).toHaveBeenCalledWith({ appointment_id: 3 });
		expect($btn().data('submitting')).toBe(false);
	});

	it('refreshes the captcha and asks again when the server wants the override confirmed', async () => {
		const ctl = window.ffcCalendarFrontend;
		window.FFC.overrideConfirm = vi.fn().mockResolvedValue(false);
		const data = { code: 'override_confirmation_required', refresh_captcha: true, new_hash: 'h2', reasons: [{ code: 'closed' }] };
		window.FFC.request.mockRejectedValueOnce(serverError(data));

		ctl.sendBooking($form(), false);
		await flushPromises();

		expect(refreshCaptcha).toHaveBeenCalledTimes(1);
		expect(refreshCaptcha.mock.calls[0][0][0]).toBe($form()[0]);
		expect(refreshCaptcha.mock.calls[0][1]).toBe(data);
		expect(window.FFC.overrideConfirm.mock.calls[0][0].reasons).toEqual([{ code: 'closed' }]);
		// Declined: the button comes back.
		expect($btn().data('submitting')).toBe(false);
		expect($btn().prop('disabled')).toBe(false);
		expect(message()).toBe('');
	});

	it('asks with no reasons when the server listed none', async () => {
		window.FFC.overrideConfirm = vi.fn().mockReturnValue(new Promise(() => {}));
		window.FFC.request.mockRejectedValueOnce(serverError({ code: 'override_confirmation_required' }));

		window.ffcCalendarFrontend.sendBooking($form(), false);
		await flushPromises();

		expect(refreshCaptcha).not.toHaveBeenCalled();
		expect(window.FFC.overrideConfirm.mock.calls[0][0].reasons).toEqual([]);
		expect($btn().data('submitting')).toBe(true);
	});

	it('shows the server message and refreshes the captcha on a server error, even once confirmed', async () => {
		$btn().prop('disabled', true).text('Loading…');
		window.FFC.request.mockRejectedValueOnce(
			serverError({ code: 'override_confirmation_required', refresh_captcha: true }, 'Slot taken.')
		);

		window.ffcCalendarFrontend.sendBooking($form(), true);
		await flushPromises();

		expect(window.FFC.request.mock.calls[0][1]).toContain('confirm_override=1');
		expect(refreshCaptcha).toHaveBeenCalledTimes(1);
		expect(message()).toBe('Slot taken.');
		expect(document.querySelector('.ffc-form-messages .ffc-notice-danger').getAttribute('role')).toBe('note');
		expect($btn().prop('disabled')).toBe(false);
		expect($btn().text()).toBe('Book Appointment');
	});

	it('falls back to the generic error for a server error without a message', async () => {
		window.FFC.request.mockRejectedValueOnce(serverError({ code: 'x' }));

		window.ffcCalendarFrontend.sendBooking($form(), false);
		await flushPromises();

		expect(refreshCaptcha).not.toHaveBeenCalled();
		expect(message()).toBe('An error occurred');
	});

	it.each([
		['a timeout', { xhr: { statusText: 'timeout', status: 0 } }, 'Connection timeout. Please try again.'],
		['no connection', { xhr: { statusText: 'error', status: 0 } }, 'Network error. Please check your connection.'],
		['a transport failure', { xhr: { statusText: 'error', status: 500 } }, 'An error occurred'],
		['an error with no request', {}, 'An error occurred'],
	])('names %s', async (_label, err, expected) => {
		window.FFC.request.mockRejectedValueOnce(err);

		window.ffcCalendarFrontend.sendBooking($form(), false);
		await flushPromises();

		expect(message()).toBe(expected);
		expect($btn().prop('disabled')).toBe(false);
	});

	it('uses the built-in timeout and network texts when none is localised', async () => {
		delete window.ffcCalendar.strings.timeout;
		delete window.ffcCalendar.strings.networkError;
		delete window.ffcCalendar.strings.submit;
		window.FFC.request
			.mockRejectedValueOnce({ xhr: { statusText: 'timeout' } })
			.mockRejectedValueOnce({ xhr: { status: 0 } });

		window.ffcCalendarFrontend.sendBooking($form(), false);
		await flushPromises();
		expect(message()).toBe('Connection timeout. Please try again.');
		expect($btn().text()).toBe('Book Appointment');

		window.ffcCalendarFrontend.sendBooking($form(), false);
		await flushPromises();
		expect(message()).toBe('Network error. Please check your connection and try again.');
	});
});

describe('ffc-calendar-frontend.js — loadTimeSlots without a slot list', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		document.body.innerHTML = MODAL_FIXTURE;
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	it('says there are no slots when the payload carries none', async () => {
		window.FFC.request.mockResolvedValue({});

		window.ffcCalendarFrontend.loadTimeSlots(7, '2026-05-21');
		expect(document.querySelector('.ffc-modal-title').textContent).toBe('Available Times — 21 May 2026');
		await flushPromises();

		expect(document.querySelector('#ffc-timeslots-container .ffc-no-slots').textContent).toBe('No slots available');
		expect(document.querySelectorAll('#ffc-timeslots-container .ffc-timeslot')).toHaveLength(0);
	});
});

describe('ffc-calendar-frontend.js — initCalendarCore wiring', () => {
	let options;
	let instances;
	beforeEach(async () => {
		reset();
		installGlobals();
		window.FFC.request.mockReturnValue(new Promise(() => {}));
		instances = [];
		window.FFCCalendarCore = function ($container, opts) {
			options = opts;
			this.$container = $container;
			this.options = opts;
			this.refresh = vi.fn();
			instances.push(this);
		};
		document.body.innerHTML =
			'<input id="ffc-selected-date" />' +
			'<div id="ffc-calendar-container-7"><div class="ffc-calendar-filters"></div></div>' +
			'<script type="application/json" id="ffc-calendar-config-7">{"calendarId":7,"workingDays":[1,2,3,4,5]}</script>';
		loadScript('assets/js/ffc-calendar-frontend.js');
		// Let document-ready's own initCalendarCore() run before the test does.
		await tick();
		options = null;
		instances = [];
	});

	function setConfig(config) {
		document.getElementById('ffc-calendar-config-7').textContent = JSON.stringify(config);
	}

	function ymd(d) {
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}

	// A weekday a few days ahead, inside the default 30-day window.
	function nextWeekday() {
		const d = new Date();
		d.setHours(12, 0, 0, 0);
		do {
			d.setDate(d.getDate() + 1);
		} while (d.getDay() === 0 || d.getDay() === 6);
		return d;
	}

	function deferred() {
		let resolve;
		let reject;
		const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
		return { promise, resolve, reject };
	}

	it('builds nothing without a config tag, or with a config that is not JSON', () => {
		document.getElementById('ffc-calendar-config-7').textContent = '{not json';
		window.ffcCalendarFrontend.initCalendarCore();
		expect(instances).toHaveLength(0);

		document.getElementById('ffc-calendar-config-7').remove();
		window.ffcCalendarFrontend.initCalendarCore();
		expect(instances).toHaveLength(0);
	});

	it('disables the non-working weekdays, defaults the window to 30 days and stores the instance', () => {
		window.ffcCalendarFrontend.initCalendarCore();

		expect(instances).toHaveLength(1);
		expect(options.disabledDays).toEqual([0, 6]);
		expect(options.showFilters).toBe(false);
		const expectedMax = new Date();
		expectedMax.setDate(expectedMax.getDate() + 30);
		expect(ymd(options.maxDate)).toBe(ymd(expectedMax));
		expect(options.maxDate.getHours()).toBe(23);
		expect(window.$('#ffc-calendar-container-7').data('calendar')).toBe(instances[0]);
		expect(window.ffcCalendarFrontend.config.calendarId).toBe(7);
	});

	it('pushes the first bookable day out by the minimum notice', () => {
		setConfig({ calendarId: 7, workingDays: [1, 2, 3, 4, 5], minDateHours: 48 });
		const expected = new Date();
		expected.setHours(expected.getHours() + 48);
		expected.setHours(0, 0, 0, 0);

		window.ffcCalendarFrontend.initCalendarCore();

		expect(options.minDate.getTime()).toBe(expected.getTime());
	});

	it('in custom mode disables no weekday and extends the range to the last configured date (#941)', () => {
		setConfig({ calendarId: 7, workingDays: [1], scheduleType: 'custom', enabledDates: ['2026-12-01', '2027-03-15'] });
		window.ffcCalendarFrontend.initCalendarCore();

		expect(options.disabledDays).toEqual([]);
		expect(options.enabledDates).toEqual(['2026-12-01', '2027-03-15']);
		expect(options.maxDate.getTime()).toBe(new Date('2027-03-15T23:59:59').getTime());
	});

	it('keeps the default range when the last custom date cannot be read', () => {
		setConfig({ calendarId: 7, workingDays: [1], scheduleType: 'custom', enabledDates: ['not-a-date'] });
		window.ffcCalendarFrontend.initCalendarCore();

		const expectedMax = new Date();
		expectedMax.setDate(expectedMax.getDate() + 30);
		expect(ymd(options.maxDate)).toBe(ymd(expectedMax));
	});

	it('prints the calendar title, escaped, in the filters area', () => {
		setConfig({ calendarId: 7, workingDays: [1], calendarTitle: 'Room <b>A</b>' });
		window.ffcCalendarFrontend.initCalendarCore();

		expect(options.showFilters).toBe(true);
		const title = document.querySelector('#ffc-calendar-container-7 .ffc-calendar-filters .ffc-calendar-title');
		expect(title.textContent).toBe('Room <b>A</b>');
		expect(title.querySelector('b')).toBeNull();
	});

	it('opens the slots for a clicked day', () => {
		window.ffcCalendarFrontend.initCalendarCore();
		const load = vi.spyOn(window.ffcCalendarFrontend, 'loadTimeSlots').mockImplementation(() => {});

		options.onDayClick('2026-06-10', null);

		expect(document.getElementById('ffc-selected-date').value).toBe('2026-06-10');
		expect(load).toHaveBeenCalledWith(7, '2026-06-10');
	});

	it('marks a working day in the window as available, and not a weekend or a past day', () => {
		window.ffcCalendarFrontend.initCalendarCore();
		const day = nextWeekday();
		const sunday = new Date(day);
		while (sunday.getDay() !== 0) {
			sunday.setDate(sunday.getDate() + 1);
		}
		const past = new Date(2020, 0, 6);

		expect(options.getDayClasses(ymd(day), day)).toEqual(['ffc-available']);
		expect(options.getDayClasses(ymd(sunday), sunday)).toEqual([]);
		expect(options.getDayClasses(ymd(past), past)).toEqual([]);
	});

	it('fetches the month, then draws booking counts and holidays and refreshes', async () => {
		window.ffcCalendar.strings.closedDateKinds = { optional: { label: 'Optional holiday', short: 'Opt.' } };
		window.ffcCalendarFrontend.initCalendarCore();
		const day = nextWeekday();
		const holiday = new Date(day);
		holiday.setDate(holiday.getDate() + 7);
		const other = new Date(day);
		other.setDate(other.getDate() + 14);
		const d = deferred();
		window.FFC.request.mockReturnValueOnce(d.promise);

		options.onMonthChange(2026, 6);
		expect(window.FFC.request).toHaveBeenCalledWith(
			'ffc_get_month_bookings',
			{ calendar_id: 7, year: 2026, month: 6 },
			{ nonce: 'test-nonce', ajaxUrl: '/wp-admin/admin-ajax.php' }
		);

		const holidays = { [ymd(holiday)]: 'Founders', [ymd(other)]: 'Feast' };
		d.resolve({
			counts: { [ymd(day)]: 2, '2026-06-11': 1 },
			holidays,
			holiday_kinds: { [ymd(holiday)]: 'optional' },
		});
		await flushPromises();

		expect(instances[0].refresh).toHaveBeenCalledTimes(1);
		expect(instances[0].options.holidays).toBe(holidays);
		expect(options.getDayContent(ymd(day), day, false, false)).toBe('<span class="ffc-day-badge ffc-badge-bookings">2 bookings</span>');
		expect(options.getDayContent('2026-06-11', new Date(2026, 5, 11), false, false)).toContain('1 booking<');
		// A fetched holiday is no longer available, even on a working day.
		expect(options.getDayClasses(ymd(holiday), holiday)).toEqual([]);

		const $optional = window.$('<div>').html(options.getDayContent(ymd(holiday), holiday, true, false)).find('.ffc-day-badge');
		expect($optional.hasClass('ffc-badge-optional-holiday')).toBe(true);
		expect($optional.attr('aria-label')).toBe('Optional holiday');
		expect($optional.text()).toBe('Opt.');

		const $plain = window.$('<div>').html(options.getDayContent(ymd(other), other, true, false)).find('.ffc-day-badge');
		expect($plain.hasClass('ffc-badge-optional-holiday')).toBe(false);
		expect($plain.attr('title')).toBe('Holiday');
		expect($plain.text()).toBe('Holiday');
	});

	it('uses the calendar labels for the counts', async () => {
		setConfig({ calendarId: 7, workingDays: [1], labels: { booking: 'sessão', bookings: 'sessões' } });
		window.ffcCalendarFrontend.initCalendarCore();
		window.FFC.request.mockResolvedValueOnce({ counts: { '2026-06-10': 1, '2026-06-11': 3 } });

		options.onMonthChange(2026, 6);
		await flushPromises();

		expect(options.getDayContent('2026-06-10', new Date(2026, 5, 10), false, false)).toContain('1 sessão');
		expect(options.getDayContent('2026-06-11', new Date(2026, 5, 11), false, false)).toContain('3 sessões');
	});

	it('drops a stale month answer and still refreshes when the fetch fails', async () => {
		window.ffcCalendarFrontend.initCalendarCore();
		const first = deferred();
		const second = deferred();
		window.FFC.request.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);

		options.onMonthChange(2026, 6);
		options.onMonthChange(2026, 7);
		second.resolve({ counts: { '2026-07-01': 4 } });
		await flushPromises();
		first.resolve({ counts: { '2026-07-01': 9 } });
		await flushPromises();

		expect(instances[0].refresh).toHaveBeenCalledTimes(1);
		expect(options.getDayContent('2026-07-01', new Date(2026, 6, 1), false, false)).toContain('4 bookings');

		const third = deferred();
		const fourth = deferred();
		window.FFC.request.mockReturnValueOnce(third.promise).mockReturnValueOnce(fourth.promise);
		options.onMonthChange(2026, 8);
		options.onMonthChange(2026, 9);
		third.reject(new Error('stale'));
		await flushPromises();
		expect(instances[0].refresh).toHaveBeenCalledTimes(1);
		fourth.reject(new Error('down'));
		await flushPromises();
		expect(instances[0].refresh).toHaveBeenCalledTimes(2);
		// The stale counts were cleared at fetch time.
		expect(options.getDayContent('2026-07-01', new Date(2026, 6, 1), false, false)).toBe('');
	});
});

// The free-slots panel (#2405): built from the month read, never naming
// anyone but the visitor, and following the month navigation.
describe('ffc-calendar-frontend.js — the free-slots panel (#2405)', () => {
	const panelHtml =
		'<div id="ffc-event-list-panel"><div class="ffc-event-list-header"><h3>Free Slots</h3></div>' +
		'<div class="ffc-event-list-content" id="ffc-event-list-content"></div></div>';
	const strings = () => Object.assign({}, window.ffcCalendar.strings, {
		panelHeading: 'Vagas — %s',
		panelMine: 'Seus agendamentos',
		panelNone: 'Nenhuma vaga.',
		panelFreeOne: '1 vaga',
		panelFreeMany: '%d vagas',
		panelTimesOne: '1 horário',
		panelTimesMany: '%d horários',
		confirmed: 'Confirmado',
		pendingApproval: 'Pendente',
	});
	const items = () => Array.from(document.querySelectorAll('#ffc-event-list-content > *')).map((el) => el.textContent);

	beforeEach(() => {
		reset();
		installGlobals();
		document.body.innerHTML = panelHtml;
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	it('names the month and lists the days with free places and the holidays, in date order', () => {
		window.ffcCalendarFrontend.renderPanel(
			2032,
			3,
			{ days: { '2032-03-15': { free: 1, slots: 1 }, '2032-03-08': { free: 3, slots: 2 } }, mine: [] },
			{ '2032-03-10': 'Feriado <b>', '2032-04-01': 'Not this month' },
			strings()
		);

		expect(document.querySelector('#ffc-event-list-panel h3').textContent).toBe('Vagas — March 2032');
		expect(items()).toEqual([
			'8 March 2032', '3 vagas2 horários',
			'10 March 2032', 'Feriado <b>',
			'15 March 2032', '1 vaga1 horário',
		]);
		expect(document.querySelector('#ffc-event-list-content b')).toBeNull();
	});

	it("shows the visitor's own bookings first, with their status", () => {
		window.ffcCalendarFrontend.renderPanel(
			2032,
			3,
			{ days: {}, mine: [{ date: '2032-03-08', start: '09:00', end: '09:30', status: 'pending' }] },
			{},
			strings()
		);

		expect(items()).toEqual(['Seus agendamentos', '8 March 2032 · 09:00–09:30Pendente']);
	});

	it('says so when the month has nothing to list', () => {
		window.ffcCalendarFrontend.renderPanel(2032, 3, null, {}, strings());

		expect(items()).toEqual(['Nenhuma vaga.']);
	});

	it('is filled on each month change when the calendar shows it, and only then', async () => {
		let options = null;
		window.FFCCalendarCore = function (_$container, opts) {
			options = opts;
			this.options = opts;
			this.refresh = () => {};
		};
		window.FFC.request.mockResolvedValue({
			counts: {},
			holidays: {},
			panel: { days: { '2032-03-08': { free: 2, slots: 1 } }, mine: [] },
		});
		document.body.innerHTML = panelHtml +
			'<div id="ffc-calendar-container-7"></div>' +
			'<script type="application/json" id="ffc-calendar-config-7">{"calendarId":7,"workingDays":[1],"showPanel":true}</script>';
		window.ffcCalendarFrontend.initCalendarCore();

		options.onMonthChange(2032, 3);
		await Promise.resolve();
		await Promise.resolve();

		expect(document.querySelector('#ffc-event-list-panel h3').textContent).toContain('March 2032');
		expect(document.querySelectorAll('#ffc-event-list-content .ffc-event-list-free').length).toBe(1);

		const spy = vi.spyOn(window.ffcCalendarFrontend, 'renderPanel');
		document.getElementById('ffc-calendar-config-7').textContent = '{"calendarId":7,"workingDays":[1]}';
		window.ffcCalendarFrontend.initCalendarCore();
		options.onMonthChange(2032, 4);
		await Promise.resolve();
		await Promise.resolve();
		expect(spy).not.toHaveBeenCalled();
	});
});

// The session step (#2405): on a calendar with sessions a day lists them
// first; choosing one loads its times, and the booking carries it.
describe('ffc-calendar-frontend.js — the session step (#2405)', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		Object.assign(window.ffcCalendar.strings, {
			chooseSession: 'Choose a session',
			changeSession: 'Change session',
			session: 'Session',
			sessionFull: 'Full',
			panelFreeOne: '1 free place',
			panelFreeMany: '%d free places',
		});
		document.body.innerHTML = `
			<div id="ffc-self-scheduling-modal" style="display:none;">
				<div class="ffc-modal-content"><div class="ffc-modal-title"></div></div>
				<div class="ffc-timeslots-wrapper">
					<div class="ffc-timeslots-loading" style="display:none;">Loading</div>
					<div id="ffc-session-step" hidden></div>
					<div id="ffc-timeslots-container"></div>
				</div>
				<div class="ffc-booking-form-wrapper" style="display:none;">
					<input id="ffc-form-date"><input id="ffc-form-time"><input id="ffc-form-session">
				</div>
			</div>
		`;
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	const settle = async () => {
		await Promise.resolve();
		await Promise.resolve();
	};

	it('lists the sessions with their free places, a full one disabled, before any time', async () => {
		window.FFC.request.mockResolvedValue({
			sessions: [
				{ id: 1, name: 'Room <A>', free: 3, slots: 2 },
				{ id: 2, name: 'Room B', free: 0, slots: 0 },
			],
		});

		window.ffcCalendarFrontend.loadTimeSlots(7, '2032-03-08');
		await settle();

		const choices = document.querySelectorAll('#ffc-session-step .ffc-session-choice');
		expect(document.getElementById('ffc-session-step').hidden).toBe(false);
		expect(choices).toHaveLength(2);
		expect(choices[0].textContent).toContain('Room <A> — 3 free places');
		expect(choices[0].querySelector('b')).toBeNull();
		expect(choices[1].textContent).toContain('Full');
		expect(choices[1].disabled).toBe(true);
		expect(document.querySelectorAll('#ffc-timeslots-container .ffc-timeslot')).toHaveLength(0);
	});

	it('loads the chosen session times, with a way back, and books in it', async () => {
		window.FFC.request.mockResolvedValueOnce({ sessions: [{ id: 4, name: 'Room A', free: 1, slots: 1 }] });
		window.ffcCalendarFrontend.loadTimeSlots(7, '2032-03-08');
		await settle();

		window.FFC.request.mockResolvedValueOnce({ slots: [{ time: '09:00', display: '09:00', available: 1, total: 1 }] });
		document.querySelector('.ffc-session-choice').click();
		await settle();

		expect(window.FFC.request.mock.calls[1][1]).toEqual({ calendar_id: 7, date: '2032-03-08', session_id: 4 });
		expect(document.querySelectorAll('#ffc-timeslots-container .ffc-timeslot')).toHaveLength(1);
		expect(document.querySelector('#ffc-session-step').textContent).toContain('Session: Room A');
		expect(document.querySelector('#ffc-session-step .ffc-session-back')).not.toBeNull();

		window.ffcCalendarFrontend.selectedTime = '09:00';
		window.ffcCalendarFrontend.showBookingForm();
		expect(document.getElementById('ffc-form-session').value).toBe('4');

		window.FFC.request.mockResolvedValueOnce({ sessions: [{ id: 4, name: 'Room A', free: 1, slots: 1 }] });
		document.querySelector('.ffc-session-back').click();
		await settle();
		expect(window.FFC.request.mock.calls[2][1]).toEqual({ calendar_id: 7, date: '2032-03-08' });
		expect(window.ffcCalendarFrontend.selectedSession).toBeNull();
	});

	it('names the step with the calendar\'s own word for a session (#2413)', async () => {
		window.ffcCalendarFrontend.config = { labels: { chooseSession: 'Rooms', changeSession: 'Rooms', session: 'Room' } };
		window.FFC.request.mockResolvedValueOnce({ sessions: [{ id: 4, name: 'Room A', free: 1, slots: 1 }] });
		window.ffcCalendarFrontend.loadTimeSlots(7, '2032-03-08');
		await settle();
		expect(document.querySelector('#ffc-session-step .ffc-session-step-title').textContent).toBe('Rooms');

		window.FFC.request.mockResolvedValueOnce({ slots: [{ time: '09:00', display: '09:00', available: 1, total: 1 }] });
		document.querySelector('.ffc-session-choice').click();
		await settle();
		expect(document.querySelector('#ffc-session-step').textContent).toContain('Room: Room A');
		expect(document.querySelector('#ffc-session-step .ffc-session-back').textContent).toContain('Rooms');
		window.ffcCalendarFrontend.config = undefined;
	});

	it('leaves a calendar without sessions as it was', async () => {
		window.FFC.request.mockResolvedValue({ slots: [{ time: '09:00', display: '09:00', available: 1, total: 1 }] });

		window.ffcCalendarFrontend.loadTimeSlots(7, '2032-03-08');
		await settle();
		window.ffcCalendarFrontend.selectedTime = '09:00';
		window.ffcCalendarFrontend.showBookingForm();

		expect(document.getElementById('ffc-session-step').hidden).toBe(true);
		expect(document.getElementById('ffc-form-session').value).toBe('');
	});
});

describe('ffc-calendar-frontend.js — the panel with sessions (#2405)', () => {
	beforeEach(() => {
		reset();
		installGlobals();
		document.body.innerHTML = '<div id="ffc-event-list-panel"><div class="ffc-event-list-header"><h3></h3></div><div id="ffc-event-list-content"></div></div>';
		loadScript('assets/js/ffc-calendar-frontend.js');
	});

	it("lists each session's places and names the visitor's session", () => {
		const strings = Object.assign({}, window.ffcCalendar.strings, { panelFreeMany: '%d free', panelFreeOne: '1 free', panelTimesMany: '%d times', panelTimesOne: '1 time', sessionFull: 'Full', confirmed: 'Confirmed' });
		window.ffcCalendarFrontend.renderPanel(2032, 3, {
			days: { '2032-03-08': { free: 3, slots: 2, sessions: [{ name: 'Room A', free: 3, slots: 2 }, { name: 'Room B', free: 0, slots: 0 }] } },
			mine: [{ date: '2032-03-08', start: '09:00', end: '09:30', status: 'confirmed', session: 'Room A' }],
		}, {}, strings);

		const items = Array.from(document.querySelectorAll('#ffc-event-list-content .ffc-event-list-item')).map((el) => el.textContent);
		expect(items).toEqual(['8 March 2032 · 09:00–09:30Room A · Confirmed', 'Room A · 3 free2 times', 'Room B · Full']);
	});
});
