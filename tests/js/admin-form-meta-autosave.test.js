// Tests for the per-form-meta half of the shared autosave widget.
//
// Any input carrying `data-ffc-autosave-form-key` POSTs to
// `ffc_update_form_meta`, scoped to the post id localized into
// `window.ffcFormMetaAutosave`, and surfaces the same badge the settings
// tabs use. Until #1116 this was a second implementation living in
// `ffc-admin.js`; these tests now exercise `FFC.Admin.autoSaveField`
// through `bootAutoSaveFields`, so a fix on either endpoint is proved to
// reach both.

import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// FFC.request wraps jQuery.post() in a Promise. Mock $.post and return a
// chain whose .done / .fail callback the FFC.request internals invoke.
function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(spec.fail === true ? undefined : spec.fail); return chain; };
	return chain;
}

// Microtask flush so .then/.catch reactions run before assertions.
function flush() { return Promise.resolve().then(() => Promise.resolve()); }

// The widget debounces; every save in these tests is released by this.
function releaseSave() { vi.advanceTimersByTime(400); }

beforeAll(() => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'admin-nonce',
		strings: { error: 'Generic', connectionError: 'Net error' },
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-admin.js');
	loadScript('assets/js/ffc-admin-autosave.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	vi.useFakeTimers();
	window.ffcFormMetaAutosave = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		action:  'ffc_update_form_meta',
		nonce:   'fm-nonce',
		postId:  42,
		strings: {
			saving:  'Salvando…',
			saved:   'Salvo',
			error:   'Falha ao salvar',
			invalid: 'Informe um valor válido',
		},
	};
});

afterEach(() => {
	delete window.ffcFormMetaAutosave;
	vi.restoreAllMocks();
	vi.useRealTimers();
});

function boot() {
	window.FFC.Admin.bootAutoSaveFields();
}

function mountToggle(key, checked) {
	document.body.innerHTML = `
		<label class="ffc-toggle">
			<input type="checkbox"
				id="t-${key}"
				name="ffc_config[${key}]"
				value="1"
				data-ffc-autosave-form-key="${key}"
				${checked ? 'checked' : ''}>
		</label>
	`;
	boot();
}

describe('form-meta autosave — the wire', () => {
	it('POSTs the toggle state to the configured endpoint', async () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: {} } }));

		mountToggle('quiz_enabled', false);
		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');
		releaseSave();
		await flush();

		expect(postSpy).toHaveBeenCalledTimes(1);
		const [url, payload] = postSpy.mock.calls[0];
		expect(url).toBe('/wp-admin/admin-ajax.php');
		expect(payload.action).toBe('ffc_update_form_meta');
		expect(payload.nonce).toBe('fm-nonce');
		expect(payload.post_id).toBe(42);
		expect(payload.key).toBe('quiz_enabled');
		expect(payload.value).toBe('1');
	});

	it('sends "0" when the toggle is unchecked', async () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		mountToggle('send_user_email', true);
		window.$('#t-send_user_email').prop('checked', false).trigger('change');
		releaseSave();
		await flush();

		expect(postSpy.mock.calls[0][1].value).toBe('0');
	});

	it('never sends the settings nonce — each endpoint verifies its own', async () => {
		window.ffcAdminAutosave = { nonce: 'settings-nonce' };
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		mountToggle('quiz_enabled', false);
		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');
		releaseSave();
		await flush();

		expect(postSpy.mock.calls[0][1].nonce).toBe('fm-nonce');
		delete window.ffcAdminAutosave;
	});

	it('binds nothing when the screen localized no post id', async () => {
		delete window.ffcFormMetaAutosave.postId;
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		mountToggle('quiz_enabled', false);
		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');
		releaseSave();
		await flush();

		expect(postSpy).not.toHaveBeenCalled();
	});

	it('returns early without POSTing when the autosave key is empty', async () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));
		document.body.innerHTML = '<input type="checkbox" id="t-empty" data-ffc-autosave-form-key="">';
		boot();

		window.$('#t-empty').prop('checked', true).trigger('change');
		releaseSave();
		await flush();

		expect(postSpy).not.toHaveBeenCalled();
	});
});

