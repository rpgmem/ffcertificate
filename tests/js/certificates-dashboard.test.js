// Tests for `assets/js/ffc-certificates-dashboard.js`.
//
// The script wraps `FFCCalendarCore` (the in-house calendar widget from
// ffc-calendar-core.js) into a monthly view of forms keyed by their
// geofence start date. It bails early on three conditions; the rest of
// the file builds a calendar instance with options and binds AJAX
// fetches per month.
//
// Tests cover the bail paths + the happy-path instantiation. Deeper
// AJAX/render paths would re-test the calendar component itself, which
// is out of scope here.
//
// Sprint P of #175.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	window.ffcCertificatesDashboard = undefined;
	window.FFCCalendarCore = undefined;
	document.body.innerHTML = '';
});

describe('ffc-certificates-dashboard — bail paths', () => {
	it('bails silently when FFCCalendarCore is not loaded', async () => {
		// Settings present but no calendar component.
		window.ffcCertificatesDashboard = { i18n: {} };
		document.body.innerHTML = '<div id="ffc-certificates-calendar"></div>';
		expect(() => loadScript('assets/js/ffc-certificates-dashboard.js')).not.toThrow();
		await new Promise((r) => setTimeout(r, 0));
		// No FFCCalendarCore was instantiated since the module wasn't loaded.
		// (Nothing observable to assert other than "didn't crash".)
	});

	it('bails silently when window.ffcCertificatesDashboard is undefined', async () => {
		// Calendar core stubbed but no settings payload.
		window.FFCCalendarCore = vi.fn();
		document.body.innerHTML = '<div id="ffc-certificates-calendar"></div>';
		loadScript('assets/js/ffc-certificates-dashboard.js');
		await new Promise((r) => setTimeout(r, 0));
		expect(window.FFCCalendarCore).not.toHaveBeenCalled();
	});

	it('bails silently when #ffc-certificates-calendar is missing from the DOM', async () => {
		window.FFCCalendarCore = vi.fn();
		window.ffcCertificatesDashboard = { i18n: {} };
		document.body.innerHTML = '<div>nothing</div>';
		loadScript('assets/js/ffc-certificates-dashboard.js');
		await new Promise((r) => setTimeout(r, 0));
		expect(window.FFCCalendarCore).not.toHaveBeenCalled();
	});
});

describe('ffc-certificates-dashboard — happy path', () => {
	it('instantiates FFCCalendarCore on the #ffc-certificates-calendar container', async () => {
		window.FFCCalendarCore = vi.fn(function () {
			// Constructor return — the actual core returns an object with methods.
			return { init: vi.fn(), refresh: vi.fn() };
		});
		window.ffcCertificatesDashboard = {
			i18n: { today: 'Hoje', clear: 'Limpar' },
			restUrl: '/wp-json/ffc/v1/',
		};
		document.body.innerHTML = `
			<div id="ffc-certificates-calendar"></div>
			<ul id="ffc-certificates-day-list"></ul>
			<p class="ffc-certificates-side-empty">Empty</p>
			<h3 class="ffc-certificates-side-title">Forms</h3>
		`;
		loadScript('assets/js/ffc-certificates-dashboard.js');
		await new Promise((r) => setTimeout(r, 0));

		expect(window.FFCCalendarCore).toHaveBeenCalledOnce();
		// First arg is the jQuery wrapper for the container; assert it
		// points at our DOM element by checking the underlying [0] node.
		const callArgs = window.FFCCalendarCore.mock.calls[0];
		expect(callArgs[0][0]).toBe(document.getElementById('ffc-certificates-calendar'));
		// Second arg is an options object — confirm it's an object.
		expect(typeof callArgs[1]).toBe('object');
	});
});
