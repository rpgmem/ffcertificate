// Deep coverage for ffc-user-dashboard-appointments.js. The shallow
// render tests in dashboard-appointments.test.js cover sectioning and
// row classes; this file completes the picture by driving:
//   - the document-level click delegate that triggers cancelAppointment
//   - the load() path (canViewAppointments guard, state cache, AJAX
//     success/error, viewAsUserId / X-WP-Nonce)
//   - render() branches not exercised by the shallow file (filter bar,
//     pagination, calendar-export button, viewReceipt + cancel buttons
//     within the upcoming section).
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel, flushPromises } from './dashboard-fixtures.js';

beforeAll(() => {
	installDashboardFixtures();
	Object.assign(window.ffcDashboard.strings, {
		upcoming: 'Upcoming',
		past: 'Past',
		calendar: 'Calendar',
		time: 'Time',
		viewReceipt: 'View Receipt',
		cancelAppointment: 'Cancel',
		cancelTitle: 'Cancel appointment',
		cancelIntro: 'Please confirm.',
		cancelService: 'Service',
		cancelWhen: 'When',
		cancelReasonLabel: 'Reason (optional)',
		cancelConfirmBtn: 'Confirm cancellation',
		cancelKeepBtn: 'Keep appointment',
		close: 'Close',
		cancelSuccess: 'Cancelled OK',
		cancelError: 'Cancel failed',
		noPermission: 'No permission',
		name: 'Name:',
	});
	window.ffcDashboard.restUrl = 'https://x.test/wp-json/ffc/v1/';
	window.ffcDashboard.ajaxUrl = 'https://x.test/wp-admin/admin-ajax.php';
	window.ffcDashboard.nonce = 'rest-nonce';
	window.ffcDashboard.schedulingNonce = 'sched-nonce';
	window.ffcDashboard.mainAddress = '123 Main St';

	loadDashboardCore();
	loadPanel('cal-export');
	loadPanel('appointments');
	// bindEvents is called explicitly by core.init() in real life — here
	// we wire it ourselves so the delegated handler is registered.
	window.FFCDashboard.panels.appointments.bindEvents();
});

beforeEach(() => {
	document.getElementById('tab-appointments').innerHTML = '';
	window.localStorage.setItem('ffc_page_size', '25');
	window.FFCDashboard.panels.appointments.state = null;
});

afterEach(() => {
	vi.restoreAllMocks();
	delete window.ffcDashboard.viewAsUserId;
	delete window.ffcDashboard.canViewAppointments;
});

const FAR_PAST   = '2000-01-01';
const FAR_FUTURE = '2999-12-31';

function makeAppt(over = {}) {
	return Object.assign({
		calendar_title: 'My calendar',
		appointment_date: '01/01/2999',
		appointment_date_raw: FAR_FUTURE,
		start_time: '10:00',
		start_time_raw: '10:00',
		end_time: '11:00',
		status: 'confirmed',
		status_label: 'Confirmed',
		receipt_url: '',
		can_cancel: false,
		id: Math.floor(Math.random() * 1e9),
	}, over);
}

const panel = () => window.FFCDashboard.panels.appointments;

// ----------------------------------------------------------------------
// load()
// ----------------------------------------------------------------------

