// Short URLs → QR Code Generator (#1563).
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(undefined); return chain; };
	return chain;
}

function flush() { return new Promise((r) => setTimeout(r, 0)); }

function mount() {
	document.body.innerHTML = `
		<form id="ffc-qr-generator">
			<input type="radio" name="type" value="url" checked>
			<input type="radio" name="type" value="wifi">
			<table data-ffc-qr-type="url"><tr><td>
				<input id="ffc-qr-url" data-ffc-qr-field="url:url" value="example.com">
				<input id="ffc-qr-url-title" value="Flyer">
				<button type="button" id="ffc-qr-shorten">Shorten</button>
			</td></tr></table>
			<table data-ffc-qr-type="wifi" hidden><tr><td>
				<input data-ffc-qr-field="wifi:ssid" value="Net">
				<input type="checkbox" data-ffc-qr-field="wifi:hidden" value="1" checked>
			</td></tr></table>
			<select data-ffc-qr-design="qr_design_dots"><option value="dots" selected>dots</option><option value="square">square</option></select>
			<input type="checkbox" data-ffc-qr-design="qr_design_gradient" value="1" checked>
			<input type="hidden" data-ffc-qr-design="qr_design_logo_id" value="9">
			<img id="ffc-qr-logo-thumb" src="logo.png">
			<button type="button" id="ffc-qr-design-reset">Reset</button>
			<input id="qr_default_margin" value="2">
			<select id="qr_default_error_level"><option value="M" selected>M</option><option value="Q">Q</option></select>
			<div id="ffc-qr-generator-preview"></div>
			<p id="ffc-qr-generator-usage"></p>
			<p id="ffc-qr-generator-status"></p>
			<select id="ffc-qr-png-width"><option value="2000" selected>2000</option></select>
			<button type="button" id="ffc-qr-download-png" disabled>PNG</button>
			<button type="button" id="ffc-qr-download-svg" disabled>SVG</button>
		</form>`;
}

const OK = { svg: '<svg id="drawn"></svg>', payload: 'https://example.com', usage: { bytes: 19, capacity: 2331, percent: 1, version: 2, dense: false }, checks: { inverted: false, low_contrast: false, min_ratio: 21, caption_contrast: true } };

beforeAll(() => {
	window.ffcQrGenerator = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		generate: 'ffc_qr_generate',
		generateNonce: 'gen-nonce',
		shorten: 'ffc_qr_shorten',
		shortenNonce: 'short-nonce',
		remember: 'ffc_qr_remember',
		rememberNonce: 'remember-nonce',
		i18n: { usage: '%1$d of %2$d bytes (%3$d%%)', dense: 'Dense (version %d)', lowContrast: 'Low (%s:1)', inverted: 'Inverted', captionContrast: 'Caption', ok: 'Readable', error: 'Failed', shortened: 'Shortened', reset: 'Reset done' },
	};
	window.ffcQrDesign = { i18n: {} };
	if (!window.FFC || !window.FFC.request) { loadScript('assets/js/ffc-core.js'); }
	loadScript('assets/js/ffc-qr-raster.js');
	loadScript('assets/js/ffc-qr-design.js');
	loadScript('assets/js/ffc-qr-generator.js');
});

beforeEach(() => {
	mount();
	window.$(document).off();
	window.$('#ffc-qr-generator').off();
});

afterEach(() => {
	vi.restoreAllMocks();
	vi.useRealTimers();
});

