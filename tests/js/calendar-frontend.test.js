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
