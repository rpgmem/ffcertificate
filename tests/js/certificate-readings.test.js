/**
 * Certificate terminal readings (#2892): the Export CSV button.
 */
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-certificate-readings.js');
});

function button(attrs = {}) {
	document.body.innerHTML = '';
	const el = document.createElement('button');
	el.id = 'ffc-readings-export';
	Object.entries({ 'data-form-id': '12', 'data-result': 'refused', 'data-search': 'maria', ...attrs }).forEach(([k, v]) => el.setAttribute(k, v));
	const icon = document.createElement('span');
	el.appendChild(icon);
	document.body.appendChild(el);
	return { el, icon };
}

describe('Export CSV on the readings screen', () => {
	beforeEach(() => {
		window.ffcCertificateReadings = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			nonce: 'n1',
			strings: { preparing: 'P', exporting: 'E', done: 'D', error: 'X' },
		};
		window.FFCBatchedExport = { run: vi.fn() };
	});

	it('exports the form with the filters the screen was drawn with', () => {
		const { icon } = button();
		icon.click();

		const run = window.FFCBatchedExport.run;
		expect(run).toHaveBeenCalledTimes(1);
		const cfg = run.mock.calls[0][0];
		expect(cfg.type).toBe('certificate_readings');
		expect(cfg.nonce).toBe('n1');
		expect(cfg.ajaxUrl).toBe('/wp-admin/admin-ajax.php');
		expect(cfg.overlay).toBe(true);
		expect(cfg.startData).toEqual({ form_id: '12', result: 'refused', s: 'maria' });
		expect(cfg.strings.downloading).toBe('D');
	});

	it('sends empty filters when the screen has none', () => {
		const { el } = button({ 'data-result': '', 'data-search': '' });
		expect(window.FFCCertificateReadings.startData(el)).toEqual({ form_id: '12', result: '', s: '' });
	});

	it('does nothing without the export engine or a nonce', () => {
		const { el } = button();
		delete window.FFCBatchedExport;
		expect(window.FFCCertificateReadings.exportCsv(el)).toBe(false);

		window.FFCBatchedExport = { run: vi.fn() };
		window.ffcCertificateReadings.nonce = '';
		expect(window.FFCCertificateReadings.exportCsv(el)).toBe(false);
		expect(window.FFCBatchedExport.run).not.toHaveBeenCalled();
	});

	it('ignores clicks elsewhere', () => {
		document.body.innerHTML = '<button id="other">x</button>';
		document.getElementById('other').click();
		expect(window.FFCBatchedExport.run).not.toHaveBeenCalled();
	});
});