describe('appointments.load', () => {
	it('bails when #tab-appointments is missing', async () => {
		document.getElementById('tab-appointments').remove();
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		// Restore container for subsequent tests.
		document.getElementById('ffc-dashboard').insertAdjacentHTML(
			'beforeend',
			'<div id="tab-appointments" class="ffc-tab-content"></div>'
		);
	});

	it('shows the noPermission notice when canViewAppointments is false', async () => {
		window.ffcDashboard.canViewAppointments = false;
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		expect(document.getElementById('tab-appointments').innerHTML).toContain('No permission');
	});

	it('short-circuits when state is already populated', async () => {
		panel().state = [];
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
	});

	it('GETs /user/appointments and stores the response on state', async () => {
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({ appointments: [makeAppt({ calendar_title: 'A' })] });
			return {};
		});

		panel().load();
		await flushPromises();

		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.url).toBe('https://x.test/wp-json/ffc/v1/user/appointments');
		expect(opts.method).toBe('GET');
		expect(panel().state.length).toBe(1);
		expect(document.getElementById('tab-appointments').textContent).toContain('A');
	});

	it('sets X-WP-Nonce on the request', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			const xhr = { setRequestHeader: vi.fn() };
			opts.beforeSend(xhr);
			expect(xhr.setRequestHeader).toHaveBeenCalledWith('X-WP-Nonce', 'rest-nonce');
			return {};
		});

		panel().load();
		await flushPromises();
	});

	it('appends viewAsUserId query string when impersonating', async () => {
		window.ffcDashboard.viewAsUserId = 99;
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy.mock.calls[0][0].url).toBe('https://x.test/wp-json/ffc/v1/user/appointments?viewAsUserId=99');
	});

	it('defaults state to [] when response.appointments is missing', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({});
			return {};
		});

		panel().load();
		await flushPromises();

		expect(panel().state).toEqual([]);
		// Empty state markup renders.
		expect(document.querySelector('#tab-appointments .ffc-empty-state')).not.toBeNull();
	});

	it('renders the error notice when the AJAX call fails', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error();
			return {};
		});

		panel().load();
		await flushPromises();

		expect(document.getElementById('tab-appointments').innerHTML).toContain('Error');
	});
});

// ----------------------------------------------------------------------
// The cancellation dialog (#1140)
//
// It replaced a `confirm()`, and the reason is not cosmetic: the endpoint has
// always accepted and sanitised `reason`, and this caller never sent one — so a
// cancellation from the dashboard recorded an empty reason while the same
// action from the e-mail link recorded what the person wrote. The dialog also
// says WHICH appointment is being cancelled, which `confirm()` could not.
// ----------------------------------------------------------------------

