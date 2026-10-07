// Shared QR image helpers (#1563): saving a payload and rasterising an SVG.
import { describe, it, expect, beforeAll, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-qr-raster.js');
});

afterEach(() => {
	vi.restoreAllMocks();
});

// jsdom neither loads images nor paints canvases: stand in for both.
function stubCanvas(fail, natural) {
	vi.spyOn(window, 'Image').mockImplementation(function () {
		const img = natural ? { naturalWidth: natural[0], naturalHeight: natural[1] } : {};
		Object.defineProperty(img, 'src', {
			set(v) { img._src = v; setTimeout(() => (fail ? img.onerror(new Error('x')) : img.onload()), 0); },
			get() { return img._src; },
		});
		return img;
	});
	const draw = vi.fn();
	vi.spyOn(window.HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ drawImage: draw });
	vi.spyOn(window.HTMLCanvasElement.prototype, 'toDataURL').mockReturnValue('data:image/png;base64,UE5HUE5H');
	return draw;
}

describe('FFC.QrRaster', () => {
	it('toPng draws a square code at the requested width', async () => {
		const draw = stubCanvas(false);

		expect(await window.FFC.QrRaster.toPng('PHN2Zy8+', 300)).toBe('UE5HUE5H');
		expect(draw).toHaveBeenCalledWith(expect.anything(), 0, 0, 300, 300);
	});

	it('toPng keeps a framed code\'s aspect ratio', async () => {
		const draw = stubCanvas(false, [400, 520]);

		await window.FFC.QrRaster.toPng('PHN2Zy8+', 1000);

		expect(draw).toHaveBeenCalledWith(expect.anything(), 0, 0, 1000, 1300);
	});

	it('toPng rejects when the image does not load', async () => {
		stubCanvas(true);

		await expect(window.FFC.QrRaster.toPng('PHN2Zy8+', 300)).rejects.toBeTruthy();
	});

	it('download saves the decoded bytes under the given name and type', () => {
		window.URL.createObjectURL = vi.fn(() => 'blob:fake');
		window.URL.revokeObjectURL = vi.fn();
		const clicks = vi.spyOn(window.HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

		window.FFC.QrRaster.download(btoa('abc'), 'qr.svg', 'image/svg+xml');

		const blob = window.URL.createObjectURL.mock.calls[0][0];
		expect(blob.type).toBe('image/svg+xml');
		expect(clicks).toHaveBeenCalledTimes(1);
		expect(window.URL.revokeObjectURL).toHaveBeenCalledWith('blob:fake');
	});

	it('encode is UTF-8 safe', () => {
		const encoded = window.FFC.QrRaster.encode('<text>Olá — ç</text>');
		const bytes = Uint8Array.from(atob(encoded), (c) => c.charCodeAt(0));

		expect(new TextDecoder().decode(bytes)).toBe('<text>Olá — ç</text>');
	});
});
