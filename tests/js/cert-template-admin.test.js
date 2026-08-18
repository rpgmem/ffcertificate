// Tests for ffc-cert-template-admin.js — the certificate-template edit-screen
// behaviours: (1) lock the core title on shipped defaults, (2) autosave the
// sidebar visibility toggle over AJAX (so the removed Publish box isn't needed).
//
// The toggle autosave goes through the shared window.FFC.request (ffc-core);
// these tests inject a mock FFC so no real transport runs.

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// Install a mock window.FFC.request. Pass { reject: true } to simulate an
// AJAX failure; otherwise it resolves with the given `resolve` data.
function installFFC(result) {
	const request = vi.fn(() =>
		result && result.reject
			? Promise.reject(new Error('fail'))
			: Promise.resolve(result ? result.resolve : undefined)
	);
	window.FFC = { request };
	return request;
}

function flush() {
	return Promise.resolve()
		.then(() => Promise.resolve())
		.then(() => Promise.resolve());
}

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
	delete window.FFC;
	vi.restoreAllMocks();
});

describe('ffc-cert-template-admin', () => {
	it('calls FFC.request with the toggle state on change', async () => {
		const request = installFFC({ resolve: {} });

		mount(config(), { checked: true });
		window.$('#ffc_template_visible').prop('checked', false).trigger('change');
		await flush();

		expect(request).toHaveBeenCalledTimes(1);
		const [action, data, options] = request.mock.calls[0];
		expect(action).toBe('ffc_cert_template_toggle_visibility');
		expect(data).toEqual({ post_id: 9, visible: '0' });
		expect(options).toEqual({ nonce: 'tpl-nonce', ajaxUrl: '/wp-admin/admin-ajax.php' });
	});

	it('sends "1" when the toggle is checked on', async () => {
		const request = installFFC({ resolve: {} });

		mount(config(), { checked: false });
		window.$('#ffc_template_visible').prop('checked', true).trigger('change');
		await flush();

		expect(request.mock.calls[0][1].visible).toBe('1');
	});

	it('shows the "Saved" status on success', async () => {
		installFFC({ resolve: {} });

		mount(config(), { checked: false });
		window.$('#ffc_template_visible').prop('checked', true).trigger('change');
		await flush();

		const $status = window.$('#ffc_template_visible_status');
		expect($status.text()).toBe('Saved');
		expect($status.hasClass('ffc-saved')).toBe(true);
	});

	it('shows the "Save failed" status on AJAX failure', async () => {
		installFFC({ reject: true });

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
		const request = installFFC({ resolve: {} });
		mount(config({ postId: 0 }), { checked: false });
		window.$('#ffc_template_visible').prop('checked', true).trigger('change');
		await flush();
		expect(request).not.toHaveBeenCalled();
	});
});
