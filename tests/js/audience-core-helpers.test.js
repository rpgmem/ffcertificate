// Deep coverage for the pure helpers on window.FFCAudience
// (assets/js/ffc-audience.js): date/time formatting, HTML escaping,
// booking-label resolution, the audience-tree utilities (parent-name
// map, name formatting, parent collapse), environment colour/label
// lookup, and modal focus/close. These are exercised directly against
// the singleton with a synthetic state.config, complementing the
// init()/bindEvents() smoke in audience-smoke.test.js.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

let api;

beforeAll(() => {
	window.ffcAudience = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		restUrl: '/wp-json/ffc/v1/audience/',
		nonce: 'n',
		locale: 'pt_BR',
		strings: { booking: 'booking', bookings: 'bookings', environmentLabel: 'Environment' },
	};
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-audience.js');
	api = window.FFCAudience;
});

beforeEach(() => {
	window.$.fx.off = true;
	api.state.config = {};
	api.state._triggerElement = null;
	document.body.innerHTML = '';
});

describe('FFCAudience helpers — date / time / pad', () => {
	it('pad adds a leading zero below 10 only', () => {
		expect(api.pad(5)).toBe('05');
		expect(api.pad(12)).toBe('12');
	});

	it('formatDate renders YYYY-MM-DD with padding', () => {
		expect(api.formatDate(new Date(2026, 5, 9))).toBe('2026-06-09');
		expect(api.formatDate(new Date(2026, 11, 25))).toBe('2026-12-25');
	});

	it('parseDate builds a local-timezone date (no UTC shift)', () => {
		const d = api.parseDate('2026-06-09');
		expect(d.getFullYear()).toBe(2026);
		expect(d.getMonth()).toBe(5);
		expect(d.getDate()).toBe(9);
	});

	it('formatTime trims to HH:MM and tolerates empty', () => {
		expect(api.formatTime('09:30:00')).toBe('09:30');
		expect(api.formatTime('')).toBe('');
	});
});

describe('FFCAudience helpers — escapeHtml', () => {
	it('escapes the five entities', () => {
		expect(api.escapeHtml('<a href="x">&\'')).toBe('&lt;a href=&quot;x&quot;&gt;&amp;&#39;');
	});

	it('returns empty string for falsy input', () => {
		expect(api.escapeHtml('')).toBe('');
		expect(api.escapeHtml(null)).toBe('');
	});
});

describe('FFCAudience helpers — booking label', () => {
	it('prefers the schedule-level custom label', () => {
		api.state.config = { schedules: [{ bookingLabelSingular: 'Aula', bookingLabelPlural: 'Aulas' }] };
		expect(api.getBookingLabel('singular')).toBe('Aula');
		expect(api.getBookingLabel('plural')).toBe('Aulas');
	});

	it('falls back to the global strings when no schedule label', () => {
		api.state.config = { schedules: [{}] };
		expect(api.getBookingLabel('singular')).toBe('booking');
		expect(api.getBookingLabel('plural')).toBe('bookings');
	});
});

describe('FFCAudience helpers — audience tree', () => {
	const tree = [
		{ id: 1, name: 'Parent', children: [
			{ id: 11, name: 'Child A', children: [{ id: 111, name: 'Grand' }] },
			{ id: 12, name: 'Child B' },
		] },
	];

	it('buildParentNameMap maps every child to its parent name', () => {
		api.state.config = { audiences: tree };
		const map = api.buildParentNameMap();
		expect(map[11]).toBe('Parent');
		expect(map[12]).toBe('Parent');
		expect(map[111]).toBe('Child A');
	});

	it('formatAudienceName prefixes the parent only in parent_name mode', () => {
		api.state.config = { audiences: tree, audienceBadgeFormat: 'parent_name' };
		const map = api.buildParentNameMap();
		expect(api.formatAudienceName({ id: 11, name: 'Child A' }, map)).toBe('Parent: Child A');
		// Root node has no parent → plain name.
		expect(api.formatAudienceName({ id: 1, name: 'Parent' }, map)).toBe('Parent');
		// Non-parent_name mode → always plain.
		api.state.config.audienceBadgeFormat = 'plain';
		expect(api.formatAudienceName({ id: 11, name: 'Child A' }, map)).toBe('Child A');
	});

	it('collapseParentAudiences hides children when the parent + all descendants are present', () => {
		api.state.config = { audiences: tree };
		const input = [{ id: 1 }, { id: 11 }, { id: 111 }, { id: 12 }];
		expect(api.collapseParentAudiences(input).map((a) => a.id)).toEqual([1]);
	});

	it('collapseParentAudiences keeps children when not every descendant is present', () => {
		api.state.config = { audiences: tree };
		const input = [{ id: 1 }, { id: 11 }]; // 12 + 111 missing
		expect(api.collapseParentAudiences(input).map((a) => a.id)).toEqual([1, 11]);
	});

	it('collapseParentAudiences returns the input unchanged when empty', () => {
		expect(api.collapseParentAudiences([])).toEqual([]);
	});
});

describe('FFCAudience helpers — environment colour / label', () => {
	beforeEach(() => {
		api.state.config = { schedules: [
			{ environmentLabel: 'Sala', environments: [
				{ id: 10, name: 'A', color: '#abcdef' },
				{ id: 11, name: 'B' },
			] },
		] };
	});

	it('getEnvironmentColor returns the env colour, else the default', () => {
		expect(api.getEnvironmentColor(10)).toBe('#abcdef');
		expect(api.getEnvironmentColor(11)).toBe('#3788d8'); // env present, no colour
		expect(api.getEnvironmentColor(999)).toBe('#3788d8'); // unknown env
	});

	it('getEnvironmentLabelForBooking resolves the owning schedule label', () => {
		expect(api.getEnvironmentLabelForBooking({ environment_id: 10 })).toBe('Sala');
		expect(api.getEnvironmentLabelForBooking({ environment_id: 999 })).toBe('Environment');
	});
});

describe('FFCAudience helpers — closeModals + trapFocus', () => {
	it('closeModals hides both modals and restores focus to the trigger', () => {
		document.body.innerHTML = `
			<button id="trigger">open</button>
			<div id="ffc-booking-modal" style="display:block"></div>
			<div id="ffc-day-modal" style="display:block"></div>
		`;
		api.state._triggerElement = document.getElementById('trigger');
		api.closeModals();
		expect(window.$('#ffc-booking-modal').css('display')).toBe('none');
		expect(window.$('#ffc-day-modal').css('display')).toBe('none');
		expect(api.state._triggerElement).toBeNull();
	});

	it('trapFocus binds a Tab handler that runs without throwing', () => {
		document.body.innerHTML = '<div id="m"><button id="b1">1</button><button id="b2">2</button></div>';
		const $m = window.$('#m');
		api.trapFocus($m);
		// Non-Tab key returns early; Tab key runs the focus-collection path.
		// (jsdom has no layout so :visible filters all out — the wrap
		// branches stay dormant, but the handler body still executes.)
		expect(() => {
			$m.trigger(window.$.Event('keydown', { key: 'a' }));
			$m.trigger(window.$.Event('keydown', { key: 'Tab', shiftKey: false }));
			$m.trigger(window.$.Event('keydown', { key: 'Tab', shiftKey: true }));
		}).not.toThrow();
	});
});
