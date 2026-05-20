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

// 6.6.2 Sprint 2 — desktop fallback link injection.
//
// We don't drive the whole html2canvas + jsPDF pipeline (that needs a
// real canvas implementation jsdom doesn't ship); we exercise the
// generatePDF entry guards and then assert the contract that the
// desktop branch only emits the fallback when needsPreOpen is false.
// The detailed branch is asserted by post-mortem of the rendered
// elements after `vi.runAllTimersAsync()`.
describe('ffc-pdf-generator.js — desktop fallback link (Sprint 2)', () => {
	beforeEach(reset);

	function installLibraries() {
		// Minimal jsPDF stub: addImage no-ops; output('bloburl') returns
		// a string; save records the call. ffc-pdf-generator clones the
		// jsPDF constructor via `const { jsPDF } = window.jspdf`, so we
		// install the constructor on window.jspdf.
		const saveSpy = vi.fn();
		const outputSpy = vi.fn(() => 'blob:fake-url');
		window.jspdf = {
			jsPDF: function () {
				return {
					addImage: function () {},
					save: saveSpy,
					output: outputSpy,
				};
			},
		};
		// Minimal html2canvas stub: synchronously resolves a fake canvas
		// with a non-white pixel (so the "blank canvas" guard doesn't
		// trip) and `toDataURL` / `getContext` / `width` / `height`.
		window.html2canvas = function () {
			return Promise.resolve({
				width: 10,
				height: 10,
				getContext: function () {
					return {
						getImageData: function () {
							// 10x10 RGBA — one non-white pixel triggers
							// hasContent=true on the first iteration.
							const data = new Uint8ClampedArray(10 * 10 * 4);
							data[0] = 0;
							data[1] = 0;
							data[2] = 0;
							data[3] = 255;
							return { data };
						},
					};
				},
				toDataURL: function () { return 'data:image/png;base64,xxx'; },
			});
		};
		return { saveSpy, outputSpy };
	}

	it('appends .ffc-pdf-desktop-fallback link after pdf.save() on desktop UA', async () => {
		Object.defineProperty(navigator, 'userAgent', {
			configurable: true,
			value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
		});
		Object.defineProperty(navigator, 'maxTouchPoints', { configurable: true, value: 0 });
		window.ffc_ajax = {
			strings: {
				pdfDesktopFallbackHint: 'Não baixou? Clique aqui.',
				pdfDownloaded: 'PDF baixado.',
			},
		};
		const { saveSpy, outputSpy } = installLibraries();
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>cert</p>', orientation: 'landscape' }, 'cert.pdf');
		// Advance past minDisplayTime (800ms) + the inner 300ms gate +
		// html2canvas microtask flush, but stop short of the 6000ms
		// hideOverlay() auto-dismiss that would tear down the link.
		await vi.advanceTimersByTimeAsync(2000);
		vi.useRealTimers();

		// pdf.save() did fire on desktop branch.
		expect(saveSpy).toHaveBeenCalledWith('cert.pdf');
		// blob URL also computed, so the manual fallback link is in DOM.
		expect(outputSpy).toHaveBeenCalledWith('bloburl');
		const link = document.querySelector('a.ffc-pdf-desktop-fallback');
		expect(link).not.toBeNull();
		expect(link.getAttribute('href')).toBe('blob:fake-url');
		expect(link.getAttribute('download')).toBe('cert.pdf');
		expect(link.textContent).toContain('Não baixou');
	});

	it('does NOT inject the desktop fallback link on iOS UA (uses placeholder-tab path)', async () => {
		Object.defineProperty(navigator, 'userAgent', {
			configurable: true,
			value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
		});
		Object.defineProperty(navigator, 'maxTouchPoints', { configurable: true, value: 5 });
		window.ffc_ajax = { strings: { pdfDesktopFallbackHint: 'X' } };
		const { saveSpy } = installLibraries();
		// window.open is the placeholder tab. Return a stub that's not closed.
		const openSpy = vi.spyOn(window, 'open').mockReturnValue({
			closed: false,
			document: { title: '', body: { innerHTML: '', style: { cssText: '' } } },
			location: {},
		});
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>cert</p>' }, 'cert.pdf');
		await vi.runAllTimersAsync();
		vi.useRealTimers();

		// pdf.save() does NOT fire on the iOS branch (location swap is used instead).
		expect(saveSpy).not.toHaveBeenCalled();
		expect(openSpy).toHaveBeenCalled();
		expect(document.querySelector('a.ffc-pdf-desktop-fallback')).toBeNull();
		openSpy.mockRestore();
	});
});
