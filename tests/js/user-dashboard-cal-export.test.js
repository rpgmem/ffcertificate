// Tests for `assets/js/ffc-user-dashboard-cal-export.js`.
//
// The file exposes `FFCDashboard.calExport.buildButton(event)` and binds
// the dropdown click handlers (toggle, ICS download, click-outside). It
// depends on a minimal `FFCDashboard.helpers.pad2` shim + global
// `ffcDashboard` localized strings, both of which we install before
// loading the script.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function installDeps() {
	window.FFCDashboard = {
		helpers: {
			pad2(n) {
				return n < 10 ? '0' + n : '' + n;
			},
		},
	};
	window.ffcDashboard = {
		siteName: 'Test Site',
		wpTimezone: 'America/Sao_Paulo',
		strings: {
			exportToCalendar: 'Export to Calendar',
			otherIcs: 'Other (.ics)',
		},
	};
}

function makeEvent(overrides = {}) {
	return {
		uid: 'evt-1',
		date: '2026-05-20',
		startTime: '09:00',
		endTime: '10:30',
		summary: 'Appointment',
		description: 'Bring docs',
		location: 'Room 4',
		...overrides,
	};
}

describe('ffc-user-dashboard-cal-export — buildButton', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
		installDeps();
		loadScript('assets/js/ffc-user-dashboard-cal-export.js');
	});

	it('exposes calExport.buildButton on FFCDashboard', () => {
		expect(typeof window.FFCDashboard.calExport.buildButton).toBe('function');
	});

	it('renders a wrapping div with .ffc-cal-export-wrap and a dropdown', () => {
		const html = window.FFCDashboard.calExport.buildButton(makeEvent());
		const tmp = document.createElement('div');
		tmp.innerHTML = html;

		expect(tmp.querySelector('.ffc-cal-export-wrap')).not.toBeNull();
		expect(tmp.querySelector('.ffc-cal-export-btn')).not.toBeNull();
		expect(tmp.querySelector('.ffc-cal-export-dropdown')).not.toBeNull();
	});

	it('builds a Google Calendar URL with required params + ctz timezone', () => {
		const html = window.FFCDashboard.calExport.buildButton(makeEvent());
		const tmp = document.createElement('div');
		tmp.innerHTML = html;

		const links = Array.from(tmp.querySelectorAll('a'));
		const google = links.find((a) =>
			a.getAttribute('href').startsWith('https://calendar.google.com/')
		);
		expect(google).toBeDefined();
		const href = google.getAttribute('href');
		expect(href).toContain('action=TEMPLATE');
		expect(href).toContain('text=Appointment');
		// 2026-05-20 09:00 → 20260520T090000, 2026-05-20 10:30 → 20260520T103000
		expect(href).toContain('20260520T090000');
		expect(href).toContain('20260520T103000');
		expect(href).toContain('location=Room%204');
		expect(href).toContain('ctz=America%2FSao_Paulo');
	});

	it('builds an Outlook URL with rru=addevent and ISO-like local time', () => {
		const html = window.FFCDashboard.calExport.buildButton(makeEvent());
		const tmp = document.createElement('div');
		tmp.innerHTML = html;

		const outlook = Array.from(tmp.querySelectorAll('a')).find((a) =>
			a.getAttribute('href').startsWith('https://outlook.live.com/')
		);
		expect(outlook).toBeDefined();
		const href = outlook.getAttribute('href');
		expect(href).toContain('rru=addevent');
		expect(href).toContain('subject=Appointment');
		expect(href).toContain('startdt=2026-05-20T09%3A00%3A00');
		expect(href).toContain('enddt=2026-05-20T10%3A30%3A00');
	});

	it('escapes quotes in the embedded JSON so the data attribute parses safely', () => {
		const html = window.FFCDashboard.calExport.buildButton(
			makeEvent({ summary: "Quoted 'title' & more" })
		);
		// JSON `"` quotes are entity-encoded so they don't break the host
		// data-event="..." attribute; the apostrophe receives the same
		// treatment for consistency.
		expect(html).toContain('&quot;summary&quot;');
		expect(html).toContain('&#39;title&#39;');

		// And parsing it back round-trips to a usable object — the encoded
		// form decodes cleanly when the browser reads the attribute.
		const tmp = document.createElement('div');
		tmp.innerHTML = html;
		const raw = tmp.querySelector('.ffc-cal-export-ics').getAttribute('data-event');
		expect(() => JSON.parse(raw)).not.toThrow();
		expect(JSON.parse(raw).summary).toBe("Quoted 'title' & more");
	});

	it('localizes the trigger button label from ffcDashboard.strings', () => {
		window.ffcDashboard.strings.exportToCalendar = 'Salvar no calendário';
		// Re-evaluating the script picks up the new strings via the closure.
		loadScript('assets/js/ffc-user-dashboard-cal-export.js');
		const html = window.FFCDashboard.calExport.buildButton(makeEvent());
		expect(html).toContain('Salvar no calendário');
	});
});

