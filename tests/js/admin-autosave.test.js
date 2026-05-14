// Tests for FFC.Admin.autoSaveField — debounced inline-save widget
// introduced in 6.5.4.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'admin-nonce',
		strings: {
			error: 'Generic',
			connectionError: 'Net error',
		},
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-admin.js');
	loadScript('assets/js/ffc-admin-autosave.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	vi.useFakeTimers();
});

afterEach(() => {
	vi.restoreAllMocks();
	vi.useRealTimers();
});

function makeChain(impl) {
	// Build a $.post-shaped chain whose .done(cb) calls cb(impl()).
	var chain = { done: () => chain, fail: () => chain };
	chain.done = function (cb) { cb(impl()); return chain; };
	chain.fail = function () { return chain; };
	return chain;
}

describe('FFC.Admin.autoSaveField', () => {
	it('warns and returns a no-op when config.key is missing', () => {
		const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
		document.body.innerHTML = '<input type="checkbox" id="t" />';
		const ret = window.FFC.Admin.autoSaveField(window.$('#t'), {});
		expect(warnSpy).toHaveBeenCalled();
		expect(typeof ret.destroy).toBe('function');
	});

	it('debounces successive changes into a single save', () => {
		document.body.innerHTML = '<input type="checkbox" id="t" />';
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({
			success: true, data: {},
		})));
		window.FFC.Admin.autoSaveField(window.$('#t'), { key: 'admin_bypass_geo', debounce: 400 });

		// Fire 3 rapid changes — only one save should land.
		window.$('#t').prop('checked', true).trigger('change');
		vi.advanceTimersByTime(200);
		window.$('#t').prop('checked', false).trigger('change');
		vi.advanceTimersByTime(200);
		window.$('#t').prop('checked', true).trigger('change');

		// Before the debounce elapses → no post yet.
		vi.advanceTimersByTime(399);
		expect(postSpy).not.toHaveBeenCalled();

		// At 400 ms from the LAST change → one post.
		vi.advanceTimersByTime(1);
		expect(postSpy).toHaveBeenCalledTimes(1);

		const payload = postSpy.mock.calls[0][1];
		expect(payload).toMatchObject({
			action: 'ffc_update_setting',
			key: 'admin_bypass_geo',
			value: '1',
		});
	});

	it('renders the saving → saved badge transition on success', async () => {
		document.body.innerHTML = '<input type="checkbox" id="t" />';
		vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({
			success: true, data: { key: 'admin_bypass_geo', value: true },
		})));
		window.FFC.Admin.autoSaveField(window.$('#t'), { key: 'admin_bypass_geo' });

		window.$('#t').prop('checked', true).trigger('change');
		vi.advanceTimersByTime(400);
		// FFC.request resolves via Promise microtask — flush it.
		await Promise.resolve();
		await Promise.resolve();

		const $badge = window.$('.ffc-autosave-badge');
		expect($badge.text()).toBe('Saved');
		expect($badge.hasClass('ffc-autosave-badge--saved')).toBe(true);

		vi.advanceTimersByTime(1800);
		expect($badge.attr('hidden')).toBe('hidden');
	});

	it('renders the error badge with the server message on protocol failure', async () => {
		document.body.innerHTML = '<input type="checkbox" id="t" />';
		vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({
			success: false, data: { message: 'Quota exceeded' },
		})));
		window.FFC.Admin.autoSaveField(window.$('#t'), { key: 'admin_bypass_geo' });

		window.$('#t').prop('checked', true).trigger('change');
		vi.advanceTimersByTime(400);
		await Promise.resolve();
		await Promise.resolve();

		const $badge = window.$('.ffc-autosave-badge');
		expect($badge.text()).toBe('Quota exceeded');
		expect($badge.hasClass('ffc-autosave-badge--error')).toBe(true);
	});

	it('honours a custom transform when extracting the value', () => {
		document.body.innerHTML = '<input type="text" id="name" value="alice" />';
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({
			success: true, data: {},
		})));
		window.FFC.Admin.autoSaveField(window.$('#name'), {
			key: 'some_text_key',
			transform: function ($el) { return $el.val().toUpperCase(); },
		});

		window.$('#name').trigger('change');
		vi.advanceTimersByTime(400);

		expect(postSpy.mock.calls[0][1].value).toBe('ALICE');
	});

	it('destroy() unbinds the change handler', () => {
		document.body.innerHTML = '<input type="checkbox" id="t" />';
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({
			success: true, data: {},
		})));
		const ctrl = window.FFC.Admin.autoSaveField(window.$('#t'), { key: 'admin_bypass_geo' });
		ctrl.destroy();

		window.$('#t').prop('checked', true).trigger('change');
		vi.advanceTimersByTime(400);

		expect(postSpy).not.toHaveBeenCalled();
	});
});

