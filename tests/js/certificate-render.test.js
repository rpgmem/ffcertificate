/**
 * Certificate render preparation (#2569, #2570): the font wait and the
 * shrink-to-fit both captures run.
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

	it('prepare() fits only once the fonts are ready', async () => {
		let release;
		setFonts({ ready: new Promise((resolve) => { release = resolve; }) });
		const root = document.createElement('div');
		const el = fitted(root, 'ffc-fit', 30, 'width', 600, 800);

		const pending = window.FFCCertificateRender.prepare(root);
		await Promise.resolve();
		expect(el.style.fontSize).toBe('30px');

		release();
		await pending;
		expect(parseFloat(el.style.fontSize)).toBeLessThan(30);
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

/**
 * A fitted element whose measured extent grows with its font size, the way a
 * line of text does. jsdom has no layout, so the box is described here: the
 * text at `basePx` measures `textAtBase` against a box of `box`.
 */
function fitted(root, cls, basePx, axis, box, textAtBase) {
	const el = document.createElement('div');
	el.className = cls;
	el.style.fontSize = basePx + 'px';
	const size = () => parseFloat(el.style.fontSize);
	const extent = () => textAtBase * size() / basePx;
	if (axis === 'width') {
		Object.defineProperty(el, 'scrollWidth', { get: extent, configurable: true });
		Object.defineProperty(el, 'clientWidth', { get: () => box, configurable: true });
	} else {
		Object.defineProperty(el, 'scrollHeight', { get: extent, configurable: true });
		Object.defineProperty(el, 'clientHeight', { get: () => box, configurable: true });
	}
	root.appendChild(el);
	return el;
}

describe('FFCCertificateRender.fit (#2570)', () => {
	beforeEach(() => {
		delete window.FFCCertificateRender;
		loadScript('assets/js/ffc-certificate-render.js');
	});

	it('shrinks a one-line value until it fits the width', () => {
		const root = document.createElement('div');
		// 30px measures 800 in a 600 box: 22.5px is the exact fit.
		const el = fitted(root, 'ffc-fit', 30, 'width', 600, 800);

		window.FFCCertificateRender.fit(root);

		const px = parseFloat(el.style.fontSize);
		expect(px).toBeLessThanOrEqual(22.5);
		expect(px).toBeGreaterThan(22.3);
		expect(el.scrollWidth).toBeLessThanOrEqual(el.clientWidth);
	});

	it('shrinks a wrapping value until it fits the height', () => {
		const root = document.createElement('div');
		// 20px measures 150 in a 100 box: about 13.3px fits.
		const el = fitted(root, 'ffc-fit-box', 20, 'height', 100, 150);

		window.FFCCertificateRender.fit(root);

		const px = parseFloat(el.style.fontSize);
		expect(px).toBeLessThan(20);
		expect(px).toBeGreaterThan(13);
		expect(el.scrollHeight).toBeLessThanOrEqual(el.clientHeight);
	});

	it('leaves a value that already fits untouched', () => {
		const root = document.createElement('div');
		const wide = fitted(root, 'ffc-fit', 30, 'width', 600, 500);
		const tall = fitted(root, 'ffc-fit-box', 20, 'height', 100, 100);

		window.FFCCertificateRender.fit(root);

		expect(wide.style.fontSize).toBe('30px');
		expect(tall.style.fontSize).toBe('20px');
	});

	it('never shrinks below half the size, even when the floor still overflows', () => {
		const root = document.createElement('div');
		// Needs a tenth of its size to fit: the floor holds at 15px.
		const el = fitted(root, 'ffc-fit', 30, 'width', 100, 1000);

		window.FFCCertificateRender.fit(root);

		expect(el.style.fontSize).toBe('15px');
		expect(window.FFCCertificateRender.FIT_FLOOR).toBe(0.5);
	});

	it('measures an inline value against the box around it', () => {
		const root = document.createElement('div');
		const parent = document.createElement('p');
		Object.defineProperty(parent, 'clientWidth', { get: () => 400, configurable: true });
		const span = document.createElement('span');
		span.className = 'ffc-fit';
		span.style.fontSize = '20px';
		span.getBoundingClientRect = () => ({ width: 600 * parseFloat(span.style.fontSize) / 20 });
		parent.appendChild(span);
		root.appendChild(parent);

		window.FFCCertificateRender.fit(root);

		const px = parseFloat(span.style.fontSize);
		expect(px).toBeLessThanOrEqual(400 * 20 / 600);
		expect(px).toBeGreaterThan(13);
	});

	it('ignores elements without either class and a missing root', () => {
		const root = document.createElement('div');
		const plain = fitted(root, 'something-else', 30, 'width', 100, 1000);

		window.FFCCertificateRender.fit(root);
		window.FFCCertificateRender.fit(null);

		expect(plain.style.fontSize).toBe('30px');
	});
});
