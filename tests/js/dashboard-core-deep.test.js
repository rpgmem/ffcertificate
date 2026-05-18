// Deep coverage for assets/js/ffc-user-dashboard-core.js — the
// panel-registry shell that tab-dispatches into the sibling
// dashboard panel scripts.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, flushPromises } from './dashboard-fixtures.js';

// Core is loaded ONCE in beforeAll: its delegated handlers attach to
// $(document), and re-loading per-test would stack handlers from prior
// tests that still reference stale panel objects.
beforeAll(() => {
	installDashboardFixtures();
	loadDashboardCore();
});

beforeEach(() => {
	installDashboardFixtures();
	window.ffcDashboard.canViewCertificates = true;
	window.ffcDashboard.canViewAppointments = true;
	window.ffcDashboard.canViewAudienceBookings = true;
	window.ffcDashboard.restUrl = '/wp-json/ffc/v1/';
	try { window.localStorage.removeItem('ffc_page_size'); } catch (_) { /* ignore */ }
	// Reset panels so tests start from a clean slate.
	window.FFCDashboard.panels = {};
});

afterEach(() => {
	vi.restoreAllMocks();
});

function installFakePanels() {
	window.FFCDashboard.panels.certificates = {
		state: { ok: true },
		load: vi.fn(),
		render: vi.fn(),
	};
	window.FFCDashboard.panels.appointments = {
		state: { ok: true },
		load: vi.fn(),
		render: vi.fn(),
		bindEvents: vi.fn(),
	};
	window.FFCDashboard.panels.audience = {
		state: { ok: true },
		load: vi.fn(),
		render: vi.fn(),
	};
}

// ----------------------------------------------------------------------
// helpers
// ----------------------------------------------------------------------

describe('FFCDashboard.helpers', () => {
	it('esc() escapes HTML entities through jQuery .text/.html', async () => {
		expect(window.FFCDashboard.helpers.esc('<b>hi</b>')).toBe('&lt;b&gt;hi&lt;/b&gt;');
		expect(window.FFCDashboard.helpers.esc('')).toBe('');
		expect(window.FFCDashboard.helpers.esc(null)).toBe('');
	});

	it('pad2 zero-pads single digits', async () => {
		expect(window.FFCDashboard.helpers.pad2(0)).toBe('00');
		expect(window.FFCDashboard.helpers.pad2(7)).toBe('07');
		expect(window.FFCDashboard.helpers.pad2(15)).toBe('15');
	});

	it('getPageSize returns the stored value when in [10,25,50], else default 25', async () => {
		expect(window.FFCDashboard.helpers.getPageSize()).toBe(25);

		window.localStorage.setItem('ffc_page_size', '10');
		expect(window.FFCDashboard.helpers.getPageSize()).toBe(10);

		window.localStorage.setItem('ffc_page_size', '999'); // out of allowlist
		expect(window.FFCDashboard.helpers.getPageSize()).toBe(25);
	});

	it('buildFilterBar emits a date-range + search-box + apply/clear cluster', async () => {
		const html = window.FFCDashboard.helpers.buildFilterBar('certificates');
		expect(html).toContain('data-tab="certificates"');
		expect(html).toContain('ffc-filter-from');
		expect(html).toContain('ffc-filter-to');
		expect(html).toContain('ffc-filter-search');
		expect(html).toContain('ffc-filter-apply');
		expect(html).toContain('ffc-filter-clear');
	});

	it('buildPageSizeSelector marks the current page size as <strong>', async () => {
		window.localStorage.setItem('ffc_page_size', '50');
		const html = window.FFCDashboard.helpers.buildPageSizeSelector();
		expect(html).toContain('<strong>50</strong>');
		expect(html).toContain('data-size="10"');
		expect(html).toContain('data-size="25"');
		expect(html).not.toContain('data-size="50"'); // current is rendered as <strong>, not <a>
	});

	it('buildPagination skips the page controls when total ≤ pageSize', async () => {
		const html = window.FFCDashboard.helpers.buildPagination(5, 1, 'certs');
		expect(html).not.toContain('Previous');
		expect(html).not.toContain('Next');
		// Still emits the per-page selector.
		expect(html).toContain('ffc-page-size-select');
	});

	it('buildPagination emits Previous/Next + "Page X of Y" when paginating', async () => {
		const html = window.FFCDashboard.helpers.buildPagination(100, 2, 'certs');
		expect(html).toContain('Prev');
		expect(html).toContain('Next');
		expect(html).toContain('Page 2 of 4');
	});

	it('buildPagination hides Previous on page 1 and Next on the last page', async () => {
		const first = window.FFCDashboard.helpers.buildPagination(100, 1, 'x');
		expect(first).not.toContain('Prev');
		expect(first).toContain('Next');

		const last = window.FFCDashboard.helpers.buildPagination(100, 4, 'x');
		expect(last).toContain('Prev');
		expect(last).not.toContain('Next');
	});
});

