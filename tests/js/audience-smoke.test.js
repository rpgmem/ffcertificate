// Sprint B (decision A) — smoke coverage for assets/js/ffc-audience.js
// (1470 LOC, previously 0%).
//
// The file is a monolithic IIFE that wraps a full booking-calendar UI.
// Deep coverage would require recreating most of the production DOM +
// the REST conversation; #167 closed the deep-coverage idea as
// not_planned. This sprint takes decision A from the Sprint A/B/C/D/E
// planning: write the minimal smoke tests that legitimise keeping the
// file in the coverage denominator (vs. excluding it under #167).
//
// What we cover:
//
//   - init() bails when `#ffc-audience-calendar` is absent.
//   - init() runs end-to-end on a minimal fixture without throwing
//     (state parsing, modal portalling, populateAudienceSelect,
//     updateEnvironmentSelect, renderCalendar header text, bindEvents).
//   - Simple delegated handlers fire: prev/next/today buttons mutate
//     state.currentDate and re-render the calendar header.
//   - Schedule + environment selects update state and re-render.
//   - Show-cancelled checkbox is wired (no throw).
//   - ESC keydown handler is wired (no throw).
//
// The deeper booking-flow paths (AJAX validation, conflict check,
// description-counter, audience tree selection) are left out by
// design — the smoke is enough to push the file from 0% to a
// non-trivial number so a regression in init() / bindEvents() shows up.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// The former monolith was split into a core (window.FFCAudience) plus three
// flow modules; load them in dependency order so the namespace is complete
// before $(document).ready fires init().
function loadAudience() {
	loadScript('assets/js/ffc-audience.js');
	loadScript('assets/js/ffc-audience-calendar.js');
	loadScript('assets/js/ffc-audience-bookings.js');
	loadScript('assets/js/ffc-audience-booking-form.js');
}

function fullCalendarFixture() {
	document.body.innerHTML = `
		<div id="ffc-audience-calendar"
			data-config='${JSON.stringify({
				scheduleId: 0,
				environmentId: 0,
				schedules: [
					{ id: 1, name: 'Main', environments: [{ id: 10, name: 'Env A' }] },
				],
				audiences: [
					{ id: 1, name: 'Group A', children: [{ id: 11, name: 'Sub A' }] },
				],
			})}'></div>

		<!-- Calendar surrounding chrome -->
		<button class="ffc-prev-month">←</button>
		<button class="ffc-next-month">→</button>
		<button class="ffc-today-btn">Today</button>
		<span class="ffc-current-month"></span>
		<select id="ffc-schedule-select">
			<option value="0">Any</option>
			<option value="1">Main</option>
		</select>
		<select id="ffc-environment-select"></select>
		<div id="ffc-calendar-days"></div>
		<div id="ffc-event-list-panel">
			<div class="ffc-event-list-header"><h3></h3></div>
		</div>

		<!-- Day modal -->
		<div id="ffc-day-modal" style="display:none">
			<div class="ffc-modal-backdrop"></div>
			<div class="ffc-modal-content">
				<button class="ffc-modal-close">x</button>
				<button class="ffc-modal-cancel">x</button>
				<input type="checkbox" id="ffc-show-cancelled" />
				<button id="ffc-new-booking-btn">+</button>
			</div>
		</div>

		<!-- Booking modal -->
		<div id="ffc-booking-modal" style="display:none">
			<div class="ffc-modal-backdrop"></div>
			<div class="ffc-modal-content">
				<button class="ffc-modal-close">x</button>
				<button class="ffc-modal-cancel">x</button>
				<input type="checkbox" id="booking-all-day" />
				<select id="booking-type">
					<option value="users">Users</option>
					<option value="audiences">Audiences</option>
				</select>
				<select id="booking-audiences" multiple></select>
				<textarea id="booking-description"></textarea>
				<input id="booking-user-search" />
				<div id="booking-user-results"></div>
				<div id="booking-selected-users"></div>
				<button id="ffc-check-conflicts-btn">Check</button>
				<button id="ffc-create-booking-btn">Create</button>
				<input type="checkbox" id="ffc-conflict-acknowledge" />
			</div>
		</div>
	`;
}

beforeAll(() => {
	window.ffcAudience = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		restUrl: '/wp-json/ffc/v1/audience/',
		nonce: 'aud-nonce',
		locale: 'pt_BR',
		strings: {
			allEnvironments: 'All Environments',
			events: 'Events',
		},
	};
	// ffc-audience.js was migrated to FFC.rest / FFC.request — load
	// ffc-core.js so window.FFC is defined when the audience IIFE
	// evaluates inside each test's loadScript() call.
	window.ffc_ajax = {
		ajax_url: window.ffcAudience.ajaxUrl,
		nonce: window.ffcAudience.nonce,
		strings: window.ffcAudience.strings,
	};
	loadScript('assets/js/ffc-core.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.$.fx.off = true;
	// Block REST traffic from fetchMonthData → never call the real network.
	vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
		// Synthesise an empty success response — the script tolerates it
		// (no bookings / no holidays / no closed weekdays).
		if (opts && typeof opts.success === 'function') {
			opts.success({ bookings: [], holidays: [], closed_weekdays: [] });
		}
		return { fail: () => ({}) };
	});
});

