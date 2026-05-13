// Sprint F1 — deep coverage for ffc-certificates-dashboard.js (218 LOC,
// previously 31.19%). `certificates-dashboard.test.js` already covers
// the three bail paths and the FFCCalendarCore instantiation smoke.
// This file drives the side-effect helpers that the calendar options
// resolve to: fetchMonth (success / race / error), getDayContent,
// getDayClasses (geofence / fallback / both / none), renderSideList
// (empty / sorted / edit_url / no edit_url / non-publish status),
// formatDateLabel (round-trip), and the onMonthChange / onDayClick
// glue.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function mountFixture() {
	document.body.innerHTML = `
		<div id="ffc-certificates-calendar"></div>
		<ul id="ffc-certificates-day-list"></ul>
		<p class="ffc-certificates-side-empty" style="display:none">Empty</p>
		<h3 class="ffc-certificates-side-title">Forms</h3>
	`;
}

// Captured options from the FFCCalendarCore mock — every test reaches in
// through this object to drive the IIFE's private helpers.
let capturedOpts = null;
let calendarRefreshSpy = null;

function installCalendarMock() {
	calendarRefreshSpy = vi.fn();
	window.FFCCalendarCore = vi.fn(function (_container, opts) {
		capturedOpts = opts;
		return { refresh: calendarRefreshSpy };
	});
}

beforeAll(() => {
	window.$.fx.off = true;
});

beforeEach(async () => {
	capturedOpts = null;
	calendarRefreshSpy = null;
	window.ffcCertificatesDashboard = {
		restUrl: '/wp-json/ffc/v1/',
		nonce: 'cert-nonce',
		i18n: {
			noFormsForDay: 'No forms today.',
			sourceGeofence: 'GeoFence',
			sourcePostDate: 'Publication date',
			legendGeofence: 'GeoFence start',
			legendFallback: 'Publication date',
		},
	};
	installCalendarMock();
	mountFixture();
	loadScript('assets/js/ffc-certificates-dashboard.js');
	// jQuery 4 defers $(fn) to a microtask — wait for it so the calendar
	// is instantiated before the test body runs.
	await new Promise((r) => setTimeout(r, 0));
});

afterEach(() => {
	vi.restoreAllMocks();
});

// ----------------------------------------------------------------------
// Calendar option wiring
// ----------------------------------------------------------------------

describe('certificates-dashboard — calendar wiring', () => {
	it('passes legend entries + showLegend/showTodayButton/showFilters', () => {
		expect(capturedOpts.showLegend).toBe(true);
		expect(capturedOpts.showTodayButton).toBe(true);
		expect(capturedOpts.showFilters).toBe(false);
		expect(capturedOpts.legendItems).toEqual([
			{ class: 'ffc-cert-has-geofence', label: 'GeoFence start' },
			{ class: 'ffc-cert-has-fallback', label: 'Publication date' },
		]);
	});
});

// ----------------------------------------------------------------------
// onMonthChange → fetchMonth
// ----------------------------------------------------------------------

