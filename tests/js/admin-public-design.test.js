// #2341 — Settings → Public Design: the contrast warning follows the colours
// as they change (the server's own check, asked for live), and the colour
// inputs are disabled while the design is off.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function chain(response) {
	return { done: (cb) => { cb(response); return { fail: () => {} }; } };
}

function mount(checked) {
	document.body.innerHTML = `
		<input type="checkbox" id="public_design" ${checked ? 'checked' : ''}>
		<input type="color" id="public_design_accent" value="#2271b1">
		<input type="color" id="public_design_border" value="#8e8f91">
		<input type="color" id="public_design_muted" value="#646970">
		<input type="color" id="public_design_error" value="#d63638">
		<input type="color" id="public_design_info" value="#006ba1">
		<div class="ffc-public-design-contrast" aria-live="polite"></div>
	`;
}

beforeAll(() => {
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: '' };
	loadScript('assets/js/ffc-core.js');
});

beforeEach(() => {
	window.ffcPublicDesign = { ajaxUrl: '/wp-admin/admin-ajax.php', action: 'ffc_public_design_check', nonce: 'N' };
	vi.useFakeTimers();
});

afterEach(() => {
	vi.useRealTimers();
	vi.restoreAllMocks();
	document.body.innerHTML = '';
});

async function boot(checked) {
	mount(checked);
	loadScript('assets/js/ffc-admin-public-design.js');
	window.$.ready && window.$(document).trigger('ready');
	await vi.advanceTimersByTimeAsync(0);
}

describe('Public Design tab', () => {
	it('asks the server for the warning as a colour changes, and shows it', async () => {
		const post = vi.spyOn(window.$, 'post').mockReturnValue(chain({ success: true, data: { warning: 'Some colours are hard to read: field borders on white (1.16:1, at least 3.0:1)' } }));
		await boot(true);

		window.$('#public_design_border').val('#eeeeee').trigger('input');
		await vi.advanceTimersByTimeAsync(299);
		expect(post).not.toHaveBeenCalled();
		await vi.advanceTimersByTimeAsync(1);

		expect(post).toHaveBeenCalledTimes(1);
		const data = post.mock.calls[0][1];
		expect(data.action).toBe('ffc_public_design_check');
		expect(data.nonce).toBe('N');
		expect(data.border).toBe('#eeeeee');
		const note = document.querySelector('.ffc-public-design-contrast .ffc-notice');
		expect(note).not.toBeNull();
		expect(note.textContent).toContain('field borders on white');
	});

	it('clears the warning when the colours pass', async () => {
		vi.spyOn(window.$, 'post').mockReturnValue(chain({ success: true, data: { warning: '' } }));
		await boot(true);
		document.querySelector('.ffc-public-design-contrast').innerHTML = '<div class="ffc-notice">old</div>';
		window.$('#public_design_accent').val('#000000').trigger('change');
		await vi.advanceTimersByTimeAsync(300);
		expect(document.querySelector('.ffc-public-design-contrast').children.length).toBe(0);
	});

	it('disables the colours while the design is off, and asks nothing', async () => {
		const post = vi.spyOn(window.$, 'post').mockReturnValue(chain({ success: true, data: { warning: 'x' } }));
		await boot(false);
		expect(document.getElementById('public_design_accent').disabled).toBe(true);

		window.$('#public_design').prop('checked', true).trigger('change');
		expect(document.getElementById('public_design_accent').disabled).toBe(false);
		await vi.advanceTimersByTimeAsync(300);
		expect(post).toHaveBeenCalledTimes(1);

		window.$('#public_design').prop('checked', false).trigger('change');
		expect(document.getElementById('public_design_info').disabled).toBe(true);
		await vi.advanceTimersByTimeAsync(300);
		expect(post).toHaveBeenCalledTimes(1);
		expect(document.querySelector('.ffc-public-design-contrast').children.length).toBe(0);
	});
});
