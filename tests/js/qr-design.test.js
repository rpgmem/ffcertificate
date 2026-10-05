// Live preview of the global QR design on the QR Code settings tab (#1563).
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
		<form>
			<input id="qr_default_margin" value="3">
			<select id="qr_default_error_level"><option value="Q" selected>Q</option></select>
			<select data-ffc-qr-design="qr_design_dots"><option value="dots" selected>dots</option></select>
			<select data-ffc-qr-design="qr_design_eye_frame"><option value="leaf" selected>leaf</option></select>
			<select data-ffc-qr-design="qr_design_eye_ball"><option value="circle" selected>circle</option></select>
			<input data-ffc-qr-design="qr_design_color" value="#112233">
			<input data-ffc-qr-design="qr_design_background" value="#ffffff">
			<input data-ffc-qr-design="qr_design_eye_frame_color" value="#445566">
			<input data-ffc-qr-design="qr_design_eye_ball_color" value="#778899">
			<input type="checkbox" data-ffc-qr-design="qr_design_gradient">
			<input data-ffc-qr-design="qr_design_color_end" value="#2271b1">
			<div id="ffc-qr-design-preview"></div>
			<p id="ffc-qr-design-checks"></p>
		</form>`;
}

beforeAll(() => {
	window.ffcQrDesign = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		action: 'ffc_qr_design_preview',
		nonce: 'qr-nonce',
		i18n: { lowContrast: 'Low (%s:1)', inverted: 'Inverted', ok: 'Readable', error: 'Failed' },
	};
	if (!window.FFC || !window.FFC.request) { loadScript('assets/js/ffc-core.js'); }
	loadScript('assets/js/ffc-qr-design.js');
});

beforeEach(() => {
	mount();
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('ffc-qr-design.js', () => {
	it('collects the unsaved form values into the endpoint shape', () => {
		window.$('[data-ffc-qr-design="qr_design_gradient"]').prop('checked', true);

		expect(window.FFC.QrDesign.collect(window.$('form'))).toEqual({
			design: {
				dots: 'dots',
				eye_frame: 'leaf',
				eye_ball: 'circle',
				color: '#112233',
				background: '#ffffff',
				eye_frame_color: '#445566',
				eye_ball_color: '#778899',
				gradient: '1',
				color_end: '#2271b1',
			},
			margin: '3',
			error_level: 'Q',
		});
	});

	it('posts to the preview action and shows the SVG with a readable status', async () => {
		const spy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { svg: '<svg id="drawn"></svg>', checks: { inverted: false, low_contrast: false, min_ratio: 21 } } } }));

		await window.FFC.QrDesign.refresh(window.$('form'));

		expect(spy.mock.calls[0][1]).toMatchObject({ action: 'ffc_qr_design_preview', nonce: 'qr-nonce', margin: '3' });
		expect(document.getElementById('drawn')).not.toBeNull();
		expect(window.$('#ffc-qr-design-checks').text()).toBe('Readable');
	});

	it('warns about low contrast with the measured ratio', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { svg: '<svg></svg>', checks: { inverted: false, low_contrast: true, min_ratio: 2.3 } } } }));

		await window.FFC.QrDesign.refresh(window.$('form'));

		const $out = window.$('#ffc-qr-design-checks');
		expect($out.text()).toBe('Low (2.3:1)');
		expect($out.hasClass('is-warning')).toBe(true);
	});

	it('flags an inverted code as an error', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { svg: '<svg></svg>', checks: { inverted: true, low_contrast: false, min_ratio: 21 } } } }));

		await window.FFC.QrDesign.refresh(window.$('form'));

		expect(window.$('#ffc-qr-design-checks').hasClass('is-error')).toBe(true);
		expect(window.$('#ffc-qr-design-checks').text()).toBe('Inverted');
	});

	it('reports a failed request', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));

		await window.FFC.QrDesign.refresh(window.$('form'));

		expect(window.$('#ffc-qr-design-checks').text()).toBe('Failed');
	});

	it('a stale answer never overwrites a newer one', async () => {
		const pending = [];
		vi.spyOn(window.$, 'post').mockImplementation(() => {
			const chain = { done: (cb) => { pending.push(cb); return chain; }, fail: () => chain };
			return chain;
		});

		const first = window.FFC.QrDesign.refresh(window.$('form'));
		const second = window.FFC.QrDesign.refresh(window.$('form'));
		pending[1]({ success: true, data: { svg: '<svg id="newer"></svg>', checks: {} } });
		pending[0]({ success: true, data: { svg: '<svg id="older"></svg>', checks: {} } });
		await Promise.all([first, second]);

		expect(document.getElementById('newer')).not.toBeNull();
		expect(document.getElementById('older')).toBeNull();
	});

	it('init refreshes on load and debounces changes', async () => {
		vi.useFakeTimers();
		const spy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { svg: '<svg></svg>', checks: {} } } }));

		window.FFC.QrDesign.init();
		expect(spy).toHaveBeenCalledTimes(1);

		window.$('[data-ffc-qr-design="qr_design_color"]').val('#000000').trigger('input');
		window.$('[data-ffc-qr-design="qr_design_color"]').trigger('change');
		vi.advanceTimersByTime(300);
		expect(spy).toHaveBeenCalledTimes(2);
		vi.useRealTimers();
	});

	it('does nothing on a screen without the preview', () => {
		document.body.innerHTML = '<form></form>';
		const spy = vi.spyOn(window.$, 'post');

		window.FFC.QrDesign.init();

		expect(spy).not.toHaveBeenCalled();
	});
});