describe('certificates-dashboard — fetchMonth', () => {
	it('issues a GET to /certificates/calendar?year=Y&month=M with the X-WP-Nonce header', () => {
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({
			done: () => ({}),
		}));

		capturedOpts.onMonthChange(2026, 6);

		expect(ajaxSpy).toHaveBeenCalled();
		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.url).toBe('/wp-json/ffc/v1/certificates/calendar?year=2026&month=6');
		expect(opts.method).toBe('GET');

		// Simulate the WP header send.
		const xhr = { setRequestHeader: vi.fn() };
		opts.beforeSend(xhr);
		expect(xhr.setRequestHeader).toHaveBeenCalledWith('X-WP-Nonce', 'cert-nonce');
	});

	it('on success: stores entries keyed by date and refreshes the calendar', () => {
		// Capture `done` callback.
		let doneCb;
		vi.spyOn(window.$, 'ajax').mockImplementation(() => ({
			done: (cb) => {
				doneCb = cb;
				return {};
			},
		}));

		capturedOpts.onMonthChange(2026, 6);
		// First refresh fires immediately (resets stale month).
		expect(calendarRefreshSpy).toHaveBeenCalledTimes(1);

		doneCb([
			{ date: '2026-06-05', source: 'geofence', title: 'Form A', id: 1, status: 'publish' },
			{ date: '2026-06-05', source: 'postdate', title: 'Form B', id: 2, status: 'draft' },
			{ date: '2026-06-12', source: 'geofence', title: 'Form C', id: 3, status: 'publish' },
		]);

		// Second refresh after the response writes.
		expect(calendarRefreshSpy).toHaveBeenCalledTimes(2);

		// Day content + classes now reflect the entries.
		expect(capturedOpts.getDayContent('2026-06-05')).toContain('2');
		expect(capturedOpts.getDayContent('2026-06-12')).toContain('1');
		expect(capturedOpts.getDayClasses('2026-06-05').sort()).toEqual([
			'ffc-cert-day',
			'ffc-cert-has-fallback',
			'ffc-cert-has-geofence',
		]);
	});

	it('races: a later fetch invalidates an earlier in-flight response', () => {
		const doneCbs = [];
		vi.spyOn(window.$, 'ajax').mockImplementation(() => ({
			done: (cb) => {
				doneCbs.push(cb);
				return {};
			},
		}));

		// Two fetches in flight.
		capturedOpts.onMonthChange(2026, 6);
		capturedOpts.onMonthChange(2026, 7);

		// The OLD fetch resolves first — its entries should be dropped.
		doneCbs[0]([
			{ date: '2026-06-05', source: 'geofence', title: 'Stale', id: 99 },
		]);
		expect(capturedOpts.getDayContent('2026-06-05')).toBe('');

		// Then the NEW fetch resolves — its entries should land.
		doneCbs[1]([
			{ date: '2026-07-10', source: 'geofence', title: 'Fresh', id: 100 },
		]);
		expect(capturedOpts.getDayContent('2026-07-10')).toContain('1');
	});

	it('non-array responses are ignored without throwing', () => {
		let doneCb;
		vi.spyOn(window.$, 'ajax').mockImplementation(() => ({
			done: (cb) => {
				doneCb = cb;
				return {};
			},
		}));

		capturedOpts.onMonthChange(2026, 6);
		expect(() => doneCb({ unexpected: 'shape' })).not.toThrow();
		expect(capturedOpts.getDayContent('2026-06-05')).toBe('');
	});

	it('skips response entries with no `date` field', () => {
		let doneCb;
		vi.spyOn(window.$, 'ajax').mockImplementation(() => ({
			done: (cb) => {
				doneCb = cb;
				return {};
			},
		}));

		capturedOpts.onMonthChange(2026, 6);
		doneCb([
			{ source: 'geofence', title: 'Orphan' }, // no date
			{ date: '2026-06-01', source: 'geofence', title: 'Real' },
		]);

		// Only the entry with date counts.
		expect(capturedOpts.getDayContent('2026-06-01')).toContain('1');
	});
});

// ----------------------------------------------------------------------
// getDayContent / getDayClasses edge branches
// ----------------------------------------------------------------------

describe('certificates-dashboard — day content + classes', () => {
	function seed(entries) {
		let doneCb;
		vi.spyOn(window.$, 'ajax').mockImplementation(() => ({
			done: (cb) => {
				doneCb = cb;
				return {};
			},
		}));
		capturedOpts.onMonthChange(2026, 6);
		doneCb(entries);
	}

	it('returns empty content + empty classes for a date with no entries', () => {
		expect(capturedOpts.getDayContent('2026-06-15')).toBe('');
		expect(capturedOpts.getDayClasses('2026-06-15')).toEqual([]);
	});

	it('emits only the geofence class when every entry is a geofence source', () => {
		seed([
			{ date: '2026-06-01', source: 'geofence', title: 'A' },
			{ date: '2026-06-01', source: 'geofence', title: 'B' },
		]);
		expect(capturedOpts.getDayClasses('2026-06-01')).toEqual([
			'ffc-cert-day',
			'ffc-cert-has-geofence',
		]);
	});

	it('emits only the fallback class when every entry is post-date sourced', () => {
		seed([
			{ date: '2026-06-02', source: 'postdate', title: 'A' },
			{ date: '2026-06-02', source: 'postdate', title: 'B' },
		]);
		expect(capturedOpts.getDayClasses('2026-06-02')).toEqual([
			'ffc-cert-day',
			'ffc-cert-has-fallback',
		]);
	});
});

// ----------------------------------------------------------------------
// onDayClick → renderSideList
// ----------------------------------------------------------------------