describe('ffc-qr-generator.js', () => {
	it('fills positional and sequential placeholders', () => {
		expect(window.FFC.QrGenerator.fill('%1$d of %2$d (%3$d%%)', [5, 10, 50])).toBe('5 of 10 (50%)');
		expect(window.FFC.QrGenerator.fill('v%d', [7])).toBe('v7');
	});

	it('collects only the active type\'s fields, with the design', () => {
		const $form = window.$('#ffc-qr-generator');
		expect(window.FFC.QrGenerator.collect($form)).toMatchObject({ type: 'url', fields: { url: 'example.com' }, margin: '2', error_level: 'M' });
		expect(window.FFC.QrGenerator.collect($form).design.dots).toBe('dots');

		window.$('input[value="wifi"]').prop('checked', true);
		expect(window.FFC.QrGenerator.collect($form).fields).toEqual({ ssid: 'Net', hidden: '1' });
	});

	it('draws the code, reports usage and enables the downloads', async () => {
		const spy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: OK } }));

		await window.FFC.QrGenerator.refresh(window.$('#ffc-qr-generator'));

		expect(spy.mock.calls[0][1]).toMatchObject({ action: 'ffc_qr_generate', nonce: 'gen-nonce', type: 'url' });
		expect(document.getElementById('drawn')).not.toBeNull();
		expect(window.$('#ffc-qr-generator-usage').text()).toBe('19 of 2331 bytes (1%)');
		expect(window.$('#ffc-qr-generator-status').text()).toBe('Readable');
		expect(window.$('#ffc-qr-download-png').prop('disabled')).toBe(false);
	});

	it('warns about a dense code', async () => {
		const dense = Object.assign({}, OK, { usage: Object.assign({}, OK.usage, { version: 14, dense: true }) });
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: dense } }));

		await window.FFC.QrGenerator.refresh(window.$('#ffc-qr-generator'));

		expect(window.$('#ffc-qr-generator-status').text()).toBe('Dense (version 14)');
		expect(window.$('#ffc-qr-generator-status').hasClass('is-warning')).toBe(true);
	});

	it('shows the server\'s reason and disables the downloads on an error', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Enter a valid web address' } } }));

		await window.FFC.QrGenerator.refresh(window.$('#ffc-qr-generator'));

		expect(window.$('#ffc-qr-generator-status').text()).toBe('Enter a valid web address');
		expect(window.$('#ffc-qr-download-svg').prop('disabled')).toBe(true);
		expect(window.$('#ffc-qr-generator-preview').html()).toBe('');
	});

	it('switching type shows its fields and redraws', async () => {
		const spy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: OK } }));
		window.FFC.QrGenerator.init();

		window.$('input[value="wifi"]').prop('checked', true).trigger('change');
		await flush();

		expect(window.$('[data-ffc-qr-type="wifi"]').prop('hidden')).toBe(false);
		expect(window.$('[data-ffc-qr-type="url"]').prop('hidden')).toBe(true);
		expect(spy.mock.calls[0][1].type).toBe('wifi');
	});

	it('downloads the SVG and the PNG at the chosen width', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: OK } }));
		const download = vi.spyOn(window.FFC.QrRaster, 'download').mockImplementation(() => {});
		const toPng = vi.spyOn(window.FFC.QrRaster, 'toPng').mockResolvedValue('UE5H');
		window.FFC.QrGenerator.init();
		await window.FFC.QrGenerator.refresh(window.$('#ffc-qr-generator'));

		window.$('#ffc-qr-download-svg').trigger('click');
		window.$('#ffc-qr-download-png').trigger('click');
		await flush();

		expect(download.mock.calls[0][2]).toBe('image/svg+xml');
		expect(download.mock.calls[0][1]).toMatch(/^qr-url-\d{4}-\d{2}-\d{2}\.svg$/);
		expect(toPng.mock.calls[0][1]).toBe(2000);
		expect(download.mock.calls[1]).toEqual(['UE5H', expect.stringMatching(/\.png$/), 'image/png']);
	});

	it('shorten puts the short URL in the address field and redraws', async () => {
		const spy = vi.spyOn(window.$, 'post').mockImplementation((url, payload) => postChain({
			done: payload.action === 'ffc_qr_shorten'
				? { success: true, data: { short_url: 'https://site.test/go/abc' } }
				: { success: true, data: OK },
		}));
		window.FFC.QrGenerator.init();

		window.$('#ffc-qr-shorten').trigger('click');
		await flush();

		expect(spy.mock.calls[0][1]).toMatchObject({ action: 'ffc_qr_shorten', nonce: 'short-nonce', url: 'example.com', title: 'Flyer' });
		expect(window.$('#ffc-qr-url').val()).toBe('https://site.test/go/abc');
		expect(spy.mock.calls[1][1].fields.url).toBe('https://site.test/go/abc');
	});

	it('a typed change redraws after a pause', () => {
		vi.useFakeTimers();
		const spy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: OK } }));
		window.FFC.QrGenerator.init();

		window.$('#ffc-qr-url').val('example.org').trigger('input');
		expect(spy).not.toHaveBeenCalled();
		vi.advanceTimersByTime(350);
		expect(spy).toHaveBeenCalledTimes(1);
	});

	it('shows the chosen network\'s prefix before the user name', () => {
		document.body.innerHTML = `
			<form id="ffc-qr-generator">
				<select id="ffc-qr-network">
					<option value="instagram" data-ffc-qr-prefix="https://www.instagram.com/">Instagram</option>
					<option value="github" data-ffc-qr-prefix="https://github.com/">GitHub</option>
				</select>
				<code id="ffc-qr-social-prefix">https://www.instagram.com/</code>
			</form>`;
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: OK } }));
		window.FFC.QrGenerator.init();

		window.$('#ffc-qr-network').val('github').trigger('change');

		expect(window.$('#ffc-qr-social-prefix').text()).toBe('https://github.com/');
	});

	it('does nothing on another screen', () => {
		document.body.innerHTML = '<form></form>';
		const spy = vi.spyOn(window.$, 'post');

		window.FFC.QrGenerator.init();

		expect(spy).not.toHaveBeenCalled();
	});

	it('a download remembers the design, never the content (#1568)', async () => {
		const spy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: OK } }));
		vi.spyOn(window.FFC.QrRaster, 'download').mockImplementation(() => {});
		vi.spyOn(window.FFC.QrRaster, 'toPng').mockResolvedValue('UE5H');
		window.FFC.QrGenerator.init();
		await window.FFC.QrGenerator.refresh(window.$('#ffc-qr-generator'));

		window.$('#ffc-qr-download-svg').trigger('click');
		window.$('#ffc-qr-download-png').trigger('click');
		await flush();

		const remembered = spy.mock.calls.filter((c) => c[1].action === 'ffc_qr_remember').map((c) => c[1]);
		expect(remembered).toHaveLength(2);
		expect(remembered[0]).toMatchObject({ nonce: 'remember-nonce', logo_id: '9', margin: '2', error_level: 'M' });
		expect(remembered[0].design).toMatchObject({ dots: 'dots', gradient: '1' });
		expect(remembered[0]).not.toHaveProperty('fields');
		expect(remembered[0]).not.toHaveProperty('type');
	});

	it('reset puts the global design back and redraws (#1568)', async () => {
		const state = { qr_design_dots: 'square', qr_design_gradient: false, qr_design_logo_id: 0, margin: 4, error_level: 'Q' };
		const spy = vi.spyOn(window.$, 'post').mockImplementation((url, payload) => postChain({
			done: payload.action === 'ffc_qr_remember'
				? { success: true, data: { state, logo_thumb: '' } }
				: { success: true, data: OK },
		}));
		window.FFC.QrGenerator.init();

		window.$('#ffc-qr-design-reset').trigger('click');
		await flush();
		await flush();

		expect(spy.mock.calls[0][1]).toMatchObject({ action: 'ffc_qr_remember', nonce: 'remember-nonce', reset: '1' });
		expect(window.$('[data-ffc-qr-design="qr_design_dots"]').val()).toBe('square');
		expect(window.$('[data-ffc-qr-design="qr_design_gradient"]').prop('checked')).toBe(false);
		expect(window.$('[data-ffc-qr-design="qr_design_logo_id"]').val()).toBe('0');
		expect(window.$('#ffc-qr-logo-thumb').prop('hidden')).toBe(true);
		expect(window.$('#qr_default_margin').val()).toBe('4');
		expect(window.$('#qr_default_error_level').val()).toBe('Q');
		expect(spy.mock.calls[1][1]).toMatchObject({ action: 'ffc_qr_generate', margin: '4', error_level: 'Q' });
		expect(window.$('#ffc-qr-generator-status').text()).toBe('Reset done');
		expect(window.$('#ffc-qr-design-reset').prop('disabled')).toBe(false);
	});

	it('a failed reset says so and leaves the fields alone', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Permission denied.' } } }));
		window.FFC.QrGenerator.init();

		window.$('#ffc-qr-design-reset').trigger('click');
		await flush();

		expect(window.$('#ffc-qr-generator-status').text()).toBe('Permission denied.');
		expect(window.$('[data-ffc-qr-design="qr_design_dots"]').val()).toBe('dots');
	});
});
