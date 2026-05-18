// Tests for `assets/js/ffc-pdf-generator.js`.
//
// The file exposes `window.ffcGeneratePDF` and `window.ffcPdfGenerator`.
// Full PDF generation pulls in html2canvas + jsPDF and a long async
// chain that's out of scope here; we cover the load-time contract and
// the library-availability guard.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function reset() {
	document.body.innerHTML = '';
	delete window.ffcGeneratePDF;
	delete window.ffcPdfGenerator;
	delete window.html2canvas;
	delete window.jspdf;
	delete window.ffc_ajax;
}

describe('ffc-pdf-generator.js — public API', () => {
	beforeEach(reset);

	it('exposes ffcGeneratePDF and ffcPdfGenerator on the window', () => {
		loadScript('assets/js/ffc-pdf-generator.js');

		expect(typeof window.ffcGeneratePDF).toBe('function');
		expect(typeof window.ffcPdfGenerator).toBe('object');
		expect(typeof window.ffcPdfGenerator.generatePDF).toBe('function');
		expect(typeof window.ffcPdfGenerator.checkLibraries).toBe('function');
	});

	it('ffcPdfGenerator.generatePDF and window.ffcGeneratePDF are the same function', () => {
		loadScript('assets/js/ffc-pdf-generator.js');
		expect(window.ffcPdfGenerator.generatePDF).toBe(window.ffcGeneratePDF);
	});
});

describe('ffc-pdf-generator.js — checkLibraries()', () => {
	beforeEach(reset);

	it('returns false and logs an error when html2canvas is undefined', () => {
		loadScript('assets/js/ffc-pdf-generator.js');
		const err = vi.spyOn(console, 'error').mockImplementation(() => {});

		const ok = window.ffcPdfGenerator.checkLibraries();

		expect(ok).toBe(false);
		expect(err).toHaveBeenCalledWith(expect.stringContaining('html2canvas'));
		err.mockRestore();
	});

	it('returns false when html2canvas is present but jsPDF is missing', () => {
		window.html2canvas = function () {};
		loadScript('assets/js/ffc-pdf-generator.js');
		const err = vi.spyOn(console, 'error').mockImplementation(() => {});

		const ok = window.ffcPdfGenerator.checkLibraries();

		expect(ok).toBe(false);
		expect(err).toHaveBeenCalledWith(expect.stringContaining('jsPDF'));
		err.mockRestore();
	});

	it('returns true when both libraries are present', () => {
		window.html2canvas = function () {};
		window.jspdf = { jsPDF: function () {} };
		loadScript('assets/js/ffc-pdf-generator.js');

		expect(window.ffcPdfGenerator.checkLibraries()).toBe(true);
	});
});

describe('ffc-pdf-generator.js — generateAndDownloadPDF guard', () => {
	beforeEach(reset);

	it('alerts and bails when libraries are unavailable', () => {
		loadScript('assets/js/ffc-pdf-generator.js');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

		const result = window.ffcGeneratePDF({ html: '<p>hi</p>' }, 'doc.pdf');

		expect(result).toBeUndefined();
		expect(alertSpy).toHaveBeenCalled();
		// Default message when ffc_ajax.strings is absent.
		expect(alertSpy.mock.calls[0][0]).toMatch(/PDF libraries/i);
		// No DOM side effects when bailing early.
		expect(document.querySelector('#ffc-pdf-overlay')).toBeNull();
		expect(document.querySelector('.ffc-pdf-temp-container')).toBeNull();

		alertSpy.mockRestore();
		errSpy.mockRestore();
	});

	it('uses ffc_ajax.strings.pdfLibrariesFailed for the alert when present', () => {
		window.ffc_ajax = {
			strings: { pdfLibrariesFailed: 'Erro: bibliotecas PDF indisponíveis.' },
		};
		loadScript('assets/js/ffc-pdf-generator.js');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(console, 'error').mockImplementation(() => {});

		window.ffcGeneratePDF({}, 'x.pdf');

		expect(alertSpy).toHaveBeenCalledWith(
			'Erro: bibliotecas PDF indisponíveis.'
		);
		alertSpy.mockRestore();
	});
});