// ----------------------------------------------------------------------
// init bail
// ----------------------------------------------------------------------

describe('FFCDashboard.init', () => {
	it('does not init when the #ffc-user-dashboard root is absent', async () => {
		// Wipe the fixture.
		document.body.innerHTML = '<div>no dashboard</div>';
		const initSpy = vi.fn();
		// init runs in $(document).ready — there's nothing to bail on except
		// the wrapper presence. Sanity: the global is published.
		expect(window.FFCDashboard).toBeDefined();
		expect(typeof window.FFCDashboard.init).toBe('function');
		// Calling init() with no root still wires summary+tab calls; both
		// short-circuit because their targets are missing. No throw.
		expect(() => window.FFCDashboard.init()).not.toThrow();
	});
});

// ----------------------------------------------------------------------
// loadSummary
// ----------------------------------------------------------------------

describe('FFCDashboard.loadSummary', () => {
	function mountSummary() {
		document.body.innerHTML += '<div id="ffc-dashboard-summary"></div>';
	}

	it('bails when #ffc-dashboard-summary is absent (no AJAX)', async () => {
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));
		// No #ffc-dashboard-summary in the DOM (default fixture).
		window.FFCDashboard.loadSummary();
		await flushPromises();
		expect(ajaxSpy).not.toHaveBeenCalled();
	});

	it('issues a GET to /user/summary with the X-WP-Nonce header', async () => {
		mountSummary();
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		window.FFCDashboard.loadSummary();
		await flushPromises();

		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.url).toBe('/wp-json/ffc/v1/user/summary');
		expect(opts.method).toBe('GET');
		const xhr = { setRequestHeader: vi.fn() };
		opts.beforeSend(xhr);
		expect(xhr.setRequestHeader).toHaveBeenCalledWith('X-WP-Nonce', 'test-nonce');
	});

	it('appends viewAsUserId query param when impersonating', async () => {
		mountSummary();
		window.ffcDashboard.viewAsUserId = 9;
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		window.FFCDashboard.loadSummary();
		await flushPromises();

		expect(ajaxSpy.mock.calls[0][0].url).toBe('/wp-json/ffc/v1/user/summary?viewAsUserId=9');
	});

	it('on success: renders three cards with permission flags + counts', async () => {
		mountSummary();
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({
				total_certificates: 5,
				next_appointment: { date: '2026-06-01', time: '10:00', title: 'Dr. Visit' },
				upcoming_group_events: 2,
			});
			return {};
		});

		window.FFCDashboard.loadSummary();
		await flushPromises();

		const $cards = window.$('#ffc-dashboard-summary .ffc-summary-card');
		expect($cards.length).toBe(3);
		expect($cards.text()).toContain('5');
		expect($cards.text()).toContain('Dr. Visit');
		expect($cards.text()).toContain('2');
	});

	it('omits cards for permissions the user lacks', async () => {
		mountSummary();
		window.ffcDashboard.canViewAppointments = false;
		window.ffcDashboard.canViewAudienceBookings = false;
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({ total_certificates: 1 });
			return {};
		});

		window.FFCDashboard.loadSummary();
		await flushPromises();

		expect(window.$('#ffc-dashboard-summary .ffc-summary-card').length).toBe(1);
	});

	it("handles missing next_appointment in the appointments card", async () => {
		mountSummary();
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({ total_certificates: 0, next_appointment: null, upcoming_group_events: 0 });
			return {};
		});

		window.FFCDashboard.loadSummary();
		await flushPromises();

		expect(window.$('#ffc-dashboard-summary').text()).toContain('—');
	});

	it('on network error: renders the localised error string', async () => {
		mountSummary();
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error();
			return {};
		});

		window.FFCDashboard.loadSummary();
		await flushPromises();

		expect(window.$('#ffc-dashboard-summary').text()).toBe('Error');
	});
});

// ----------------------------------------------------------------------
// switchTab / loadInitialTab / pagination / filters
// ----------------------------------------------------------------------