// ----------------------------------------------------------------------
// Generic page-init scan — every admin page that enqueues this script
// auto-wires every input tagged with `data-ffc-autosave-key`. This
// covers the wiring previously inlined in ffc-geolocation-settings.js.
// ----------------------------------------------------------------------

describe('FFC admin-autosave — bootAutoSaveFields page-init', () => {
	it('wires autoSaveField on every input tagged with data-ffc-autosave-key', () => {
		document.body.innerHTML = `
			<input type="checkbox" name="cache_enabled" data-ffc-autosave-key="cache_enabled">
			<input type="checkbox" name="disable_all_emails" data-ffc-autosave-key="disable_all_emails">
			<input type="checkbox" name="not_tagged">
		`;
		const calls = [];
		const spy = vi.spyOn(window.FFC.Admin, 'autoSaveField').mockImplementation(function ($el, config) {
			calls.push({ name: $el.attr('name'), key: config.key });
			return { destroy: () => {} };
		});

		window.FFC.Admin.bootAutoSaveFields();

		expect(spy).toHaveBeenCalledTimes(2);
		expect(calls).toEqual([
			{ name: 'cache_enabled', key: 'cache_enabled' },
			{ name: 'disable_all_emails', key: 'disable_all_emails' },
		]);
		spy.mockRestore();
	});

	it('skips inputs that have already been wired (idempotent re-scan)', () => {
		document.body.innerHTML = `
			<input type="checkbox" data-ffc-autosave-key="cache_enabled">
		`;
		const spy = vi.spyOn(window.FFC.Admin, 'autoSaveField').mockImplementation(() => ({ destroy: () => {} }));

		window.FFC.Admin.bootAutoSaveFields();
		window.FFC.Admin.bootAutoSaveFields();

		expect(spy).toHaveBeenCalledTimes(1);
		spy.mockRestore();
	});

	it('does nothing when no tagged inputs exist on the page', () => {
		document.body.innerHTML = '<input type="checkbox" name="untagged">';
		const spy = vi.spyOn(window.FFC.Admin, 'autoSaveField');

		window.FFC.Admin.bootAutoSaveFields();

		expect(spy).not.toHaveBeenCalled();
		spy.mockRestore();
	});

	it('honours a per-field data-ffc-autosave-debounce override', () => {
		document.body.innerHTML = `
			<textarea data-ffc-autosave-key="ip_message" data-ffc-autosave-debounce="800"></textarea>
			<input type="number" data-ffc-autosave-key="ip_max_per_hour">
		`;
		const calls = [];
		const spy = vi.spyOn(window.FFC.Admin, 'autoSaveField').mockImplementation(function ($el, config) {
			calls.push({ key: config.key, debounce: config.debounce });
			return { destroy: () => {} };
		});

		window.FFC.Admin.bootAutoSaveFields();

		expect(calls).toEqual([
			{ key: 'ip_message', debounce: 800 },   // explicit override
			{ key: 'ip_max_per_hour', debounce: undefined }, // default (autoSaveField uses 400)
		]);
		spy.mockRestore();
	});

	it('ignores a non-numeric data-ffc-autosave-debounce attr', () => {
		document.body.innerHTML = `
			<input data-ffc-autosave-key="x" data-ffc-autosave-debounce="not-a-number">
		`;
		const spy = vi.spyOn(window.FFC.Admin, 'autoSaveField').mockImplementation(() => ({ destroy: () => {} }));

		window.FFC.Admin.bootAutoSaveFields();

		expect(spy).toHaveBeenCalledTimes(1);
		expect(spy.mock.calls[0][1].debounce).toBeUndefined();
		spy.mockRestore();
	});
});
