/**
 * Certificate render preparation (#2569): the font wait both captures run.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function setFonts(value) {
	Object.defineProperty(document, 'fonts', { value: value, configurable: true });
}

describe('FFCCertificateRender.waitForFonts', () => {
	beforeEach(() => {
		delete window.FFCCertificateRender;
		loadScript('assets/js/ffc-certificate-render.js');
	});

	afterEach(() => {
		vi.useRealTimers();
		delete document.fonts;
	});

	it('resolves at once where the browser has no Font Loading API', async () => {
		setFonts(undefined);
		await expect(window.FFCCertificateRender.waitForFonts()).resolves.toBeUndefined();
	});

	it('waits for the document fonts to be ready', async () => {
		let release;
		setFonts({ ready: new Promise((resolve) => { release = resolve; }) });

		let done = false;
		const pending = window.FFCCertificateRender.waitForFonts(10000).then(() => { done = true; });

		await Promise.resolve();
		expect(done).toBe(false);

		release();
		await pending;
		expect(done).toBe(true);
	});

	it('gives up after the limit, so a font that never arrives does not hold the certificate', async () => {
		vi.useFakeTimers();
		setFonts({ ready: new Promise(() => {}) });

		let done = false;
		const pending = window.FFCCertificateRender.waitForFonts(3000).then(() => { done = true; });

		vi.advanceTimersByTime(2999);
		await Promise.resolve();
		expect(done).toBe(false);

		vi.advanceTimersByTime(1);
		await pending;
		expect(done).toBe(true);
	});

	it('defaults to the published limit', async () => {
		vi.useFakeTimers();
		setFonts({ ready: new Promise(() => {}) });

		const pending = window.FFCCertificateRender.waitForFonts();
		vi.advanceTimersByTime(window.FFCCertificateRender.FONT_WAIT_MS);
		await expect(pending).resolves.toBeUndefined();
	});

	it('still resolves when the fonts promise rejects', async () => {
		setFonts({ ready: Promise.reject(new Error('font failed')) });
		await expect(window.FFCCertificateRender.waitForFonts(10000)).resolves.toBeUndefined();
	});

	it('prepare() waits for the fonts', async () => {
		let release;
		setFonts({ ready: new Promise((resolve) => { release = resolve; }) });

		let done = false;
		const pending = window.FFCCertificateRender.prepare(document.createElement('div')).then(() => { done = true; });

		await Promise.resolve();
		expect(done).toBe(false);
		release();
		await pending;
		expect(done).toBe(true);
	});
});

describe('FFCLayoutPreview runs the preparation before the capture', () => {
	beforeEach(() => {
		delete window.FFCLayoutPreview;
		delete window.FFCCertificateRender;
	});

	afterEach(() => {
		delete window.html2canvas;
		delete window.FFCCertificateRender;
	});

	it('does not capture until prepare() resolves', async () => {
		const order = [];
		let release;
		window.FFCCertificateRender = {
			prepare: vi.fn(() => {
				order.push('prepare');
				return new Promise((resolve) => { release = resolve; });
			})
		};
		window.html2canvas = vi.fn(() => {
			order.push('capture');
			return Promise.resolve({ toDataURL: () => 'data:image/png;base64,AAA' });
		});
		loadScript('assets/js/ffc-layout-preview.js');

		const pending = window.FFCLayoutPreview.capture('<div>page</div>', 1123, 794);

		await new Promise((resolve) => setTimeout(resolve, 0));
		expect(order).toEqual(['prepare']);

		release();
		await pending;
		expect(order).toEqual(['prepare', 'capture']);
		expect(window.FFCCertificateRender.prepare).toHaveBeenCalledTimes(1);
	});
});
