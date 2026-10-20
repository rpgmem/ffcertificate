/**
 * ffc-layout-preview.js — the page the preview captures.
 *
 * html2canvas is stubbed: the contract under test is what the helper hands it
 * (the page, its size, the scale the PDF uses) and what it does with the canvas.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

describe('ffc-layout-preview.js', () => {
	let canvas;

	beforeEach(() => {
		document.body.innerHTML = '';
		canvas = { toDataURL: vi.fn(() => 'data:image/png;base64,AAAA') };
		window.html2canvas = vi.fn(() => Promise.resolve(canvas));
		loadScript('assets/js/ffc-layout-preview.js');
	});

	afterEach(() => {
		delete window.html2canvas;
		delete window.FFCLayoutPreview;
	});

	it('captures the page at its size with the PDF scale and no background of its own', async () => {
		const url = await window.FFCLayoutPreview.capture('<div>page</div>', 1123, 794);

		expect(url).toBe('data:image/png;base64,AAAA');
		const [element, options] = window.html2canvas.mock.calls[0];
		expect(element.innerHTML).toBe('<div>page</div>');
		expect(options).toMatchObject({ scale: 2, width: 1123, height: 794, backgroundColor: null });
	});

	it('removes its scratch element once the image is taken', async () => {
		await window.FFCLayoutPreview.capture('<div>page</div>', 794, 1123);

		expect(document.querySelectorAll('[aria-hidden="true"]').length).toBe(0);
	});

	it('rejects, and still cleans up, when the canvas is tainted', async () => {
		canvas.toDataURL = vi.fn(() => { throw new Error('SecurityError'); });

		await expect(window.FFCLayoutPreview.capture('<p>page</p>', 1123, 794)).rejects.toThrow('SecurityError');
		expect(document.querySelectorAll('[aria-hidden="true"]').length).toBe(0);
	});

	it('does not wait forever on an image that never loads', async () => {
		const pending = window.FFCLayoutPreview.capture('<img src="https://example.test/bg.png">', 1123, 794);
		await new Promise((resolve) => setTimeout(resolve, 0));
		const img = document.querySelector('[aria-hidden="true"] img');
		// A failed image still lets the capture finish: it paints without the image.
		img.dispatchEvent(new Event('error'));

		await expect(pending).resolves.toBe('data:image/png;base64,AAAA');
	});
});
