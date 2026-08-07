// Tests for ffc-cert-template-admin.js — the certificate-template edit-screen
// behaviours: (1) lock the core title on shipped defaults, (2) autosave the
// sidebar visibility toggle over AJAX (so the removed Publish box isn't needed).

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// Mock $.post and return a .done/.fail chain, mirroring the file's usage.
function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(spec.fail === true ? undefined : spec.fail); return chain; };
	return chain;
}

function flush() { return Promise.resolve().then(() => Promise.resolve()); }

function config(overrides) {
	return Object.assign({
		ajaxUrl:      '/wp-admin/admin-ajax.php',
		toggleAction: 'ffc_cert_template_toggle_visibility',
		nonce:        'tpl-nonce',
		postId:       9,
		isDefault:    false,
		savingText:   'Saving…',
		savedText:    'Saved',
		errorText:    'Save failed',
	}, overrides || {});
}

function mount(cfg, { withTitle = true, checked = true } = {}) {
	window.ffcCertTemplateAdmin = cfg;
	document.body.innerHTML = `
		${withTitle ? '<input type="text" id="title" value="Model 1">' : ''}
		<label class="ffc-toggle">
			<input type="checkbox" id="ffc_template_visible" name="ffc_template_visible" value="1" ${checked ? 'checked' : ''}>
		</label>
		<span id="ffc_template_visible_status" class="ffc-autosave-status"></span>
	`;
	loadScript('assets/js/ffc-cert-template-admin.js');
}

beforeEach(() => {
	document.body.innerHTML = '';
});

afterEach(() => {
	delete window.ffcCertTemplateAdmin;
	vi.restoreAllMocks();
});

describe('ffc-cert-template-admin', () => {
	it('POSTs the toggle state to the configured endpoint on change', async () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { visible: false } } }));

		mount(config(), { checked: true });
		window.$('#ffc_template_visible').prop('checked', false).trigger('change');
		await flush();

		expect(postSpy).toHaveBeenCalledTimes(1);
		const [url, payload] = postSpy.mock.calls[0];
		expect(url).toBe('/wp-admin/admin-ajax.php');
		expect(payload.action).toBe('ffc_cert_template_toggle_visibility');
		expect(payload.nonce).toBe('tpl-nonce');
		expect(payload.post_id).toBe(9);
		expect(payload.visible).toBe('0');
	});

	it('sends "1" when the toggle is checked on', async () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		mount(config(), { checked: false });
		window.$('#ffc_template_visible').prop('checked', true).trigger('change');
		await flush();

		expect(postSpy.mock.calls[0][1].visible).toBe('1');
	});

	it('shows the "Saved" status on success', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: {} } }));

		mount(config(), { checked: false });
		window.$('#ffc_template_visible').prop('checked', true).trigger('change');
		await flush();

		const $status = window.$('#ffc_template_visible_status');
		expect($status.text()).toBe('Saved');
		expect($status.hasClass('ffc-saved')).toBe(true);
	});

	it('shows the "Save failed" status on AJAX failure', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));

		mount(config(), { checked: false });
		window.$('#ffc_template_visible').prop('checked', true).trigger('change');
		await flush();

		const $status = window.$('#ffc_template_visible_status');
		expect($status.text()).toBe('Save failed');
		expect($status.hasClass('ffc-error')).toBe(true);
	});

	it('locks the title field when the template is a shipped default', () => {
		mount(config({ isDefault: true }));
		expect(document.getElementById('title').readOnly).toBe(true);
		expect(document.getElementById('title').getAttribute('aria-readonly')).toBe('true');
	});

	it('leaves the title editable for a user template', () => {
		mount(config({ isDefault: false }));
		expect(document.getElementById('title').readOnly).toBe(false);
	});

	it('does not bind the toggle when there is no post id (new auto-draft)', async () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));
		mount(config({ postId: 0 }), { checked: false });
		window.$('#ffc_template_visible').prop('checked', true).trigger('change');
		await flush();
		expect(postSpy).not.toHaveBeenCalled();
	});
});