describe('ffc-user-dashboard-cal-export — dropdown interactions', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
		installDeps();
		loadScript('assets/js/ffc-user-dashboard-cal-export.js');
		document.body.innerHTML = window.FFCDashboard.calExport.buildButton(
			makeEvent()
		);
	});

	it('triggers an ICS download when the .ics link is clicked', () => {
		// Stub the download primitives that jsdom does not implement.
		const createObjectURL = vi.fn(() => 'blob:fake');
		const revokeObjectURL = vi.fn();
		window.URL.createObjectURL = createObjectURL;
		window.URL.revokeObjectURL = revokeObjectURL;

		// Spy on anchor click so we can verify the download was attempted.
		const clickSpy = vi
			.spyOn(window.HTMLAnchorElement.prototype, 'click')
			.mockImplementation(() => {});

		window.$('.ffc-cal-export-ics').trigger('click');

		expect(createObjectURL).toHaveBeenCalled();
		expect(clickSpy).toHaveBeenCalled();
		expect(revokeObjectURL).toHaveBeenCalledWith('blob:fake');
		clickSpy.mockRestore();
	});

	it('toggles the dropdown open on trigger-button click and closes other open dropdowns', () => {
		// Add a second, already-open dropdown so the "close siblings" path runs.
		document.body.insertAdjacentHTML(
			'beforeend',
			window.FFCDashboard.calExport.buildButton(makeEvent({ uid: 'evt-2' }))
		);
		const wraps = document.querySelectorAll('.ffc-cal-export-wrap');
		// Force the second one open.
		wraps[1].querySelector('.ffc-cal-export-dropdown').classList.add('open');

		// Click the first trigger button.
		window.$(wraps[0]).find('.ffc-cal-export-btn').trigger('click');

		expect(wraps[0].querySelector('.ffc-cal-export-dropdown').classList.contains('open')).toBe(true);
		// The previously-open sibling was closed.
		expect(wraps[1].querySelector('.ffc-cal-export-dropdown').classList.contains('open')).toBe(false);

		// A second click toggles it back closed.
		window.$(wraps[0]).find('.ffc-cal-export-btn').trigger('click');
		expect(wraps[0].querySelector('.ffc-cal-export-dropdown').classList.contains('open')).toBe(false);
	});

	it('handles an ICS download for an event with an empty summary (escapeIcsText empty-string guard)', () => {
		document.body.innerHTML = window.FFCDashboard.calExport.buildButton(
			makeEvent({ summary: '', description: '', location: '' })
		);
		window.URL.createObjectURL = vi.fn(() => 'blob:fake');
		window.URL.revokeObjectURL = vi.fn();
		const clickSpy = vi
			.spyOn(window.HTMLAnchorElement.prototype, 'click')
			.mockImplementation(() => {});

		window.$('.ffc-cal-export-ics').trigger('click');

		expect(window.URL.createObjectURL).toHaveBeenCalled();
		clickSpy.mockRestore();
	});
});
