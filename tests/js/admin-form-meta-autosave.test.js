// Tests for the per-form-meta autosave handler added to ffc-admin.js in
// the post-#238 polish PR. Any input carrying
// `data-ffc-autosave-form-key` POSTs to `ffc_update_form_meta` on change
// and surfaces an inline status chip beside the toggle.

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// FFC.request — the migration target — wraps jQuery.post() in a Promise.
// Mock $.post and return a chain whose .done / .fail callback the
// FFC.request internals invoke.
function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(spec.fail === true ? undefined : spec.fail); return chain; };
	return chain;
}

// Microtask flush so .then/.catch reactions run before assertions.
function flush() { return Promise.resolve().then(() => Promise.resolve()); }

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcFormMetaAutosave = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		action:  'ffc_update_form_meta',
		nonce:   'fm-nonce',
		postId:  42,
		strings: {
			saving: 'Saving…',
			saved:  'Saved',
			error:  'Save failed',
		},
	};
});

afterEach(() => {
	delete window.ffcFormMetaAutosave;
	vi.restoreAllMocks();
});

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
	if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
	loadScript('assets/js/ffc-admin.js');
}

describe('ffc-admin form-meta autosave', () => {
	it('POSTs the toggle state to the configured endpoint on change', async () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { key: 'quiz_enabled' } } }));

		mountToggle('quiz_enabled', false);
		const $cb = window.$('#t-quiz_enabled');
		$cb.prop('checked', true).trigger('change');
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

	it('sends "0" when toggle is unchecked', async () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		mountToggle('send_user_email', true);
		const $cb = window.$('#t-send_user_email');
		$cb.prop('checked', false).trigger('change');
		await flush();

		expect(postSpy.mock.calls[0][1].value).toBe('0');
	});

	it('shows the "Saved" chip on success then hides after the timeout', async () => {
		vi.useFakeTimers();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: {} } }));

		mountToggle('quiz_enabled', false);
		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');
		await flush();

		const $chip = window.$('.ffc-form-meta-autosave-status');
		expect($chip.length).toBe(1);
		expect($chip.text()).toBe('Saved');
		expect($chip.hasClass('is-saved')).toBe(true);
		// After 1500 ms the chip is hidden.
		vi.advanceTimersByTime(1500);
		await flush();
		expect($chip.attr('hidden')).toBe('hidden');
	});

	it('shows the "Save failed" chip on AJAX failure', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));

		mountToggle('quiz_enabled', false);
		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');
		await flush();

		const $chip = window.$('.ffc-form-meta-autosave-status');
		expect($chip.text()).toBe('Save failed');
		expect($chip.hasClass('is-error')).toBe(true);
	});

	it('uses server-supplied error message when available', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Nope' } } }));

		mountToggle('quiz_enabled', false);
		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');
		await flush();

		const $chip = window.$('.ffc-form-meta-autosave-status');
		expect($chip.text()).toBe('Nope');
		expect($chip.hasClass('is-error')).toBe(true);
	});
});
