// The CSV export dialog (#2344): a named modal with the focus inside, a
// progressbar, a polite status every quarter, a persistent error with a
// Close button, and one set of localised strings for every export.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffcBatchedExportStrings = { title: 'Exportando CSV', close: 'Fechar', percent: '%d por cento', error: 'A exportação falhou.' };
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-batched-export.js');
	window.$.fx.off = true;
});

const O = () => window.FFCProgressOverlay;

beforeEach(() => {
	document.body.innerHTML = '<button id="trigger">Export</button>';
	document.getElementById('trigger').focus();
});

afterEach(() => {
	O().hide();
	vi.restoreAllMocks();
});

describe('FFCProgressOverlay', () => {
	it('is a modal dialog named by its title, with the focus inside', () => {
		O().show('Preparing');
		const card = document.querySelector('.ffc-csv-progress-card');
		expect(card.getAttribute('role')).toBe('dialog');
		expect(card.getAttribute('aria-modal')).toBe('true');
		const title = document.getElementById(card.getAttribute('aria-labelledby'));
		expect(title.textContent).toBe('Exportando CSV');
		expect(document.getElementById(card.getAttribute('aria-describedby')).textContent).toBe('Preparing');
		expect(document.activeElement).toBe(card);
		// The whole overlay is not an assertive live region any more.
		expect(document.querySelector('.ffc-csv-progress-overlay').getAttribute('aria-live')).toBeNull();
	});

	it('reports progress as a progressbar and announces each quarter once', () => {
		O().show('x');
		const bar = document.querySelector('[role="progressbar"]');
		const live = document.querySelector('.ffc-csv-progress-live');
		expect(live.getAttribute('role')).toBe('status');
		O().progress(10, 100);
		expect(bar.getAttribute('aria-valuenow')).toBe('10');
		expect(live.textContent).toBe('');
		O().progress(30, 100);
		expect(live.textContent).toBe('25 por cento');
		live.textContent = 'read';
		O().progress(40, 100);
		expect(live.textContent).toBe('read');
		O().progress(100, 100);
		expect(live.textContent).toBe('100 por cento');
		expect(bar.getAttribute('aria-valuenow')).toBe('100');
	});

	it('keeps an error on screen with a Close button, and gives the focus back on close', () => {
		O().show('x');
		O().error('Boom');
		const card = document.querySelector('.ffc-csv-progress-card');
		expect(card.getAttribute('role')).toBe('alertdialog');
		expect(document.querySelector('.ffc-csv-progress-status').textContent).toBe('A exportação falhou.');
		const close = document.querySelector('.ffc-csv-progress-close');
		expect(close.textContent).toBe('Fechar');
		expect(document.activeElement).toBe(close);
		close.click();
		expect(document.querySelector('.ffc-csv-progress-overlay')).toBeNull();
		expect(document.activeElement.id).toBe('trigger');
	});

	it('keeps Tab inside the dialog and closes an error with Escape', () => {
		O().show('x');
		const card = document.querySelector('.ffc-csv-progress-card');
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
		expect(document.activeElement).toBe(card);
		// Escape does nothing while the export runs.
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
		expect(document.querySelector('.ffc-csv-progress-overlay')).not.toBeNull();
		O().error('Boom');
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
		expect(document.querySelector('.ffc-csv-progress-overlay')).toBeNull();
	});
});

describe('FFCBatchedExport', () => {
	it('uses the localised defaults, and a caller value only when it is a non-empty string', () => {
		const s = window.FFCBatchedExport.strings({ error: '', close: 'X', preparing: 'P' });
		expect(s.error).toBe('A exportação falhou.');
		expect(s.close).toBe('X');
		expect(s.preparing).toBe('P');
		expect(s.generating).toBe('Generating CSV — %d records…');
	});

	it('moves the focus into the dialog before disabling the trigger, and leaves an error up', async () => {
		const trigger = document.getElementById('trigger');
		let focusWhenDisabled = null;
		const orig = window.$.fn.prop;
		vi.spyOn(window.$.fn, 'prop').mockImplementation(function (name, value) {
			if (name === 'disabled' && value === true) {
				focusWhenDisabled = document.activeElement;
			}
			return orig.apply(this, arguments);
		});
		window.FFC.request = vi.fn(() => Promise.reject(Object.assign(new Error('Server says no'), { fromServer: true })));
		window.FFCBatchedExport.run({ type: 't', ajaxUrl: '/x', overlay: true, button: trigger, nonce: 'n' });
		await new Promise((r) => setTimeout(r, 0));

		expect(focusWhenDisabled).toBe(document.querySelector('.ffc-csv-progress-card'));
		expect(trigger.disabled).toBe(false);
		expect(document.querySelector('.ffc-csv-progress-error').textContent).toBe('Server says no');
		expect(document.activeElement).toBe(document.querySelector('.ffc-csv-progress-close'));
	});
});
