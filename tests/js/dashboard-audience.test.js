// Render tests for the Audience bookings panel
// (assets/js/ffc-user-dashboard-audience.js).
//
// The panel reads from #tab-audience and writes a filter bar + one
// table per section (upcoming / past / cancelled). Each row carries
// a "audiences" tag list. Tests cover: empty state, audience-tag
// rendering, sectioning, row classes, and filter-by-search.
//
// Part of S4 of #163.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel } from './dashboard-fixtures.js';

beforeAll(() => {
	installDashboardFixtures();
	Object.assign(window.ffcDashboard.strings, {
		upcoming: 'Upcoming',
		past: 'Past',
		environment: 'Environment',
		time: 'Time',
		description: 'Description',
		audiences: 'Audiences',
		noAudienceBookings: 'No audience bookings',
	});
	loadDashboardCore();
	loadPanel('cal-export');
	loadPanel('audience');
});

beforeEach(() => {
	document.getElementById('tab-audience').innerHTML = '';
	window.localStorage.setItem('ffc_page_size', '25');
});

const panel = () => window.FFCDashboard.panels.audience;

function makeBooking(over = {}) {
	return Object.assign({
		id: Math.random(),
		environment_name: 'Main hall',
		schedule_name: '',
		booking_date: '01/01/2999',
		booking_date_raw: '2999-01-01',
		start_time: '10:00',
		end_time: '11:00',
		description: 'A booking',
		is_past: false,
		status: 'confirmed',
		audiences: [],
	}, over);
}

describe('FFCDashboard.panels.audience.render', () => {
	it('renders the empty state when there are no bookings', () => {
		panel().render([], 1);
		const container = document.getElementById('tab-audience');
		expect(container.querySelector('.ffc-empty-state')).not.toBeNull();
		expect(container.textContent).toContain('No audience bookings');
		expect(container.querySelector('table')).toBeNull();
	});

	it('renders one row per booking with environment + description columns', () => {
		panel().render([
			makeBooking({ environment_name: 'Room A', description: 'Yoga' }),
			makeBooking({ environment_name: 'Room B', description: 'Pilates' }),
		], 1);
		const rows = document.querySelectorAll('#tab-audience table tbody tr');
		expect(rows.length).toBe(2);
		expect(rows[0].textContent).toContain('Room A');
		expect(rows[0].textContent).toContain('Yoga');
		expect(rows[1].textContent).toContain('Pilates');
	});

	it('renders an .ffc-audience-tag chip per audience', () => {
		panel().render([
			makeBooking({
				audiences: [
					{ name: 'Adults',   color: '#aa0000' },
					{ name: 'Children', color: '#00aa00' },
					{ name: 'Seniors',  color: '#0000aa' },
				],
			}),
		], 1);
		const tags = document.querySelectorAll('#tab-audience .ffc-audience-tag');
		expect(tags.length).toBe(3);
		expect(tags[0].textContent).toBe('Adults');
		expect(tags[1].textContent).toBe('Children');
	});

	it('splits into Upcoming / Past / Cancelled sections', () => {
		panel().render([
			makeBooking({ is_past: false, status: 'confirmed' }),
			makeBooking({ is_past: true,  status: 'confirmed' }),
			makeBooking({ is_past: false, status: 'cancelled' }),
		], 1);
		const headers = Array.from(document.querySelectorAll('#tab-audience h3')).map((h) => h.textContent);
		expect(headers).toEqual(['Upcoming', 'Past', 'Cancelled']);
	});

	it("applies 'past-row' and 'cancelled-row' classes appropriately", () => {
		panel().render([
			makeBooking({ is_past: true,  status: 'confirmed' }),
			makeBooking({ is_past: false, status: 'cancelled' }),
		], 1);
		expect(document.querySelectorAll('#tab-audience tr.past-row').length).toBe(1);
		expect(document.querySelectorAll('#tab-audience tr.cancelled-row').length).toBe(1);
	});

	it('filters by search query (environment / schedule / description substring)', () => {
		const bookings = [
			makeBooking({ environment_name: 'Alpha hall', description: 'Morning yoga' }),
			makeBooking({ environment_name: 'Beta hall',  description: 'Evening pilates' }),
		];
		// First render to materialise the filter bar.
		panel().render(bookings, 1);
		document.querySelector('#tab-audience .ffc-filter-search').value = 'pilates';
		panel().render(bookings, 1);
		const rows = document.querySelectorAll('#tab-audience table tbody tr');
		expect(rows.length).toBe(1);
		expect(rows[0].textContent).toContain('Beta hall');
	});
});