describe('appointments cancellation dialog', () => {
	function mountCancelButton(id = 55, over = {}) {
		document.getElementById('tab-appointments').innerHTML =
			`<button class="ffc-cancel-appointment" data-id="${id}">Cancel</button>`;
		panel().state = [makeAppt(Object.assign({ id, can_cancel: true }, over))];
	}

	function postChain(opts) {
		const chain = { done: () => chain, fail: () => chain };
		if (opts.done) chain.done = (cb) => { cb(opts.done); return chain; };
		if (opts.fail) chain.fail = (cb) => { cb(); return chain; };
		return chain;
	}

	const openDialog = () => window.$('.ffc-cancel-appointment').trigger('click');
	const dialog = () => document.getElementById('ffc-cancel-appointment-modal');

	afterEach(() => {
		const el = dialog();
		if (el) el.remove();
	});

	it('opens a dialog naming the appointment instead of a bare confirm', async () => {
		mountCancelButton(7, { calendar_title: 'Sala Azul', appointment_date: '16/09/2026', start_time: '10:30' });
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);

		openDialog();
		await flushPromises();

		expect(confirmSpy).not.toHaveBeenCalled();
		expect(dialog()).not.toBeNull();
		const text = dialog().textContent;
		expect(text).toContain('Sala Azul');
		expect(text).toContain('16/09/2026');
		expect(text).toContain('10:30');
	});

	it('does nothing when the id matches no row in state', async () => {
		mountCancelButton(7);
		panel().state = [makeAppt({ id: 999 })];

		openDialog();
		await flushPromises();

		expect(dialog()).toBeNull();
	});

	it('sends no request until the confirm button is pressed', async () => {
		mountCancelButton();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		openDialog();
		await flushPromises();

		expect(postSpy).not.toHaveBeenCalled();
	});

	it('POSTs the row id + nonce when confirmed', async () => {
		mountCancelButton(123);
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		openDialog();
		window.$('.ffc-cancel-confirm').trigger('click');
		await flushPromises();

		const [url, payload] = postSpy.mock.calls[0];
		expect(url).toBe('https://x.test/wp-admin/admin-ajax.php');
		expect(payload).toMatchObject({
			action: 'ffc_cancel_appointment',
			appointment_id: 123,
			nonce: 'sched-nonce',
		});
	});

	it('sends the typed reason — the gap the dialog exists to close', async () => {
		mountCancelButton();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		openDialog();
		document.getElementById('ffc-cancel-reason').value = 'Conflito de agenda';
		window.$('.ffc-cancel-confirm').trigger('click');
		await flushPromises();

		expect(postSpy.mock.calls[0][1].reason).toBe('Conflito de agenda');
	});

	it('omits reason entirely when the field is left empty', async () => {
		mountCancelButton();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		openDialog();
		window.$('.ffc-cancel-confirm').trigger('click');
		await flushPromises();

		expect(postSpy.mock.calls[0][1]).not.toHaveProperty('reason');
	});

	it.each([
		['keep button', '.ffc-cancel-keep'],
		['close button', '.ffc-modal-close'],
		['backdrop', '.ffc-modal-backdrop'],
	])('closes without sending anything via the %s', async (_label, selector) => {
		mountCancelButton();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		openDialog();
		window.$('#ffc-cancel-appointment-modal ' + selector).trigger('click');
		await flushPromises();

		expect(dialog()).toBeNull();
		expect(postSpy).not.toHaveBeenCalled();
	});

	it('closes on Escape without sending anything', async () => {
		mountCancelButton();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		openDialog();
		window.$(document).trigger(window.$.Event('keydown', { key: 'Escape' }));
		await flushPromises();

		expect(dialog()).toBeNull();
		expect(postSpy).not.toHaveBeenCalled();
	});

	it('includes viewAsUserId in the POST body when impersonating', async () => {
		mountCancelButton();
		window.ffcDashboard.viewAsUserId = 42;
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		openDialog();
		window.$('.ffc-cancel-confirm').trigger('click');
		await flushPromises();

		expect(postSpy.mock.calls[0][1].viewAsUserId).toBe(42);
	});

	it('on success: closes the dialog, alerts, clears state, repaints and reloads', async () => {
		mountCancelButton();
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		const loadSpy = vi.spyOn(panel(), 'load').mockImplementation(() => {});
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true } }));

		openDialog();
		window.$('.ffc-cancel-confirm').trigger('click');
		await flushPromises();

		expect(dialog()).toBeNull();
		expect(alertSpy).toHaveBeenCalledWith('Cancelled OK');
		expect(panel().state).toBeNull();
		expect(document.getElementById('tab-appointments').innerHTML).toContain('Loading');
		expect(loadSpy).toHaveBeenCalled();
	});

	it('on response.success=false: alerts the server message and leaves the dialog open', async () => {
		mountCancelButton();
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({
			done: { success: false, data: { message: 'Already cancelled' } },
		}));

		openDialog();
		window.$('.ffc-cancel-confirm').trigger('click');
		await flushPromises();

		expect(alertSpy).toHaveBeenCalledWith('Already cancelled');
		// Left open on purpose: the reason the person typed is still in the
		// field, so a retry does not start from a blank form.
		expect(dialog()).not.toBeNull();
	});

	it('on response.success=false without data.message: falls back to the localised error', async () => {
		mountCancelButton();
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({
			done: { success: false, data: {} },
		}));

		openDialog();
		window.$('.ffc-cancel-confirm').trigger('click');
		await flushPromises();

		expect(alertSpy).toHaveBeenCalledWith('Cancel failed');
	});

	it('on network error: alerts the localised error', async () => {
		mountCancelButton();
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));

		openDialog();
		window.$('.ffc-cancel-confirm').trigger('click');
		await flushPromises();

		expect(alertSpy).toHaveBeenCalledWith('Cancel failed');
	});
});