afterEach(() => {
	vi.restoreAllMocks();
});

// ----------------------------------------------------------------------
// init bail
// ----------------------------------------------------------------------

describe('ffc-audience — init bail', () => {
	it('does nothing when #ffc-audience-calendar is absent', async () => {
		document.body.innerHTML = '<div>no calendar here</div>';
		expect(() => loadAudience()).not.toThrow();
		await new Promise((r) => setTimeout(r, 0));
	});
});

// ----------------------------------------------------------------------
// Full init run
// ----------------------------------------------------------------------

describe('ffc-audience — init on a minimal fixture', () => {
	it('runs end-to-end without throwing and writes the month header', async () => {
		fullCalendarFixture();
		loadAudience();
		await new Promise((r) => setTimeout(r, 0));

		// renderCalendar populates the current-month header with
		// "<Month name> <YYYY>". The default strings array always has a
		// label for the current month index.
		const headerText = window.$('.ffc-current-month').text();
		expect(headerText.length).toBeGreaterThan(0);

		// The event-list header gets "Events - <Month> <YYYY>".
		expect(window.$('#ffc-event-list-panel h3').text()).toContain('Events - ');

		// Environment select has been populated by updateEnvironmentSelect.
		// With a single schedule + single env in the fixture the script
		// shows just that one env (the "All Environments" placeholder only
		// appears when multiple envs are available across schedules), so
		// we just assert the select is non-empty.
		const envOpts = window
			.$('#ffc-environment-select option')
			.map((_, el) => el.textContent)
			.get();
		expect(envOpts.length).toBeGreaterThanOrEqual(1);
		expect(envOpts).toContain('Env A');
	});

	it('portals the modals to <body> for fixed positioning', async () => {
		fullCalendarFixture();
		// Wrap the modals inside a transformed ancestor to prove they
		// get moved out to body.
		const wrap = document.createElement('div');
		wrap.style.transform = 'translateZ(0)';
		wrap.id = 'ancestor';
		wrap.appendChild(document.getElementById('ffc-booking-modal'));
		wrap.appendChild(document.getElementById('ffc-day-modal'));
		document.body.appendChild(wrap);

		loadAudience();
		await new Promise((r) => setTimeout(r, 0));

		// After init() the modals should be direct children of <body>.
		expect(document.getElementById('ffc-booking-modal').parentElement.tagName).toBe('BODY');
		expect(document.getElementById('ffc-day-modal').parentElement.tagName).toBe('BODY');
	});

	it('converts WP locale (pt_BR) to BCP 47 format (pt-BR) at load time', async () => {
		fullCalendarFixture();
		loadAudience();
		await new Promise((r) => setTimeout(r, 0));

		expect(window.ffcAudience.locale).toBe('pt-BR');
	});
});

// ----------------------------------------------------------------------
// Month navigation handlers
// ----------------------------------------------------------------------

describe('ffc-audience — month navigation', () => {
	async function mountAndLoad() {
		fullCalendarFixture();
		loadAudience();
		await new Promise((r) => setTimeout(r, 0));
	}

	it('prev-month click changes the rendered month header', async () => {
		await mountAndLoad();
		const before = window.$('.ffc-current-month').text();
		window.$('.ffc-prev-month').trigger('click');
		const after = window.$('.ffc-current-month').text();
		// Header changed — we don't lock the month name because jsdom's
		// "current month" depends on Date.now() at test time.
		expect(after).not.toBe(before);
	});

	it('next-month click changes the rendered month header', async () => {
		await mountAndLoad();
		const before = window.$('.ffc-current-month').text();
		window.$('.ffc-next-month').trigger('click');
		const after = window.$('.ffc-current-month').text();
		expect(after).not.toBe(before);
	});

	it('today button resets and re-renders without throwing', async () => {
		await mountAndLoad();
		// Move forward, then click today, and confirm we land on a
		// non-empty header again.
		window.$('.ffc-next-month').trigger('click');
		window.$('.ffc-next-month').trigger('click');
		window.$('.ffc-today-btn').trigger('click');
		expect(window.$('.ffc-current-month').text().length).toBeGreaterThan(0);
	});
});

// ----------------------------------------------------------------------
// Filter selects
// ----------------------------------------------------------------------

