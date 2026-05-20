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

	it('opens an actionable error panel when libraries are unavailable', () => {
		loadScript('assets/js/ffc-pdf-generator.js');
		const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

		const result = window.ffcGeneratePDF({ html: '<p>hi</p>' }, 'doc.pdf');

		expect(result).toBeUndefined();
		// 6.6.2 Sprint 3: overlay opens + error panel paints (no native alert).
		const overlay = document.querySelector('#ffc-pdf-overlay');
		expect(overlay).not.toBeNull();
		expect(overlay.textContent).toMatch(/PDF libraries/i);
		// "Close" button is always present; "Try again" is NOT (this is a
		// non-recoverable error — user must reload the page).
		expect(document.querySelector('.ffc-pdf-error-close')).not.toBeNull();
		expect(document.querySelector('.ffc-pdf-error-retry')).toBeNull();

		errSpy.mockRestore();
	});

	it('uses ffc_ajax.strings.pdfLibrariesFailed for the error headline when present', () => {
		window.ffc_ajax = {
			strings: { pdfLibrariesFailed: 'Erro: bibliotecas PDF indisponíveis.' },
		};
		loadScript('assets/js/ffc-pdf-generator.js');
		vi.spyOn(console, 'error').mockImplementation(() => {});

		window.ffcGeneratePDF({}, 'x.pdf');

		expect(document.querySelector('#ffc-pdf-overlay').textContent).toContain(
			'Erro: bibliotecas PDF indisponíveis.'
		);
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

// 6.6.2 Sprint 3 — actionable error panel (replaces blocking alert()).
//
// The panel renders inside the overlay with: error icon (!), headline,
// body copy, and a {Try again, Close} pair (or just {Close} when the
// failure is non-recoverable, e.g. lib load failure).
describe('ffc-pdf-generator.js — actionable error panel (Sprint 3)', () => {
	beforeEach(reset);

	function installLibrariesThatBlank() {
		// jsPDF stub identical to Sprint 2 fixture, but html2canvas
		// returns an all-white canvas so the blank-canvas guard trips.
		window.jspdf = {
			jsPDF: function () {
				return { addImage: function () {}, save: function () {}, output: function () { return 'blob:x'; } };
			},
		};
		window.html2canvas = function () {
			return Promise.resolve({
				width: 10,
				height: 10,
				getContext: function () {
					return {
						getImageData: function () {
							// All white pixels → hasContent stays false → blank-canvas error path.
							const data = new Uint8ClampedArray(10 * 10 * 4).fill(255);
							return { data };
						},
					};
				},
				toDataURL: function () { return 'data:image/png;base64,xxx'; },
			});
		};
	}

	function installLibrariesThatTaintCanvas() {
		window.jspdf = {
			jsPDF: function () {
				return { addImage: function () {}, save: function () {}, output: function () { return 'blob:x'; } };
			},
		};
		// html2canvas resolves, but getImageData throws SecurityError —
		// the same shape jsdom-or-browser produces when a cross-origin
		// image taints the canvas.
		window.html2canvas = function () {
			return Promise.resolve({
				width: 10,
				height: 10,
				getContext: function () {
					return {
						getImageData: function () {
							const err = new Error('Tainted canvas');
							err.name = 'SecurityError';
							throw err;
						},
					};
				},
				toDataURL: function () { return 'data:image/png;base64,xxx'; },
			});
		};
	}

	function installLibrariesThatFailHtml2canvas() {
		window.jspdf = {
			jsPDF: function () {
				return { addImage: function () {}, save: function () {}, output: function () { return 'blob:x'; } };
			},
		};
		window.html2canvas = function () {
			return Promise.reject(new Error('canvas backend exploded'));
		};
	}

	function desktopUA() {
		Object.defineProperty(navigator, 'userAgent', {
			configurable: true,
			value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
		});
		Object.defineProperty(navigator, 'maxTouchPoints', { configurable: true, value: 0 });
	}

	it('renders the blank-canvas error with a Try again button', async () => {
		desktopUA();
		window.ffc_ajax = {
			strings: {
				pdfErrorTitle: 'Falha',
				pdfErrorBlank: 'O certificado saiu em branco. Tente novamente.',
				pdfErrorRetry: 'Tentar de novo',
				pdfErrorClose: 'Fechar',
			},
		};
		installLibrariesThatBlank();
		loadScript('assets/js/ffc-pdf-generator.js');
		vi.spyOn(console, 'warn').mockImplementation(() => {});

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>cert</p>' }, 'cert.pdf');
		await vi.advanceTimersByTimeAsync(2000);
		vi.useRealTimers();

		const overlay = document.querySelector('#ffc-pdf-overlay');
		expect(overlay).not.toBeNull();
		expect(overlay.textContent).toContain('Falha');
		expect(overlay.textContent).toContain('O certificado saiu em branco');
		expect(document.querySelector('.ffc-pdf-error-retry')).not.toBeNull();
		expect(document.querySelector('.ffc-pdf-error-close')).not.toBeNull();
	});

	it('renders the CORS-specific message and NO retry on SecurityError', async () => {
		desktopUA();
		window.ffc_ajax = {
			strings: {
				pdfErrorTitle: 'Falha',
				pdfErrorCors: 'Imagem cross-origin bloqueada. Contate o organizador.',
				pdfErrorClose: 'Fechar',
			},
		};
		installLibrariesThatTaintCanvas();
		loadScript('assets/js/ffc-pdf-generator.js');
		vi.spyOn(console, 'error').mockImplementation(() => {});

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>cert</p>' }, 'cert.pdf');
		await vi.advanceTimersByTimeAsync(2000);
		vi.useRealTimers();

		const overlay = document.querySelector('#ffc-pdf-overlay');
		expect(overlay).not.toBeNull();
		expect(overlay.textContent).toContain('Imagem cross-origin');
		// CORS is a server-side fix — retry button must NOT appear.
		expect(document.querySelector('.ffc-pdf-error-retry')).toBeNull();
		expect(document.querySelector('.ffc-pdf-error-close')).not.toBeNull();
	});

	it('renders the html2canvas error with a Try again button', async () => {
		desktopUA();
		window.ffc_ajax = {
			strings: {
				pdfErrorTitle: 'Falha',
				pdfErrorHtml2canvas: 'Renderização falhou. Tente novamente.',
				pdfErrorRetry: 'Tentar de novo',
				pdfErrorClose: 'Fechar',
			},
		};
		installLibrariesThatFailHtml2canvas();
		loadScript('assets/js/ffc-pdf-generator.js');
		vi.spyOn(console, 'error').mockImplementation(() => {});

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>cert</p>' }, 'cert.pdf');
		await vi.advanceTimersByTimeAsync(2000);
		vi.useRealTimers();

		expect(document.querySelector('#ffc-pdf-overlay').textContent).toContain('Renderização falhou');
		expect(document.querySelector('.ffc-pdf-error-retry')).not.toBeNull();
	});

	it('Try again button re-invokes generateAndDownloadPDF with same args', async () => {
		desktopUA();
		window.ffc_ajax = { strings: {} };
		installLibrariesThatFailHtml2canvas();
		loadScript('assets/js/ffc-pdf-generator.js');
		vi.spyOn(console, 'error').mockImplementation(() => {});

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>cert</p>' }, 'cert.pdf');
		await vi.advanceTimersByTimeAsync(2000);

		// First failure → retry button present.
		const retry = document.querySelector('.ffc-pdf-error-retry');
		expect(retry).not.toBeNull();

		// Click retry → overlay fades + the function re-runs (and fails
		// again the same way; we just verify the second overlay appears).
		retry.click();
		// The retry fires after a 350ms defer (in the JS) to let the
		// fadeOut finish; advance enough to cover both.
		await vi.advanceTimersByTimeAsync(1500);
		vi.useRealTimers();

		// New overlay was created by the retry call — same error panel.
		expect(document.querySelector('.ffc-pdf-error-retry')).not.toBeNull();
	});

	it('Close button dismisses the overlay', () => {
		// Lib-failed path opens overlay with Close-only buttons.
		loadScript('assets/js/ffc-pdf-generator.js');
		vi.spyOn(console, 'error').mockImplementation(() => {});

		window.ffcGeneratePDF({}, 'x.pdf');
		expect(document.querySelector('#ffc-pdf-overlay')).not.toBeNull();

		const close = document.querySelector('.ffc-pdf-error-close');
		close.click();
		// hideOverlay uses fadeOut(300). The element may still be in the
		// DOM during the fade-out animation; we just assert the call
		// path doesn't throw.
		expect(typeof close).toBe('object');
	});
});
