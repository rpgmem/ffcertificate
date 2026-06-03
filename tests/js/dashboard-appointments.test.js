// Render tests for the Appointments panel
// (assets/js/ffc-user-dashboard-appointments.js).
//
// The panel reads from #tab-appointments and writes a filter bar + one
// table per section (upcoming / past / cancelled). Tests cover: empty
// state, sectioning by status, row counts, receipt-button visibility,
// and the cancelled-row CSS class.
//
// Part of S4 of #163.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel } from './dashboard-fixtures.js';

beforeAll(() => {
	installDashboardFixtures();
	// Add a few i18n keys the appointments panel uses on top of the
	// default fixture set so the section headers come out readable.
	Object.assign(window.ffcDashboard.strings, {
		upcoming: 'Upcoming',
		past: 'Past',
		calendar: 'Calendar',
		time: 'Time',
		viewReceipt: 'View Receipt',
		cancelAppointment: 'Cancel',
	});
	loadDashboardCore();
	loadPanel('cal-export'); // dependency — appointments uses FFCDashboard.calExport.buildButton
	loadPanel('appointments');
});

beforeEach(() => {
	document.getElementById('tab-appointments').innerHTML = '';
	window.localStorage.setItem('ffc_page_size', '25');
});

const panel = () => window.FFCDashboard.panels.appointments;

const FAR_PAST   = '2000-01-01';
const FAR_FUTURE = '2999-12-31';

function makeAppt(over = {}) {
	return Object.assign({
		calendar_title: 'My calendar',
		appointment_date: '01/01/2999',
		appointment_date_raw: FAR_FUTURE,
		start_time: '10:00',
		end_time: '11:00',
		status: 'confirmed',
		status_label: 'Confirmed',
		receipt_url: '',
		id: Math.random(),
	}, over);
}

describe('FFCDashboard.panels.appointments.render', () => {
	it('renders the empty state when there are no appointments', () => {
		panel().render([], 1);
		const container = document.getElementById('tab-appointments');
		expect(container.querySelector('.ffc-empty-state')).not.toBeNull();
		expect(container.textContent).toContain('No appointments');
		expect(container.querySelector('table')).toBeNull();
	});

	it('renders three sections (upcoming + past + cancelled) when all three exist', () => {
		const items = [
			makeAppt({ status: 'confirmed', appointment_date_raw: FAR_FUTURE }),
			makeAppt({ status: 'completed', appointment_date_raw: FAR_PAST }),
			makeAppt({ status: 'cancelled', appointment_date_raw: FAR_FUTURE }),
		];
		panel().render(items, 1);
		const headers = document.querySelectorAll('#tab-appointments h3');
		expect(headers.length).toBe(3);
		const labels = Array.from(headers).map((h) => h.textContent);
		expect(labels).toEqual(['Upcoming', 'Past', 'Cancelled']);
	});

	it('omits sections that have no items', () => {
		// Only upcoming → only one section.
		panel().render([makeAppt(), makeAppt()], 1);
		expect(document.querySelectorAll('#tab-appointments h3').length).toBe(1);
		expect(document.querySelector('#tab-appointments h3').textContent).toBe('Upcoming');
	});

	it("applies 'cancelled-row' / 'past-row' classes to the right rows", () => {
		panel().render([
			makeAppt({ status: 'completed', appointment_date_raw: FAR_PAST, calendar_title: 'past one' }),
			makeAppt({ status: 'cancelled', calendar_title: 'cancelled one' }),
		], 1);
		expect(document.querySelectorAll('#tab-appointments tr.past-row').length).toBe(1);
		expect(document.querySelectorAll('#tab-appointments tr.cancelled-row').length).toBe(1);
	});

	it('renders the receipt button when receipt_url is set, omits it otherwise', () => {
		panel().render([
			makeAppt({ receipt_url: 'https://x.test/receipt/1' }),
			makeAppt({ receipt_url: '' }),
		], 1);
		const buttons = document.querySelectorAll('#tab-appointments .ffc-btn-receipt');
		expect(buttons.length).toBe(1);
		expect(buttons[0].getAttribute('href')).toBe('https://x.test/receipt/1');
	});

	it('escapes HTML in the calendar_title cell so injected markup cannot execute', () => {
		panel().render([makeAppt({ calendar_title: '<img src=x onerror=alert(1)>' })], 1);
		const container = document.getElementById('tab-appointments');
		expect(container.querySelector('img')).toBeNull();
		expect(container.textContent).toContain('<img src=x onerror=alert(1)>');
	});

	it('keeps a quote-breakout receipt_url inside the href and sets rel=noopener', () => {
		panel().render([makeAppt({ receipt_url: 'https://x.test/"><img src=x onerror=alert(1)>' })], 1);
		const buttons = document.querySelectorAll('#tab-appointments .ffc-btn-receipt');
		// escAttr() neutralises the closing quote: one anchor, no smuggled <img>.
		expect(buttons.length).toBe(1);
		expect(document.querySelector('#tab-appointments td img')).toBeNull();
	});

	it('adds rel="noopener noreferrer" to the target=_blank receipt link', () => {
		panel().render([makeAppt({ receipt_url: 'https://x.test/receipt/1' })], 1);
		const link = document.querySelector('#tab-appointments .ffc-btn-receipt');
		expect(link.getAttribute('target')).toBe('_blank');
		expect(link.getAttribute('rel')).toBe('noopener noreferrer');
	});

	it('attaches the appointment-status class with the status value', () => {
		panel().render([
			makeAppt({ status: 'confirmed', status_label: 'Confirmed' }),
			makeAppt({ status: 'completed', status_label: 'Done', appointment_date_raw: FAR_PAST }),
		], 1);
		expect(document.querySelectorAll('#tab-appointments .appointment-status.status-confirmed').length).toBe(1);
		expect(document.querySelectorAll('#tab-appointments .appointment-status.status-completed').length).toBe(1);
	});
});
