// The dashboard's shared resilience helpers (#2246): browser storage that may
// throw, the message region that replaced alert(), "No results" with its way
// out, the one session-expired notice, and Back restoring the tab.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { installDashboardFixtures, loadDashboardCore } from './dashboard-fixtures.js';

beforeAll(() => {
	installDashboardFixtures();
	loadDashboardCore();
	// init() runs only when the real root is on the page; the bindings
	// under test are wired here once, as init() would.
	window.FFCDashboard.bindEvents();
	window.FFCDashboard.bindHistory();
	window.FFCDashboard.bindSession();
});

beforeEach(() => {
	installDashboardFixtures();
	window.ffcDashboard.restUrl = '/wp-json/ffc/v1/';
	window.ffcDashboard.strings = Object.assign({}, window.ffcDashboard.strings, {
		noResults: 'Nothing matches',
		clearFilters: 'Clear filters',
		sessionExpired: 'Session over',
		reloadPage: 'Reload page',
		filterSearchLabel: 'Search this list',
	});
	window.FFCDashboard.panels = {};
});

afterEach(() => {
	vi.restoreAllMocks();
	window.history.replaceState({}, '', '/');
});

const helpers = () => window.FFCDashboard.helpers;

describe('storage', () => {
	it('falls back to the default page size when reading storage throws', () => {
		vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('SecurityError'); });
		expect(helpers().storage.get('ffc_page_size')).toBeNull();
		expect(helpers().getPageSize()).toBe(25);
	});

	it('swallows a write that throws', () => {
		vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('QuotaExceededError'); });
		expect(() => helpers().storage.set('ffc_page_size', 10)).not.toThrow();
	});

	it('reads back what it stored', () => {
		helpers().storage.set('ffc_page_size', 50);
		expect(helpers().getPageSize()).toBe(50);
		window.localStorage.removeItem('ffc_page_size');
	});
});

describe('flash()', () => {
	it('shows the message in one region, escaped, replacing the previous one', () => {
		helpers().flash('success', 'First');
		helpers().flash('danger', '<img src=x onerror=alert(1)>');
		const regions = document.querySelectorAll('#ffc-dashboard-flash');
		expect(regions.length).toBe(1);
		expect(regions[0].textContent).toBe('<img src=x onerror=alert(1)>');
		expect(regions[0].querySelector('img')).toBeNull();
		expect(regions[0].querySelector('.ffc-notice').getAttribute('role')).toBe('alert');
	});

	it('announces anything but an error politely', () => {
		helpers().flash('info', 'Joined');
		expect(document.querySelector('#ffc-dashboard-flash .ffc-notice').getAttribute('role')).toBe('status');
	});

	it('sits above the summary when the dashboard has one', () => {
		document.body.innerHTML = '<div id="ffc-user-dashboard"><div id="ffc-dashboard-summary"></div></div>';
		helpers().flash('info', 'Hi');
		expect(document.getElementById('ffc-dashboard-summary').previousElementSibling.id).toBe('ffc-dashboard-flash');
	});
});

describe('errorMessage()', () => {
	it('prefers the server message, then the fallback', () => {
		expect(helpers().errorMessage({ xhr: { responseJSON: { message: 'Quota' } } })).toBe('Quota');
		expect(helpers().errorMessage({ fromServer: true, message: 'Refused' })).toBe('Refused');
		expect(helpers().errorMessage({}, 'Fallback')).toBe('Fallback');
	});
});

describe('classSlug()', () => {
	it('keeps only what a class may hold', () => {
		expect(helpers().classSlug('confirmed')).toBe('confirmed');
		expect(helpers().classSlug('x" onmouseover="y')).toBe('xonmouseovery');
		expect(helpers().classSlug(null)).toBe('');
	});
});

describe('filter bar and "No results"', () => {
	it('labels the search for assistive technology', () => {
		const html = helpers().buildFilterBar('certificates');
		expect(html).toContain('aria-label="Search this list"');
	});

	it('clears its tab\'s filter and re-renders from the "No results" button', () => {
		const render = vi.fn();
		window.FFCDashboard.panels.certificates = { state: [], render };
		document.getElementById('ffc-tabpanel-certificates').innerHTML =
			helpers().buildFilterBar('certificates') + helpers().buildNoResults('certificates');
		document.querySelector('.ffc-filter-search').value = 'zzz';

		const btn = document.querySelector('.ffc-empty-filtered .ffc-filter-clear');
		expect(document.querySelector('.ffc-empty-filtered').textContent).toContain('Nothing matches');
		window.$(btn).trigger('click');

		expect(document.querySelector('.ffc-filter-search').value).toBe('');
		expect(render).toHaveBeenCalledWith([], 1);
	});
});

describe('session expiry', () => {
	it('recognises a 401 and core\'s stale nonce, and nothing else', () => {
		const d = window.FFCDashboard;
		expect(d.isSessionError({ status: 401 })).toBe(true);
		expect(d.isSessionError({ status: 403, responseJSON: { code: 'rest_cookie_invalid_nonce' } })).toBe(true);
		expect(d.isSessionError({ status: 403, responseJSON: { code: 'capability_denied' } })).toBe(false);
		expect(d.isSessionError(null)).toBe(false);
	});

	it('says it once, with a reload button, whatever the number of failures', () => {
		const xhr = { status: 401 };
		window.$(document).trigger('ajaxError', [xhr, { url: '/wp-json/ffc/v1/user/summary' }]);
		window.$(document).trigger('ajaxError', [xhr, { url: '/wp-json/ffc/v1/user/certificates' }]);

		const notices = document.querySelectorAll('#ffc-dashboard-session');
		expect(notices.length).toBe(1);
		expect(notices[0].textContent).toContain('Session over');
		expect(notices[0].querySelector('.ffc-session-reload')).not.toBeNull();
	});

	it('ignores a failure that is not the dashboard\'s REST', () => {
		window.$(document).trigger('ajaxError', [{ status: 401 }, { url: '/somewhere/else' }]);
		expect(document.getElementById('ffc-dashboard-session')).toBeNull();
	});
});

describe('history', () => {
	it('records a tab switch and restores the tab on Back without recording again', () => {
		const loadCerts = vi.fn();
		const loadAppts = vi.fn();
		window.FFCDashboard.panels.certificates = { load: loadCerts };
		window.FFCDashboard.panels.appointments = { load: loadAppts };
		const push = vi.spyOn(window.history, 'pushState');

		window.FFCDashboard.activateTab(window.$('.ffc-tab[data-tab="appointments"]'), true);
		expect(push).toHaveBeenCalledTimes(1);
		expect(new URL(window.location).searchParams.get('tab')).toBe('appointments');

		window.history.replaceState({}, '', '/?tab=certificates');
		window.dispatchEvent(new PopStateEvent('popstate'));

		expect(document.querySelector('.ffc-tab[data-tab="certificates"]').classList.contains('is-active')).toBe(true);
		expect(document.getElementById('ffc-tabpanel-certificates').classList.contains('is-active')).toBe(true);
		expect(loadCerts).toHaveBeenCalled();
		expect(push).toHaveBeenCalledTimes(1);
	});
});