describe('ffc-audience — filter selects', () => {
	async function mountAndLoad() {
		fullCalendarFixture();
		loadAudience();
		await new Promise((r) => setTimeout(r, 0));
	}

	it('schedule select change does not throw and triggers a fresh AJAX fetch', async () => {
		await mountAndLoad();
		const ajaxSpy = window.$.ajax;
		const before = ajaxSpy.mock.calls.length;

		window.$('#ffc-schedule-select').val('1').trigger('change');

		expect(ajaxSpy.mock.calls.length).toBeGreaterThan(before);
	});

	it('environment select change does not throw', async () => {
		await mountAndLoad();
		expect(() => {
			window.$('#ffc-environment-select').val('10').trigger('change');
		}).not.toThrow();
	});

	it('show-cancelled checkbox is wired (toggle does not throw)', async () => {
		await mountAndLoad();
		// Seed a date on the modal so the handler does not bail.
		window.$('#ffc-day-modal').data('date', '2026-06-01');
		expect(() => {
			window.$('#ffc-show-cancelled').prop('checked', true).trigger('change');
		}).not.toThrow();
	});
});

// ----------------------------------------------------------------------
// Keyboard + modal close
// ----------------------------------------------------------------------

describe('ffc-audience — modal close + ESC', () => {
	async function mountAndLoad() {
		fullCalendarFixture();
		loadAudience();
		await new Promise((r) => setTimeout(r, 0));
	}

	it('ESC keydown handler is wired (no throw)', async () => {
		await mountAndLoad();
		const ev = window.$.Event('keydown', { key: 'Escape' });
		expect(() => window.$(document).trigger(ev)).not.toThrow();
	});

	it('modal close button hides the booking modal', async () => {
		await mountAndLoad();
		// Force the modal visible first; the close delegate hides it.
		// jQuery's :visible/:hidden need layout (offsetWidth) which jsdom
		// doesn't implement, so we assert directly on display.
		window.$('#ffc-booking-modal').show();
		expect(window.$('#ffc-booking-modal').css('display')).not.toBe('none');

		window.$('#ffc-booking-modal .ffc-modal-close').trigger('click');
		// fadeOut runs synchronously under fx.off.
		expect(window.$('#ffc-booking-modal').css('display')).toBe('none');
	});

	it('clicking the backdrop closes the modal', async () => {
		await mountAndLoad();
		window.$('#ffc-day-modal').show();
		window.$('#ffc-day-modal > .ffc-modal-backdrop').trigger('click');
		expect(window.$('#ffc-day-modal').css('display')).toBe('none');
	});

	it('clicks inside the modal content do not propagate-close (no throw)', async () => {
		await mountAndLoad();
		window.$('#ffc-booking-modal').show();
		expect(() => {
			window.$('#ffc-booking-modal .ffc-modal-content').trigger('click');
		}).not.toThrow();
		// Modal remains open.
		expect(window.$('#ffc-booking-modal').css('display')).not.toBe('none');
	});
});

// ----------------------------------------------------------------------
// Booking modal field handlers (load-side smoke)
// ----------------------------------------------------------------------

describe('ffc-audience — booking modal handlers', () => {
	async function mountAndLoad() {
		fullCalendarFixture();
		loadAudience();
		await new Promise((r) => setTimeout(r, 0));
	}

	it('all-day toggle change does not throw', async () => {
		await mountAndLoad();
		expect(() => {
			window.$('#booking-all-day').prop('checked', true).trigger('change');
		}).not.toThrow();
	});

	it('type select change does not throw', async () => {
		await mountAndLoad();
		expect(() => {
			window.$('#booking-type').val('users').trigger('change');
		}).not.toThrow();
	});

	it('description input does not throw (counter wiring smoke)', async () => {
		await mountAndLoad();
		expect(() => {
			window.$('#booking-description').val('hello world').trigger('input');
		}).not.toThrow();
	});

	it('user-search input does not throw (debounced AJAX wiring smoke)', async () => {
		await mountAndLoad();
		expect(() => {
			window.$('#booking-user-search').val('alice').trigger('input');
		}).not.toThrow();
	});

	it('check-conflicts button click does not throw', async () => {
		await mountAndLoad();
		expect(() => {
			window.$('#ffc-check-conflicts-btn').trigger('click');
		}).not.toThrow();
	});

	it('create-booking button click does not throw', async () => {
		await mountAndLoad();
		expect(() => {
			window.$('#ffc-create-booking-btn').trigger('click');
		}).not.toThrow();
	});

	it('conflict acknowledge change does not throw', async () => {
		await mountAndLoad();
		expect(() => {
			window.$('#ffc-conflict-acknowledge').prop('checked', true).trigger('change');
		}).not.toThrow();
	});
});
