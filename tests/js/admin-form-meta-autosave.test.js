// Tests for the per-form-meta autosave handler added to ffc-admin.js in
// the post-#238 polish PR. Any input carrying
// `data-ffc-autosave-form-key` POSTs to `ffc_update_form_meta` on change
// and surfaces an inline status chip beside the toggle.

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

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
	loadScript('assets/js/ffc-admin.js');
}

describe('ffc-admin form-meta autosave', () => {
	it('POSTs the toggle state to the configured endpoint on change', () => {
		const ajaxSpy = vi.fn(function (opts) {
			// Return a thenable-ish that supports .done().fail() chain.
			return {
				done: function (cb) { cb({ success: true, data: { key: opts.data.key } }); return this; },
				fail: function () { return this; },
			};
		});
		window.$.ajax = ajaxSpy;

		mountToggle('quiz_enabled', false);
		const $cb = window.$('#t-quiz_enabled');
		$cb.prop('checked', true).trigger('change');

		expect(ajaxSpy).toHaveBeenCalledTimes(1);
		const call = ajaxSpy.mock.calls[0][0];
		expect(call.url).toBe('/wp-admin/admin-ajax.php');
		expect(call.method).toBe('POST');
		expect(call.data.action).toBe('ffc_update_form_meta');
		expect(call.data.nonce).toBe('fm-nonce');
		expect(call.data.post_id).toBe(42);
		expect(call.data.key).toBe('quiz_enabled');
		expect(call.data.value).toBe('1');
	});

	it('sends "0" when toggle is unchecked', () => {
		const ajaxSpy = vi.fn(function () {
			return { done: function () { return this; }, fail: function () { return this; } };
		});
		window.$.ajax = ajaxSpy;

		mountToggle('send_user_email', true);
		const $cb = window.$('#t-send_user_email');
		$cb.prop('checked', false).trigger('change');

		expect(ajaxSpy.mock.calls[0][0].data.value).toBe('0');
	});

	it('shows the "Saved" chip on success then hides after the timeout', () => {
		vi.useFakeTimers();
		const ajaxSpy = vi.fn(function () {
			return {
				done: function (cb) { cb({ success: true, data: {} }); return this; },
				fail: function () { return this; },
			};
		});
		window.$.ajax = ajaxSpy;

		mountToggle('quiz_enabled', false);
		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');

		const $chip = window.$('.ffc-form-meta-autosave-status');
		expect($chip.length).toBe(1);
		expect($chip.text()).toBe('Saved');
		expect($chip.hasClass('is-saved')).toBe(true);

		vi.advanceTimersByTime(1600);
		expect($chip.attr('hidden')).toBe('hidden');
		vi.useRealTimers();
	});

	it('shows the "Save failed" chip on AJAX failure', () => {
		const ajaxSpy = vi.fn(function () {
			return {
				done: function () { return this; },
				fail: function (cb) { cb(); return this; },
			};
		});
		window.$.ajax = ajaxSpy;

		mountToggle('quiz_enabled', false);
		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');

		const $chip = window.$('.ffc-form-meta-autosave-status');
		expect($chip.hasClass('is-error')).toBe(true);
		expect($chip.text()).toBe('Save failed');
	});

	it('uses server-supplied error message when available', () => {
		const ajaxSpy = vi.fn(function () {
			return {
				done: function (cb) {
					cb({ success: false, data: { message: 'Forbidden' } });
					return this;
				},
				fail: function () { return this; },
			};
		});
		window.$.ajax = ajaxSpy;

		mountToggle('quiz_enabled', false);
		window.$('#t-quiz_enabled').prop('checked', true).trigger('change');

		const $chip = window.$('.ffc-form-meta-autosave-status');
		expect($chip.hasClass('is-error')).toBe(true);
		expect($chip.text()).toBe('Forbidden');
	});

	// Note: the missing-config branch (`window.ffcFormMetaAutosave` not
	// localized — e.g. brand-new post screen) is covered by the
	// `if (FORM_META_CFG && ...)` guard in ffc-admin.js itself. Testing
	// it in isolation here is hard because the IIFE attaches the
	// delegated change listener at document level on first load and we
	// can't easily un-attach it across test runs. We rely on the
	// structural guard + production smoke testing for that branch.
});
