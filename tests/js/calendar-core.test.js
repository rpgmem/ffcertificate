// Tests for `assets/js/ffc-calendar-core.js` — the shared monthly
// calendar component (window.FFCCalendarCore constructor + prototype).
//
// Sprint 3 of the JS coverage roadmap. Wraps a real FullCalendar-like
// widget that the plugin reuses across scheduling features; tests
// drive the constructor + the navigation/selection methods.
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-calendar-core.js');
});

beforeEach(() => {
	document.body.innerHTML = '<div id="cal"></div>';
});

function build(options = {}) {
	const $c = window.$('#cal');
	return new window.FFCCalendarCore($c, options);
}

// ----------------------------------------------------------------------
// Constructor + initial render
// ----------------------------------------------------------------------

describe('FFCCalendarCore constructor', () => {
	it('renders the calendar root .ffc-calendar-core inside the container', () => {
		build();
		expect(document.querySelector('#cal .ffc-calendar-core')).not.toBeNull();
	});

	it('renders weekday headers from options.strings.weekdays', () => {
		build({ strings: { weekdays: ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'] } });
		const headers = document.querySelectorAll('#cal .ffc-weekday-header, #cal .ffc-weekday');
		// Whatever the class is, there should be 7 weekday cells.
		expect(headers.length).toBeGreaterThanOrEqual(7);
	});

	it('logs an error and bails when container is empty', () => {
		const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
		const $empty = window.$('#nonexistent');
		const inst = new window.FFCCalendarCore($empty, {});
		expect(errSpy).toHaveBeenCalled();
		// Instance should have no $container set (init bailed).
		expect(inst.$container).toBeUndefined();
		errSpy.mockRestore();
	});

	it('merges user options into the defaults (deep merge for strings)', () => {
		const inst = build({
			strings: { today: 'Hoje' },
			showLegend: false,
		});
		expect(inst.options.strings.today).toBe('Hoje');
		// Defaults preserved.
		expect(inst.options.strings.holiday).toBe('Holiday');
		expect(inst.options.showLegend).toBe(false);
	});
});

// ----------------------------------------------------------------------
// Navigation
// ----------------------------------------------------------------------

describe('FFCCalendarCore navigation', () => {
	it('nextMonth advances the current date by 1 month', () => {
		const inst = build();
		inst.currentDate = new Date(2026, 0, 15); // Jan 2026
		inst.nextMonth();
		expect(inst.currentDate.getMonth()).toBe(1); // Feb
	});

	it('prevMonth retreats the current date by 1 month', () => {
		const inst = build();
		inst.currentDate = new Date(2026, 2, 15); // Mar
		inst.prevMonth();
		expect(inst.currentDate.getMonth()).toBe(1); // Feb
	});

	it('goToToday resets the current date to today', () => {
		const inst = build();
		inst.currentDate = new Date(2000, 0, 1);
		inst.goToToday();
		const today = new Date();
		expect(inst.currentDate.getFullYear()).toBe(today.getFullYear());
		expect(inst.currentDate.getMonth()).toBe(today.getMonth());
	});

	it('goToDate accepts a Y-m-d string and parses it as local time', () => {
		const inst = build();
		inst.goToDate('2027-06-15');
		expect(inst.currentDate.getFullYear()).toBe(2027);
		expect(inst.currentDate.getMonth()).toBe(5); // 0-indexed
		expect(inst.currentDate.getDate()).toBe(15);
	});

	it('goToDate accepts a Date object', () => {
		const inst = build();
		const d = new Date(2027, 6, 4);
		inst.goToDate(d);
		expect(inst.currentDate.getFullYear()).toBe(2027);
		expect(inst.currentDate.getMonth()).toBe(6);
		expect(inst.currentDate.getDate()).toBe(4);
	});
});

// ----------------------------------------------------------------------
// Selection
// ----------------------------------------------------------------------

describe('FFCCalendarCore selection', () => {
	it('selectDate stores the selectedDate and adds .ffc-selected to the matching day cell', () => {
		const inst = build();
		// Force a known month to be rendered so we can target a day.
		inst.goToDate('2026-06-15');
		inst.selectDate('2026-06-15');
		expect(inst.selectedDate).toBe('2026-06-15');
		const selected = document.querySelectorAll('#cal .ffc-day.ffc-selected');
		expect(selected.length).toBeGreaterThanOrEqual(1);
	});

	it('clearSelection removes .ffc-selected and nulls selectedDate', () => {
		const inst = build();
		inst.goToDate('2026-06-15');
		inst.selectDate('2026-06-15');
		inst.clearSelection();
		expect(inst.selectedDate).toBeNull();
		expect(document.querySelectorAll('#cal .ffc-day.ffc-selected').length).toBe(0);
	});
});

// ----------------------------------------------------------------------
// Setters
// ----------------------------------------------------------------------

describe('FFCCalendarCore setters', () => {
	it('setHolidays updates options.holidays and re-renders', () => {
		const inst = build();
		inst.goToDate('2026-12-01');
		inst.setHolidays({ '2026-12-25': 'Natal' });
		expect(inst.options.holidays['2026-12-25']).toBe('Natal');
	});

	it('setDisabledDays updates options.disabledDays and re-renders', () => {
		const inst = build();
		inst.setDisabledDays([0, 6]); // Sun + Sat
		expect(inst.options.disabledDays).toEqual([0, 6]);
	});

	it('setOptions merges new options and re-renders', () => {
		const inst = build();
		inst.setOptions({ showLegend: false });
		expect(inst.options.showLegend).toBe(false);
	});
});