describe('certificates-dashboard — renderSideList', () => {
	function seed(entries) {
		let doneCb;
		vi.spyOn(window.$, 'ajax').mockImplementation(() => ({
			done: (cb) => {
				doneCb = cb;
				return {};
			},
		}));
		capturedOpts.onMonthChange(2026, 6);
		doneCb(entries);
	}

	it('shows the empty-state message when the day has no entries', () => {
		capturedOpts.onDayClick('2026-06-20');
		expect(window.$('#ffc-certificates-day-list').attr('hidden')).toBe('hidden');
		expect(window.$('.ffc-certificates-side-empty').text()).toBe('No forms today.');
		expect(window.$('.ffc-certificates-side-empty').css('display')).not.toBe('none');
	});

	it('renders one <li> per entry, sorted by title', () => {
		seed([
			{ date: '2026-06-05', source: 'geofence', title: 'Zulu', id: 99, edit_url: '/edit/99', status: 'publish' },
			{ date: '2026-06-05', source: 'postdate', title: 'Alpha', id: 100, edit_url: '/edit/100', status: 'publish' },
			{ date: '2026-06-05', source: 'geofence', title: 'Mike', id: 101, edit_url: '/edit/101', status: 'publish' },
		]);
		capturedOpts.onDayClick('2026-06-05');

		const $items = window.$('#ffc-certificates-day-list .ffc-certificates-day-item');
		expect($items.length).toBe(3);
		const titles = $items.find('.ffc-certificates-day-title').map((_, el) => el.textContent).get();
		expect(titles).toEqual(['Alpha', 'Mike', 'Zulu']);
	});

	it('renders a link when entry has edit_url, plain span otherwise', () => {
		// renderSideList sorts entries by title; pick names that keep the
		// "linked" entry first after sort so the index assertions are stable.
		seed([
			{ date: '2026-06-05', source: 'geofence', title: 'A-Linked', id: 1, edit_url: '/edit/1', status: 'publish' },
			{ date: '2026-06-05', source: 'geofence', title: 'B-Plain', id: 2, status: 'publish' },
		]);
		capturedOpts.onDayClick('2026-06-05');

		const $titles = window.$('.ffc-certificates-day-title');
		expect($titles.eq(0).is('a')).toBe(true);
		expect($titles.eq(0).attr('href')).toBe('/edit/1');
		expect($titles.eq(1).is('a')).toBe(false);
	});

	it('appends the status label only when status !== "publish"', () => {
		// Pick names that keep "publish" entry first after the title sort.
		seed([
			{ date: '2026-06-05', source: 'geofence', title: 'A-Pub', id: 1, status: 'publish' },
			{ date: '2026-06-05', source: 'geofence', title: 'B-Drft', id: 2, status: 'draft' },
		]);
		capturedOpts.onDayClick('2026-06-05');

		const $items = window.$('#ffc-certificates-day-list .ffc-certificates-day-item');
		expect($items.eq(0).find('.ffc-certificates-status').length).toBe(0);
		expect($items.eq(1).find('.ffc-certificates-status').text()).toBe('(draft)');
	});

	it('attaches the source badge with is-geofence / is-fallback classes', () => {
		seed([
			{ date: '2026-06-05', source: 'geofence', title: 'A', id: 1, status: 'publish' },
			{ date: '2026-06-05', source: 'postdate', title: 'B', id: 2, status: 'publish' },
		]);
		capturedOpts.onDayClick('2026-06-05');

		const $badges = window.$('.ffc-certificates-source-badge');
		expect($badges.eq(0).hasClass('is-geofence')).toBe(true);
		expect($badges.eq(0).text()).toBe('GeoFence');
		expect($badges.eq(1).hasClass('is-fallback')).toBe(true);
		expect($badges.eq(1).text()).toBe('Publication date');
	});

	it('updates the side title with the localised label of the selected date', () => {
		capturedOpts.onDayClick('2026-06-05');
		expect(window.$('.ffc-certificates-side-title').text()).toContain('Forms');
		// The date format depends on locale; just confirm the title was
		// extended with the " — " separator that formatDateLabel injects.
		expect(window.$('.ffc-certificates-side-title').text()).toContain(' — ');
	});

	it('falls back to "#id" as title when entry.title is missing', () => {
		seed([
			{ date: '2026-06-05', source: 'geofence', id: 42, edit_url: '/edit/42', status: 'publish' },
		]);
		capturedOpts.onDayClick('2026-06-05');
		expect(window.$('.ffc-certificates-day-title').text()).toBe('#42');
	});
});