describe('form-meta autosave — the badge', () => {
	it('shows the localized "saved" badge then hides it', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: {} } }));

		mountToggle('quiz_enabled', false);
		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');
		releaseSave();
		await flush();

		const $badge = window.$('.ffc-autosave-badge');
		expect($badge.length).toBe(1);
		expect($badge.text()).toBe('Salvo');
		expect($badge.hasClass('ffc-autosave-badge--saved')).toBe(true);

		vi.advanceTimersByTime(1800);
		await flush();
		expect($badge.attr('hidden')).toBe('hidden');
	});

	it('uses the same badge class as the settings tabs, so dark mode applies', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: {} } }));

		mountToggle('quiz_enabled', false);
		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');
		releaseSave();
		await flush();

		// The retired `.ffc-form-meta-autosave-status` chip hardcoded hex
		// colours; `.ffc-autosave-badge` reads the --ffc-* tokens (#1116).
		expect(document.querySelector('.ffc-form-meta-autosave-status')).toBeNull();
		expect(document.querySelector('.ffc-autosave-badge')).not.toBeNull();
	});

	it('surfaces a server-supplied error message', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Nope' } } }));

		mountToggle('quiz_enabled', false);
		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');
		releaseSave();
		await flush();

		const $badge = window.$('.ffc-autosave-badge');
		expect($badge.text()).toBe('Nope');
		expect($badge.hasClass('ffc-autosave-badge--error')).toBe(true);
	});

	it('distinguishes a dropped connection from a server refusal', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));

		mountToggle('quiz_enabled', false);
		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');
		releaseSave();
		await flush();

		// The retired handler replaced this with its generic "Save failed",
		// so an operator on a dead network read it as a rejected value.
		expect(window.$('.ffc-autosave-badge').text()).toBe('Net error');
	});

	it('anchors the badge to the field when there is no .ffc-toggle wrapper', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: {} } }));
		document.body.innerHTML = `
			<div id="bare">
				<input type="text" id="t-bare" value="hi" data-ffc-autosave-form-key="bare_key">
			</div>
		`;
		boot();

		window.$('#t-bare').val('changed').trigger('change');
		releaseSave();
		await flush();

		const badge = document.getElementById('t-bare').nextElementSibling;
		expect(badge).not.toBeNull();
		expect(badge.classList.contains('ffc-autosave-badge')).toBe(true);
	});
});

describe('form-meta autosave — browser validity (#1114)', () => {
	function mountNumber(key, attrs) {
		document.body.innerHTML = `<input type="number" id="n-${key}" data-ffc-autosave-form-key="${key}" ${attrs}>`;
		boot();
	}

	it('refuses to save a value the browser considers invalid', async () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true } }));
		mountNumber('device_limit_max', 'min="1" value="1" required');

		window.$('#n-device_limit_max').val('').trigger('change');
		releaseSave();
		await flush();

		expect(postSpy).not.toHaveBeenCalled();
		expect(document.querySelector('.ffc-autosave-badge').className).toContain('--error');
	});

	it('shows the localized fallback when the browser supplies no message', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true } }));
		mountNumber('device_limit_max', 'min="1" value="1" required');
		const el = document.getElementById('n-device_limit_max');
		Object.defineProperty(el, 'validationMessage', { value: '', configurable: true });

		window.$('#n-device_limit_max').val('').trigger('change');
		releaseSave();
		await flush();

		expect(window.$('.ffc-autosave-badge').text()).toBe('Informe um valor válido');
	});

	it('saves once the value becomes valid again', async () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true } }));
		mountNumber('device_limit_max', 'min="1" value="1" required');

		window.$('#n-device_limit_max').val('').trigger('change');
		releaseSave();
		expect(postSpy).not.toHaveBeenCalled();

		window.$('#n-device_limit_max').val('3').trigger('change');
		releaseSave();
		await flush();

		expect(postSpy).toHaveBeenCalledTimes(1);
		expect(postSpy.mock.calls[0][1].value).toBe('3');
	});

	it('leaves toggles untouched — a checkbox is always valid', async () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true } }));
		mountToggle('quiz_enabled', false);

		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');
		releaseSave();
		await flush();

		expect(postSpy).toHaveBeenCalledTimes(1);
		expect(postSpy.mock.calls[0][1].value).toBe('1');
	});
});
