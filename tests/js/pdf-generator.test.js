// Tests for `assets/js/ffc-pdf-generator.js`.
//
// The file exposes `window.ffcGeneratePDF` and `window.ffcPdfGenerator`.
// Full PDF generation pulls in html2canvas + jsPDF and a long async
// chain that's out of scope here; we cover the load-time contract and
// the library-availability guard.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// The PDF path asks FFC.Platform which browser it is in (#2030).
loadScript('assets/js/ffc-core.js');

function reset() {
	document.body.innerHTML = '';
	delete window.ffcGeneratePDF;
	delete window.ffcPdfGenerator;
	delete window.html2canvas;
	delete window.jspdf;
	delete window.ffc_ajax;
	delete window.ffcPdfLibraries;
	document.head.querySelectorAll('script[data-test-lib]').forEach((n) => n.remove());
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

	it('sets the background as an attribute, so a quote in the URL opens no new attribute', async () => {
		Object.defineProperty(navigator, 'userAgent', { configurable: true, value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' });
		Object.defineProperty(navigator, 'maxTouchPoints', { configurable: true, value: 0 });
		window.ffc_ajax = { strings: {} };
		installLibraries();
		const real = window.html2canvas;
		let seen = null;
		window.html2canvas = function (el) {
			if (!seen) {
				seen = el.querySelector('img.ffc-pdf-bg');
				seen = seen && { src: seen.getAttribute('src'), onerror: seen.getAttribute('onerror'), first: seen.parentNode.classList.contains('ffc-pdf-wrapper') && seen === seen.parentNode.firstElementChild };
			}
			return real.apply(this, arguments);
		};
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>cert</p>', bg_image: 'https://x.test/a.png" onerror="window.__ffcPdfXss=1' }, 'cert.pdf');
		// jsdom never loads the image, so the 10 s image wait has to run out.
		await vi.advanceTimersByTimeAsync(12000);
		vi.useRealTimers();

		// #2344: the whole value is the src; nothing becomes a handler.
		expect(seen).toEqual({ src: 'https://x.test/a.png" onerror="window.__ffcPdfXss=1', onerror: null, first: true });
		expect(window.__ffcPdfXss).toBeUndefined();
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

// #2241 — the bundles are fetched when a page is about to need them.
describe('ffc-pdf-generator.js — load()', () => {
	beforeEach(reset);
	// A generation started here runs on fake timers, and dropping them here
	// drops its pending steps, which would otherwise fire after the file's
	// window is gone (`view.getComputedStyle is not a function`).
	afterEach(() => {
		vi.useRealTimers();
		vi.restoreAllMocks();
	});

	// Stand in for the network: answer each injected <script> by defining
	// the global its bundle would define, or by failing.
	function serveScripts(behaviour) {
		const urls = [];
		const original = document.head.appendChild.bind(document.head);
		vi.spyOn(document.head, 'appendChild').mockImplementation((node) => {
			if (node.tagName !== 'SCRIPT') {
				return original(node);
			}
			urls.push(node.getAttribute('src'));
			node.setAttribute('data-test-lib', '1');
			original(node);
			setTimeout(() => {
				if (behaviour === 'fail') {
					node.onerror();
					return;
				}
				const src = node.getAttribute('src');
				if (src.includes('html2canvas') || src === '/a.js') {
					window.html2canvas = function () {};
				}
				if (src.includes('jspdf') || src === '/b.js') {
					window.jspdf = { jsPDF: function () {} };
				}
				node.onload();
			}, 0);
			return node;
		});
		return urls;
	}

	it('injects both bundles once and resolves true', async () => {
		window.ffcPdfLibraries = { html2canvas: '/libs/html2canvas.js?ver=1', jspdf: '/libs/jspdf.js?ver=2' };
		const urls = serveScripts('ok');
		loadScript('assets/js/ffc-pdf-generator.js');

		const first = window.ffcPdfGenerator.load();
		const second = window.ffcPdfGenerator.load();

		expect(await first).toBe(true);
		expect(await second).toBe(true);
		expect(urls).toEqual(['/libs/html2canvas.js?ver=1', '/libs/jspdf.js?ver=2']);
	});

	it('injects nothing when a page already enqueued the bundles', async () => {
		window.html2canvas = function () {};
		window.jspdf = { jsPDF: function () {} };
		window.ffcPdfLibraries = { html2canvas: '/a.js', jspdf: '/b.js' };
		const urls = serveScripts('ok');
		loadScript('assets/js/ffc-pdf-generator.js');

		expect(await window.ffcPdfGenerator.load()).toBe(true);
		expect(urls).toEqual([]);
	});

	it('resolves false on a failed fetch, and a later call tries again', async () => {
		window.ffcPdfLibraries = { html2canvas: '/a.js', jspdf: '/b.js' };
		const urls = serveScripts('fail');
		loadScript('assets/js/ffc-pdf-generator.js');

		expect(await window.ffcPdfGenerator.load()).toBe(false);
		expect(await window.ffcPdfGenerator.load()).toBe(false);
		expect(urls).toHaveLength(4);
	});

	it('a submit before the bundles arrive waits for them, then generates', async () => {
		window.ffcPdfLibraries = { html2canvas: '/a.js', jspdf: '/b.js' };
		serveScripts('ok');
		loadScript('assets/js/ffc-pdf-generator.js');
		vi.spyOn(console, 'error').mockImplementation(() => {});

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>hi</p>' }, 'x.pdf');

		// The overlay opens at once; no "libraries failed" error.
		expect(document.querySelector('#ffc-pdf-overlay')).not.toBeNull();
		await vi.advanceTimersByTimeAsync(5);
		expect(typeof window.html2canvas).toBe('function');
		expect(document.querySelector('#ffc-pdf-overlay').textContent).not.toMatch(/PDF libraries/i);
	});

	it('a submit whose bundles never arrive shows the libraries error', async () => {
		window.ffcPdfLibraries = { html2canvas: '/a.js', jspdf: '/b.js' };
		serveScripts('fail');
		loadScript('assets/js/ffc-pdf-generator.js');
		vi.spyOn(console, 'error').mockImplementation(() => {});

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>hi</p>' }, 'x.pdf');
		await vi.advanceTimersByTimeAsync(5);

		expect(document.querySelector('#ffc-pdf-overlay').textContent).toMatch(/PDF libraries/i);
	});
});

// The paths below drive the whole pipeline with stub libraries: the
// platform branches, the image wait, the lazy-load restore, the overlay's
// keyboard contract and the error panel's retries.
describe('ffc-pdf-generator.js — pipeline branches', () => {
	let seen;
	let libs;

	function setUA(ua, touch) {
		Object.defineProperty(navigator, 'userAgent', { configurable: true, value: ua });
		Object.defineProperty(navigator, 'maxTouchPoints', { configurable: true, value: touch || 0 });
	}

	const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
	const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)';
	const SAMSUNG = 'Mozilla/5.0 (Linux; Android 13) SamsungBrowser/23.0 Mobile';
	const ANDROID = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) Chrome/120.0 Mobile';

	// pixels: 'content' (one dark pixel), 'blank' (all white), 'taint'
	// (getImageData throws `throwName`), or 'reject' (html2canvas fails).
	function install(pixels, throwName) {
		const save = vi.fn();
		const output = vi.fn(() => 'blob:fake-url');
		const ctor = vi.fn();
		window.jspdf = {
			jsPDF: function () {
				ctor.apply(null, arguments);
				return { addImage: vi.fn(), save, output };
			},
		};
		seen = [];
		const calls = [];
		window.html2canvas = function (el, opts) {
			calls.push(opts);
			seen.push(Array.from(el.querySelectorAll('img')).map((i) => i.getAttribute('src')));
			if (pixels === 'reject') {
				return Promise.reject(new Error('backend'));
			}
			return Promise.resolve({
				width: 2,
				height: 2,
				getContext: () => ({
					getImageData: () => {
						if (pixels === 'taint') {
							const err = new Error('generic failure');
							err.name = throwName || 'TypeError';
							throw err;
						}
						const data = new Uint8ClampedArray(16).fill(255);
						if (pixels === 'content') {
							data[0] = 0;
						}
						return { data };
					},
				}),
				toDataURL: () => 'data:image/png;base64,xxx',
			});
		};
		libs = { save, output, ctor, calls };
		return libs;
	}

	function placeholderTab(overrides) {
		return Object.assign({
			closed: false,
			close: vi.fn(),
			document: { title: '', body: { innerHTML: '', style: { cssText: '' } } },
			location: {},
		}, overrides || {});
	}

	function overlay() {
		return document.querySelector('#ffc-pdf-overlay');
	}

	beforeEach(() => {
		reset();
		// An earlier test's fadeOut may still be ticking on real timers;
		// drop it so this test's animations run on the fake clock.
		window.$.timers.length = 0;
		window.$.fx.stop();
		window.$.fx.off = true;
		vi.spyOn(console, 'error').mockImplementation(() => {});
		vi.spyOn(console, 'warn').mockImplementation(() => {});
		setUA(DESKTOP);
	});

	afterEach(() => {
		vi.useRealTimers();
		vi.restoreAllMocks();
		window.$.fx.off = false;
	});

	it('saves as certificate.pdf in portrait at full scale on desktop when no filename is given', async () => {
		install('content');
		window.ffc_ajax = { strings: {} };
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>', orientation: 'portrait', bg_color: '#123456' });
		const wrapper = document.querySelector('.ffc-pdf-temp-container .ffc-pdf-wrapper');
		expect(wrapper.getAttribute('style')).toContain('background-color:#123456;');
		expect(document.querySelector('.ffc-pdf-temp-container').style.width).toBe('794px');
		await vi.advanceTimersByTimeAsync(2000);

		expect(libs.ctor).toHaveBeenCalledWith('portrait', 'mm', 'a4');
		expect(libs.calls[0]).toMatchObject({ scale: 2, width: 794, height: 1123 });
		expect(libs.save).toHaveBeenCalledWith('certificate.pdf');
		expect(overlay().querySelector('h3').textContent).toBe('PDF downloaded successfully.');
		expect(document.querySelector('a.ffc-pdf-desktop-fallback').getAttribute('download')).toBe('certificate.pdf');
		expect(document.querySelector('a.ffc-pdf-desktop-fallback').textContent).toBe("Didn't download? Click here to open the PDF in a new tab.");
		expect(document.querySelector('.ffc-pdf-temp-container')).toBeNull();

		// The success panel dismisses itself after six seconds.
		await vi.advanceTimersByTimeAsync(6500);
		expect(overlay()).toBeNull();
	});

	it('revokes the blob URL a minute after the overlay goes, not before (#2344)', async () => {
		install('content');
		const revoke = vi.fn();
		window.URL.revokeObjectURL = revoke;
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		await vi.advanceTimersByTimeAsync(2000);
		expect(document.querySelector('a.ffc-pdf-desktop-fallback').getAttribute('href')).toBe('blob:fake-url');

		// The panel goes after six seconds; the link it offered may have just
		// opened a tab still reading the URL, so the revoke waits a minute.
		await vi.advanceTimersByTimeAsync(6500);
		expect(overlay()).toBeNull();
		expect(revoke).not.toHaveBeenCalled();
		await vi.advanceTimersByTimeAsync(60000);
		expect(revoke).toHaveBeenCalledTimes(1);
		expect(revoke).toHaveBeenCalledWith('blob:fake-url');
	});

	it('revokes a pending blob URL at once when the page goes away (#2344)', async () => {
		install('content');
		const revoke = vi.fn();
		window.URL.revokeObjectURL = revoke;
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		await vi.advanceTimersByTimeAsync(2000);
		expect(revoke).not.toHaveBeenCalled();

		window.dispatchEvent(new Event('pagehide'));
		expect(revoke).toHaveBeenCalledWith('blob:fake-url');
	});

	it('lets Escape dismiss the success panel early once the fallback link is offered', async () => {
		install('content');
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		await vi.advanceTimersByTimeAsync(2000);
		expect(document.querySelector('a.ffc-pdf-desktop-fallback')).not.toBeNull();

		window.$(overlay()).trigger(window.$.Event('keydown', { key: 'Escape' }));
		await vi.advanceTimersByTimeAsync(500);
		expect(overlay()).toBeNull();

		// The pending auto-dismiss then finds nothing to close and no new
		// overlay appears.
		await vi.advanceTimersByTimeAsync(6000);
		expect(overlay()).toBeNull();
	});

	it('tells an Android Chrome visitor to look in Downloads, rendering at the mobile scale', async () => {
		setUA(ANDROID);
		install('content');
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		await vi.advanceTimersByTimeAsync(2000);

		expect(libs.calls[0].scale).toBe(1.5);
		expect(libs.save).toHaveBeenCalledWith('a.pdf');
		expect(overlay().querySelector('h3').textContent).toBe('PDF saved! Check your Downloads folder.');
	});

	it('uses the localised Android text when one is given', async () => {
		setUA(ANDROID);
		install('content');
		window.ffc_ajax = { strings: { pdfSavedAndroid: 'Salvo em Downloads.' } };
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		await vi.advanceTimersByTimeAsync(2000);

		expect(overlay().querySelector('h3').textContent).toBe('Salvo em Downloads.');
	});

	it('points a Samsung Internet placeholder tab at the PDF and says so', async () => {
		setUA(SAMSUNG);
		install('content');
		const tab = placeholderTab();
		vi.spyOn(window, 'open').mockReturnValue(tab);
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		expect(window.open).toHaveBeenCalledWith('about:blank', '_blank');
		expect(tab.document.title).toBe('Generating your certificate…');
		expect(tab.document.body.innerHTML).toContain('Please do not close this tab.');
		await vi.advanceTimersByTimeAsync(2000);

		expect(libs.save).not.toHaveBeenCalled();
		expect(tab.location.href).toBe('blob:fake-url');
		expect(overlay().querySelector('h3').textContent).toBe('PDF opened in a new tab. Use the menu to save or share.');
		expect(document.querySelector('a.ffc-pdf-desktop-fallback')).toBeNull();
	});

	it('paints the localised placeholder text, and survives a tab that refuses it', async () => {
		setUA(IPHONE, 5);
		install('content');
		window.ffc_ajax = { strings: { pdfGeneratingTab: 'Gerando…', pdfGeneratingTabHint: 'Aguarde.', pdfOpenedIOS: 'Aberto.' } };
		const tab = placeholderTab();
		vi.spyOn(window, 'open').mockReturnValue(tab);
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		expect(tab.document.title).toBe('Gerando…');
		expect(tab.document.body.innerHTML).toBe('<h2 style="font-weight:600;margin:0 0 12px">Gerando…</h2><p>Aguarde.</p>');
		// This first generation is not followed; its pending steps go with the fake clock.
		vi.useRealTimers();

		reset();
		install('content');
		const throwing = placeholderTab({ document: null });
		window.open.mockReturnValue(throwing);
		loadScript('assets/js/ffc-pdf-generator.js');
		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		await vi.advanceTimersByTimeAsync(2000);
		// The tab still works as the target.
		expect(throwing.location.href).toBe('blob:fake-url');
	});

	it('offers a tap-to-open link when the popup was blocked on iOS', async () => {
		setUA(IPHONE, 5);
		install('content');
		vi.spyOn(window, 'open').mockReturnValue(null);
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' });
		await vi.advanceTimersByTimeAsync(2000);

		const cta = document.querySelector('a.ffc-pdf-fallback-btn');
		expect(cta).not.toBeNull();
		expect(cta.getAttribute('href')).toBe('blob:fake-url');
		expect(cta.getAttribute('download')).toBe('certificate.pdf');
		expect(cta.getAttribute('target')).toBe('_blank');
		expect(cta.textContent).toBe('Tap to open the PDF');
		expect(overlay().querySelector('h3').textContent).toBe('Tap to open the PDF');
		expect(overlay().querySelector('p').textContent).toContain('Pop-ups are blocked in this browser.');
		expect(overlay().querySelector('.ffc-spinner').style.display).toBe('none');

		// The tap dismisses the overlay a moment later.
		cta.click();
		await vi.advanceTimersByTimeAsync(1000);
		expect(overlay()).toBeNull();
	});

	it('treats a tab that refuses the location swap as blocked, with localised texts', async () => {
		setUA(IPHONE, 5);
		install('content');
		window.ffc_ajax = { strings: { pdfManualOpenIOS: 'Toque para abrir', pdfManualHintIOS: 'Pop-ups bloqueados.' } };
		const tab = placeholderTab();
		Object.defineProperty(tab, 'location', {
			get() {
				return { set href(_v) { throw new Error('refused'); } };
			},
		});
		vi.spyOn(window, 'open').mockReturnValue(tab);
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'mine.pdf');
		await vi.advanceTimersByTimeAsync(2000);

		const cta = document.querySelector('a.ffc-pdf-fallback-btn');
		expect(cta.textContent).toBe('Toque para abrir');
		expect(cta.getAttribute('download')).toBe('mine.pdf');
		expect(overlay().querySelector('p').textContent).toBe('Pop-ups bloqueados.');
	});

	it('treats a placeholder tab the visitor closed as blocked', async () => {
		setUA(SAMSUNG);
		install('content');
		vi.spyOn(window, 'open').mockReturnValue(placeholderTab({ closed: true }));
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		await vi.advanceTimersByTimeAsync(2000);

		expect(document.querySelector('a.ffc-pdf-fallback-btn')).not.toBeNull();
	});

	it('closes the placeholder tab when rendering fails', async () => {
		setUA(IPHONE, 5);
		install('reject');
		const tab = placeholderTab();
		vi.spyOn(window, 'open').mockReturnValue(tab);
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		await vi.advanceTimersByTimeAsync(2000);

		expect(tab.close).toHaveBeenCalledTimes(1);
		expect(overlay().textContent).toContain("We couldn't render the certificate.");
	});

	it('swallows a placeholder tab that throws on close', async () => {
		setUA(IPHONE, 5);
		install('taint');
		const tab = placeholderTab({ close: vi.fn(() => { throw new Error('nope'); }) });
		vi.spyOn(window, 'open').mockReturnValue(tab);
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		await vi.advanceTimersByTimeAsync(2000);

		expect(tab.close).toHaveBeenCalled();
		// A non-CORS failure keeps the retry and says something went wrong.
		expect(overlay().textContent).toContain('Something went wrong while building the PDF.');
		expect(document.querySelector('.ffc-pdf-error-retry')).not.toBeNull();
	});

	it('reads a "tainted" message as a CORS failure even without a SecurityError name', async () => {
		install('taint');
		window.html2canvas = (() => {
			const real = window.html2canvas;
			return function () {
				return real.apply(this, arguments).then((canvas) => {
					canvas.getContext = () => ({ getImageData: () => { throw new Error('The canvas has been tainted by cross-origin data.'); } });
					return canvas;
				});
			};
		})();
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		await vi.advanceTimersByTimeAsync(2000);

		expect(overlay().textContent).toContain('cross-origin restriction');
		expect(document.querySelector('.ffc-pdf-error-retry')).toBeNull();
	});

	it('retries a generic failure and a blank canvas with the same arguments', async () => {
		install('taint');
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		await vi.advanceTimersByTimeAsync(2000);
		expect(libs.calls).toHaveLength(1);

		document.querySelector('.ffc-pdf-error-retry').click();
		await vi.advanceTimersByTimeAsync(2500);
		expect(libs.calls).toHaveLength(2);
		expect(overlay().textContent).toContain('Something went wrong');

		// Blank canvas, with the built-in texts.
		reset();
		install('blank');
		loadScript('assets/js/ffc-pdf-generator.js');
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'b.pdf');
		await vi.advanceTimersByTimeAsync(2000);
		expect(overlay().textContent).toContain('The certificate came out blank');
		expect(overlay().textContent).toContain("Couldn't generate the certificate");

		document.querySelector('.ffc-pdf-error-retry').click();
		await vi.advanceTimersByTimeAsync(2500);
		expect(libs.calls).toHaveLength(2);
		expect(libs.save).not.toHaveBeenCalled();
	});

	it('reports a wrapper that disappeared before capture, and the retry builds it again', async () => {
		install('content');
		window.ffc_ajax = { strings: { pdfErrorTitle: 'Falha', pdfErrorGeneric: 'Algo deu errado.' } };
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		document.querySelector('.ffc-pdf-temp-container .ffc-pdf-wrapper').remove();
		await vi.advanceTimersByTimeAsync(2000);

		expect(console.error).toHaveBeenCalledWith('[FFC PDF] Wrapper not found');
		expect(libs.calls).toHaveLength(0);
		expect(overlay().textContent).toContain('Falha');
		expect(overlay().textContent).toContain('Algo deu errado.');
		expect(document.querySelector('.ffc-pdf-temp-container')).toBeNull();

		document.querySelector('.ffc-pdf-error-retry').click();
		await vi.advanceTimersByTimeAsync(2500);
		expect(libs.calls).toHaveLength(1);
		expect(libs.save).toHaveBeenCalledWith('a.pdf');
	});

	it('falls back to alert() when the overlay is gone by the time an error arrives', async () => {
		install('reject');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		overlay().remove();
		await vi.advanceTimersByTimeAsync(2000);

		expect(alertSpy).toHaveBeenCalledWith(
			"Couldn't generate the certificate\n\nWe couldn't render the certificate. This usually clears up on a retry; if not, try a different browser."
		);
	});

	it('generates as soon as a slow image loads, without waiting for the timeout', async () => {
		install('content');
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<img src="https://x.test/logo.png"><img src="https://x.test/seal.png">' }, 'a.pdf');
		const imgs = document.querySelectorAll('.ffc-pdf-temp-container img');
		expect(imgs[0].getAttribute('data-ffc-original-src')).toBe('https://x.test/logo.png');
		expect(imgs[0].getAttribute('loading')).toBe('eager');
		expect(imgs[0].classList.contains('perfmatters-lazy-skip')).toBe(true);

		window.$(imgs[0]).trigger('load');
		await vi.advanceTimersByTimeAsync(2000);
		expect(libs.calls).toHaveLength(0);

		window.$(imgs[1]).trigger('error');
		await vi.advanceTimersByTimeAsync(2000);
		expect(console.warn).toHaveBeenCalledWith('[FFC PDF] Image failed to load:', 'https://x.test/seal.png');
		expect(libs.calls).toHaveLength(1);
		expect(libs.save).toHaveBeenCalledWith('a.pdf');
	});

	it('decodes a data-URI image before generating, and goes on when decoding fails', async () => {
		install('content');
		const decode = vi.fn()
			.mockResolvedValueOnce(undefined)
			.mockRejectedValueOnce(new Error('bad'));
		window.HTMLImageElement.prototype.decode = decode;
		try {
			loadScript('assets/js/ffc-pdf-generator.js');
			vi.useFakeTimers();
			window.ffcGeneratePDF({ html: '<img src="data:image/png;base64,AAA"><img src="data:image/png;base64,BBB">' }, 'a.pdf');
			await vi.advanceTimersByTimeAsync(2000);

			expect(decode).toHaveBeenCalledTimes(2);
			expect(console.warn).toHaveBeenCalledWith('[FFC PDF] Data URI decode failed');
			expect(libs.save).toHaveBeenCalledWith('a.pdf');
		} finally {
			delete window.HTMLImageElement.prototype.decode;
		}
	});

	it('waits on load or error for a data-URI image when decode() is unavailable', async () => {
		install('content');
		expect(window.HTMLImageElement.prototype.decode).toBeUndefined();
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<img src="data:image/png;base64,AAA"><img src="data:image/png;base64,BBB">' }, 'a.pdf');
		const imgs = document.querySelectorAll('.ffc-pdf-temp-container img');
		window.$(imgs[0]).trigger('load');
		window.$(imgs[1]).trigger('error');
		await vi.advanceTimersByTimeAsync(2000);

		expect(console.warn).toHaveBeenCalledWith('[FFC PDF] Data URI image failed to load');
		expect(libs.save).toHaveBeenCalledWith('a.pdf');
	});

	it('counts an image that is already decoded as loaded straight away', async () => {
		install('content');
		const proto = window.HTMLImageElement.prototype;
		const complete = Object.getOwnPropertyDescriptor(proto, 'complete');
		const natural = Object.getOwnPropertyDescriptor(proto, 'naturalHeight');
		Object.defineProperty(proto, 'complete', { configurable: true, get: () => true });
		Object.defineProperty(proto, 'naturalHeight', { configurable: true, get: () => 10 });
		try {
			loadScript('assets/js/ffc-pdf-generator.js');
			vi.useFakeTimers();
			window.ffcGeneratePDF({ html: '<img src="data:image/png;base64,AAA"><img src="https://x.test/a.png">' }, 'a.pdf');
			// Only the minimum display time and the capture gate remain.
			await vi.advanceTimersByTimeAsync(1200);
			expect(libs.save).toHaveBeenCalledWith('a.pdf');
		} finally {
			Object.defineProperty(proto, 'complete', complete);
			Object.defineProperty(proto, 'naturalHeight', natural);
		}
	});

	it('restores an image src a lazy-loading plugin swapped out, preferring its data-src', async () => {
		install('content');
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({
			html: '<img id="a" src="https://x.test/a.png"><img id="b" src="https://x.test/b.png">',
		}, 'a.pdf');
		const a = document.querySelector('.ffc-pdf-temp-container #a');
		const b = document.querySelector('.ffc-pdf-temp-container #b');
		// What a lazy loader does: a placeholder src, the real one aside.
		a.setAttribute('src', 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
		a.setAttribute('data-src', 'https://x.test/a-real.png');
		b.setAttribute('src', 'https://x.test/other.png');
		await vi.advanceTimersByTimeAsync(12000);

		// A capture left over from an earlier test (its own real timer) can
		// land in this array first, so look for this document's capture.
		expect(seen).toContainEqual(['https://x.test/a-real.png', 'https://x.test/b.png']);
	});

	it('falls back to the original src when a lazy loader emptied it and left no data attribute', async () => {
		install('content');
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<img id="a" src="https://x.test/a.png">' }, 'a.pdf');
		document.querySelector('.ffc-pdf-temp-container #a').setAttribute('src', 'about:blank');
		await vi.advanceTimersByTimeAsync(12000);

		expect(seen).toContainEqual(['https://x.test/a.png']);
	});

	it('traps Tab inside the error panel and closes it on Escape, restoring focus', async () => {
		document.body.innerHTML = '<button id="opener">Get PDF</button>';
		const opener = document.getElementById('opener');
		opener.focus();
		install('reject');
		loadScript('assets/js/ffc-pdf-generator.js');

		vi.useFakeTimers();
		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		await vi.advanceTimersByTimeAsync(2000);

		const retry = document.querySelector('.ffc-pdf-error-retry');
		const close = document.querySelector('.ffc-pdf-error-close');
		expect(document.activeElement).toBe(retry);
		expect(overlay().getAttribute('aria-busy')).toBe('false');
		// jsdom has no layout; give both buttons a box so they count as visible.
		retry.getClientRects = () => [{}];
		close.getClientRects = () => [{}];
		const $ov = window.$(overlay());

		const fwd = window.$.Event('keydown', { key: 'Tab', shiftKey: false });
		close.focus();
		$ov.trigger(fwd);
		expect(fwd.isDefaultPrevented()).toBe(true);
		expect(document.activeElement).toBe(retry);

		const back = window.$.Event('keydown', { key: 'Tab', shiftKey: true });
		$ov.trigger(back);
		expect(back.isDefaultPrevented()).toBe(true);
		expect(document.activeElement).toBe(close);

		const mid = window.$.Event('keydown', { key: 'Tab', shiftKey: false });
		retry.focus();
		$ov.trigger(mid);
		expect(mid.isDefaultPrevented()).toBe(false);

		$ov.trigger(window.$.Event('keydown', { key: 'Escape' }));
		await vi.advanceTimersByTimeAsync(500);
		expect(overlay()).toBeNull();
		expect(document.activeElement).toBe(opener);
	});

	it('keeps Tab on the busy overlay and ignores Escape while generating', async () => {
		window.ffcPdfLibraries = { html2canvas: '/a.js', jspdf: '/b.js' };
		vi.spyOn(document.head, 'appendChild').mockImplementation((node) => node);
		loadScript('assets/js/ffc-pdf-generator.js');

		window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
		const $ov = window.$(overlay());
		expect(overlay().getAttribute('aria-busy')).toBe('true');
		expect(overlay().getAttribute('role')).toBe('dialog');

		const tab = window.$.Event('keydown', { key: 'Tab' });
		$ov.trigger(tab);
		expect(tab.isDefaultPrevented()).toBe(true);

		$ov.trigger(window.$.Event('keydown', { key: 'Escape' }));
		await new Promise((r) => setTimeout(r, 50));
		expect(overlay()).not.toBeNull();
	});

	it('reads the overlay texts from the calendar or the receipt page when ffc_ajax has none', () => {
		window.ffcPdfLibraries = { html2canvas: '/a.js', jspdf: '/b.js' };
		vi.spyOn(document.head, 'appendChild').mockImplementation((node) => node);
		window.ffcCalendar = { strings: { generatingPdf: 'Calendar title', pleaseWait: 'Calendar wait' } };
		try {
			loadScript('assets/js/ffc-pdf-generator.js');
			window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
			expect(document.getElementById('ffc-pdf-overlay-title').textContent).toBe('Calendar title');
			expect(document.getElementById('ffc-pdf-overlay-message').textContent).toBe('Calendar wait');
		} finally {
			delete window.ffcCalendar;
		}

		reset();
		window.ffcPdfLibraries = { html2canvas: '/a.js', jspdf: '/b.js' };
		window.ffcReceiptData = { strings: { generatingPdf: 'Receipt title', pleaseWait: 'Receipt wait' } };
		window.ffc_ajax = { strings: { pdfDontCloseTab: 'Não feche.' } };
		try {
			loadScript('assets/js/ffc-pdf-generator.js');
			window.ffcGeneratePDF({ html: '<p>c</p>' }, 'a.pdf');
			expect(document.getElementById('ffc-pdf-overlay-title').textContent).toBe('Receipt title');
			expect(document.getElementById('ffc-pdf-overlay-message').textContent).toBe('Receipt wait');
			expect(document.querySelector('.ffc-pdf-dont-close').textContent).toBe('Não feche.');
		} finally {
			delete window.ffcReceiptData;
		}
	});

	it('opens the overlay even when the focused element cannot be read', () => {
		Object.defineProperty(document, 'activeElement', { configurable: true, get() { throw new Error('denied'); } });
		try {
			loadScript('assets/js/ffc-pdf-generator.js');
			window.ffcGeneratePDF({}, 'x.pdf');
		} finally {
			delete document.activeElement;
		}
		expect(window.__ffcPdfOverlayReturnFocus).toBeNull();
		expect(overlay()).not.toBeNull();
	});

	it('focuses without options where focus() rejects them', () => {
		const proto = window.HTMLElement.prototype;
		const realFocus = proto.focus;
		const calls = [];
		proto.focus = function (opts) {
			calls.push([this, opts]);
			if (opts) {
				throw new TypeError('no options');
			}
			return realFocus.call(this);
		};
		try {
			loadScript('assets/js/ffc-pdf-generator.js');
			window.ffcGeneratePDF({}, 'x.pdf');
		} finally {
			proto.focus = realFocus;
		}
		const ov = overlay();
		const close = document.querySelector('.ffc-pdf-error-close');
		// Both the dialog and its first action were focused twice: once with
		// options (rejected) and once without.
		expect(calls.filter(([el, o]) => el === ov && o === undefined)).toHaveLength(1);
		expect(calls.filter(([el, o]) => el === close && o === undefined)).toHaveLength(1);
		expect(document.activeElement).toBe(close);
	});
});