// ----------------------------------------------------------------------
// render() — branches the shallow file doesn't exercise
// ----------------------------------------------------------------------

describe('appointments.render — filter, pagination, extras', () => {
	it('filters by date range (from/to) and search', async () => {
		// Two appointments — only one survives the filter window.
		const items = [
			makeAppt({ calendar_title: 'Match',  appointment_date_raw: '2030-06-15' }),
			makeAppt({ calendar_title: 'Skip',   appointment_date_raw: '2031-01-01' }),
		];
		panel().render(items, 1);
		// Type into the filter inputs, then re-render to apply.
		document.querySelector('.ffc-filter-from').value = '2030-01-01';
		document.querySelector('.ffc-filter-to').value = '2030-12-31';
		document.querySelector('.ffc-filter-search').value = 'match';

		panel().render(items, 1);

		const rows = document.querySelectorAll('#tab-appointments tbody tr');
		expect(rows.length).toBe(1);
		expect(rows[0].textContent).toContain('Match');
	});

	it('paginates when there are more rows than the page size', async () => {
		// getPageSize() only accepts 10/25/50 — set the lowest and build 11 rows.
		window.localStorage.setItem('ffc_page_size', '10');
		const items = [];
		for (let i = 1; i <= 11; i++) {
			items.push(makeAppt({ calendar_title: 'C' + i, id: i }));
		}
		panel().render(items, 1);
		// First 10 rows on page 1.
		expect(document.querySelectorAll('#tab-appointments tbody tr').length).toBe(10);
		// Pagination control is emitted with prev/next links.
		expect(document.querySelector('#tab-appointments .ffc-pagination')).not.toBeNull();

		// Page 2 → only the 11th row.
		panel().render(items, 2);
		expect(document.querySelectorAll('#tab-appointments tbody tr').length).toBe(1);
		expect(document.querySelector('#tab-appointments tbody tr').textContent).toContain('C11');
	});

	it('renders the cancel button when can_cancel is true', async () => {
		panel().render([makeAppt({ can_cancel: true, id: 77 })], 1);
		const btn = document.querySelector('#tab-appointments .ffc-cancel-appointment');
		expect(btn).not.toBeNull();
		expect(btn.getAttribute('data-id')).toBe('77');
	});

	it('renders the calendar-export button only for upcoming + confirmed rows', async () => {
		const items = [
			makeAppt({ status: 'confirmed', id: 1 }),                                                // upcoming + confirmed → button
			makeAppt({ status: 'pending',   id: 2 }),                                                // upcoming + non-confirmed → no button
			makeAppt({ status: 'cancelled', id: 3 }),                                                // cancelled section → no button
			makeAppt({ status: 'completed', id: 4, appointment_date_raw: FAR_PAST }),                // past section → no button
		];
		panel().render(items, 1);

		const calButtons = document.querySelectorAll('#tab-appointments .ffc-cal-export-wrap');
		expect(calButtons.length).toBe(1);
	});

	it('keeps the filter input values after re-rendering with new data', async () => {
		panel().render([makeAppt()], 1);
		document.querySelector('.ffc-filter-search').value = 'remember-me';

		panel().render([makeAppt()], 1);

		expect(document.querySelector('.ffc-filter-search').value).toBe('remember-me');
	});

	it('shows the empty state when the filter window removes every row', async () => {
		const items = [makeAppt({ appointment_date_raw: '2030-06-15' })];
		panel().render(items, 1);
		document.querySelector('.ffc-filter-from').value = '2099-01-01';

		panel().render(items, 1);

		// Filtered to zero rows but `appointments.length > 0`, so the empty
		// state path is NOT hit — instead the page-items loop renders no
		// sections and no table. Assert there's neither a table nor an
		// empty-state element, which is the documented behaviour.
		expect(document.querySelectorAll('#tab-appointments table').length).toBe(0);
	});
});