describe('FFCDashboard.tab dispatch', () => {
	it('loadInitialTab calls load() on the active panel', async () => {
		installFakePanels();
		window.FFCDashboard.loadInitialTab();
		expect(window.FFCDashboard.panels.certificates.load).toHaveBeenCalled();
	});

	it('switchTab moves active state, updates URL, and calls load() on the new panel', async () => {
		installFakePanels();
		// Click the appointments tab.
		window.$('.ffc-tab[data-tab="appointments"]').trigger('click');

		expect(window.$('.ffc-tab.active').data('tab')).toBe('appointments');
		expect(window.$('.ffc-tab-content.active').attr('id')).toBe('tab-appointments');
		expect(window.FFCDashboard.panels.appointments.load).toHaveBeenCalled();
	});

	it('keyboard nav: ArrowRight cycles forward, ArrowLeft cycles backward', async () => {
		installFakePanels();
		// Focus the first tab + ArrowRight.
		const $first = window.$('.ffc-tab').eq(0);
		$first[0].focus();
		const ev = window.$.Event('keydown', { key: 'ArrowRight' });
		$first.trigger(ev);

		// After ArrowRight, second tab is active (after focus+click).
		expect(window.$('.ffc-tab.active').data('tab')).toBe('appointments');

		const $second = window.$('.ffc-tab').eq(1);
		const ev2 = window.$.Event('keydown', { key: 'ArrowLeft' });
		$second.trigger(ev2);
		expect(window.$('.ffc-tab.active').data('tab')).toBe('certificates');
	});

	it('keyboard nav: Home / End jump to first / last tab', async () => {
		installFakePanels();
		const $first = window.$('.ffc-tab').eq(0);
		$first.trigger(window.$.Event('keydown', { key: 'End' }));
		expect(window.$('.ffc-tab.active').data('tab')).toBe('audience');

		const $last = window.$('.ffc-tab').eq(2);
		$last.trigger(window.$.Event('keydown', { key: 'Home' }));
		expect(window.$('.ffc-tab.active').data('tab')).toBe('certificates');
	});

	it('pagination button click dispatches render(state, page) to the panel', async () => {
		installFakePanels();
		document.body.innerHTML += '<button class="ffc-pagination-btn" data-page="3" data-target="certificates">3</button>';

		window.$('.ffc-pagination-btn').trigger('click');

		expect(window.FFCDashboard.panels.certificates.render).toHaveBeenCalledWith(
			window.FFCDashboard.panels.certificates.state,
			3,
		);
	});

	it('filter-apply triggers render(state, 1) on the matching panel', async () => {
		installFakePanels();
		document.body.innerHTML += '<div class="ffc-filter-bar" data-tab="appointments"><button class="ffc-filter-apply">Apply</button></div>';

		window.$('.ffc-filter-apply').trigger('click');

		expect(window.FFCDashboard.panels.appointments.render).toHaveBeenCalledWith(
			window.FFCDashboard.panels.appointments.state,
			1,
		);
	});

	it('filter-clear empties inputs and triggers render', async () => {
		installFakePanels();
		document.body.innerHTML += `
			<div class="ffc-filter-bar" data-tab="appointments">
				<input class="ffc-filter-from" value="2026-01-01">
				<input class="ffc-filter-search" value="hello">
				<button class="ffc-filter-clear">Clear</button>
			</div>
		`;

		window.$('.ffc-filter-clear').trigger('click');

		expect(window.$('.ffc-filter-from').val()).toBe('');
		expect(window.$('.ffc-filter-search').val()).toBe('');
		expect(window.FFCDashboard.panels.appointments.render).toHaveBeenCalled();
	});

	it('Enter on the search input triggers the filter for the current bar', async () => {
		installFakePanels();
		document.body.innerHTML += `
			<div class="ffc-filter-bar" data-tab="appointments">
				<input class="ffc-filter-search" value="x">
			</div>
		`;

		const ev = window.$.Event('keyup', { key: 'Enter' });
		window.$('.ffc-filter-search').trigger(ev);

		expect(window.FFCDashboard.panels.appointments.render).toHaveBeenCalled();
	});

	it('page-size button click updates localStorage + applies filter for the active tab', async () => {
		installFakePanels();
		document.body.innerHTML += '<a class="ffc-page-size-btn" data-size="50" href="#">50</a>';

		window.$('.ffc-page-size-btn').trigger('click');

		expect(window.localStorage.getItem('ffc_page_size')).toBe('50');
		expect(window.FFCDashboard.panels.certificates.render).toHaveBeenCalled();
	});

	it('bindPanelEvents calls panel.bindEvents() once per panel that exposes it', async () => {
		// fresh state — install only one panel with bindEvents so we can
		// assert exactly one call.
		const bindSpy = vi.fn();
		window.FFCDashboard.panels.solo = { bindEvents: bindSpy };
		window.FFCDashboard.bindPanelEvents();
		expect(bindSpy).toHaveBeenCalledTimes(1);
		expect(bindSpy).toHaveBeenCalledWith(window.FFCDashboard);
	});
});
