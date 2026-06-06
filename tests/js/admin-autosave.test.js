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

	it('sends the checked value of a radio group, not a boolean', () => {
		document.body.innerHTML =
			'<input type="radio" name="lvl" id="r-debug" value="debug">' +
			'<input type="radio" name="lvl" id="r-info" value="info">' +
			'<input type="radio" name="lvl" id="r-warn" value="warning">';
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({
			success: true, data: {},
		})));
		window.FFC.Admin.autoSaveField(window.$('#r-info'), { key: 'activity_log_min_level' });

		window.$('#r-info').prop('checked', true).trigger('change');
		vi.advanceTimersByTime(400);

		expect(postSpy).toHaveBeenCalled();
		// The value is the selected level — not '1'/'0' like a checkbox.
		expect(postSpy.mock.calls[0][1].value).toBe('info');
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

	it('anchors the badge after the .ffc-toggle wrapper, not between input and track', async () => {
		// Regression test for the user-reported bug where toggle change
		// "didn't save" — actually saved fine, but the badge injected
		// between <input> and <span.ffc-toggle-track> broke the CSS
		// `input:checked + .ffc-toggle-track` sibling rule, so the
		// track never recoloured and the toggle visually stayed off.
		document.body.innerHTML = `
			<label class="ffc-toggle" for="t">
				<input type="checkbox" id="t" />
				<span class="ffc-toggle-track"></span>
				<span class="ffc-toggle-label">Test</span>
			</label>
		`;
		vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({
			success: true, data: {},
		})));
		window.FFC.Admin.autoSaveField(window.$('#t'), { key: 'enable_activity_log' });

		window.$('#t').prop('checked', true).trigger('change');
		vi.advanceTimersByTime(400);
		await Promise.resolve();
		await Promise.resolve();

		// Track must still be the immediate next sibling of the input.
		const input = document.getElementById('t');
		expect(input.nextElementSibling).not.toBeNull();
		expect(input.nextElementSibling.className).toBe('ffc-toggle-track');

		// Badge must exist and live outside the label.
		const badge = document.querySelector('.ffc-autosave-badge');
		expect(badge).not.toBeNull();
		expect(badge.parentElement).toBe(document.body);
		// Anchored right after the wrapping .ffc-toggle label.
		const label = document.querySelector('.ffc-toggle');
		expect(label.nextElementSibling).toBe(badge);
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

// ----------------------------------------------------------------------
// Extra branches: explicit/pre-existing badge, radio-without-name,
// multi-checkbox collection, nonce injection, destroy with live timers.
// ----------------------------------------------------------------------

describe('FFC.Admin.autoSaveField — extra branches', () => {
	it('reuses an explicitly-passed badge node', () => {
		document.body.innerHTML = `
			<input type="checkbox" id="t" />
			<span id="my-badge"></span>
		`;
		vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({ success: true, data: {} })));
		const $badge = window.$('#my-badge');
		window.FFC.Admin.autoSaveField(window.$('#t'), { key: 'k', $badge });
		window.$('#t').prop('checked', true).trigger('change');
		vi.advanceTimersByTime(400);
		// No new ffc-autosave-badge created — the explicit one is used.
		expect(document.querySelectorAll('.ffc-autosave-badge').length).toBe(0);
		expect(window.$('#my-badge').text()).toContain('Saving');
	});

	it('reuses an already-injected badge on a second autoSaveField call', () => {
		document.body.innerHTML = '<input type="checkbox" id="t" />';
		vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({ success: true, data: {} })));
		window.FFC.Admin.autoSaveField(window.$('#t'), { key: 'k' });
		// First call injected a badge after the input. A second call should
		// reuse it rather than inject a duplicate.
		window.FFC.Admin.autoSaveField(window.$('#t'), { key: 'k' });
		expect(document.querySelectorAll('.ffc-autosave-badge').length).toBe(1);
	});

	it('falls back to $field.val() for a radio with no name attribute', () => {
		document.body.innerHTML = '<input type="radio" id="r" value="solo" checked>';
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({ success: true, data: {} })));
		window.FFC.Admin.autoSaveField(window.$('#r'), { key: 'k' });
		window.$('#r').trigger('change');
		vi.advanceTimersByTime(400);
		expect(postSpy.mock.calls[0][1].value).toBe('solo');
	});

	it('injects the localized nonce from window.ffcAdminAutosave', () => {
		document.body.innerHTML = '<input type="checkbox" id="t" />';
		window.ffcAdminAutosave = { nonce: 'autosave-nonce-123' };
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({ success: true, data: {} })));
		window.FFC.Admin.autoSaveField(window.$('#t'), { key: 'k' });
		window.$('#t').prop('checked', true).trigger('change');
		vi.advanceTimersByTime(400);
		expect(postSpy.mock.calls[0][1].nonce).toBe('autosave-nonce-123');
		delete window.ffcAdminAutosave;
	});

	it('clears the linger timer when a new change reschedules during the "Saved" window', async () => {
		document.body.innerHTML = '<input type="checkbox" id="t" />';
		vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({ success: true, data: {} })));
		window.FFC.Admin.autoSaveField(window.$('#t'), { key: 'k' });

		window.$('#t').prop('checked', true).trigger('change');
		vi.advanceTimersByTime(400);
		await Promise.resolve();
		await Promise.resolve();
		const $badge = window.$('.ffc-autosave-badge');
		expect($badge.hasClass('ffc-autosave-badge--saved')).toBe(true);

		// Trigger another change while the "Saved" linger timer is live.
		window.$('#t').prop('checked', false).trigger('change');
		// scheduleSave should have cleared the linger timer, so advancing
		// past the original linger does NOT hide the badge prematurely.
		vi.advanceTimersByTime(400);
		await Promise.resolve();
		await Promise.resolve();
		expect($badge.attr('hidden')).toBeUndefined();
	});

	it('destroy() clears a pending timer without throwing', () => {
		document.body.innerHTML = '<input type="checkbox" id="t" />';
		vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({ success: true, data: {} })));
		const ctrl = window.FFC.Admin.autoSaveField(window.$('#t'), { key: 'k' });
		// Schedule a save (live pendingTimer) but do NOT let it fire.
		window.$('#t').prop('checked', true).trigger('change');
		expect(() => ctrl.destroy()).not.toThrow();
		expect(document.querySelectorAll('.ffc-autosave-badge').length).toBe(0);
	});

	it('destroy() clears the linger timer when one is live', async () => {
		document.body.innerHTML = '<input type="checkbox" id="t" />';
		vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({ success: true, data: {} })));
		const ctrl = window.FFC.Admin.autoSaveField(window.$('#t'), { key: 'k' });
		window.$('#t').prop('checked', true).trigger('change');
		// Let the save resolve so a lingerTimer is created (and pendingTimer
		// is cleared to null). Then destroy hits the lingerTimer branch.
		vi.advanceTimersByTime(400);
		await Promise.resolve();
		await Promise.resolve();
		expect(() => ctrl.destroy()).not.toThrow();
		expect(document.querySelectorAll('.ffc-autosave-badge').length).toBe(0);
	});

	it('extracts a plain text field value with no transform/checkbox/radio', () => {
		document.body.innerHTML = '<input type="text" id="plain" value="hello">';
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({ success: true, data: {} })));
		window.FFC.Admin.autoSaveField(window.$('#plain'), { key: 'k' });
		window.$('#plain').val('world').trigger('change');
		vi.advanceTimersByTime(400);
		expect(postSpy.mock.calls[0][1].value).toBe('world');
	});

	it('multi-checkbox group POSTs the collected checked values as an array', () => {
		document.body.innerHTML = `
			<input type="checkbox" data-ffc-autosave-key="signals" data-ffc-autosave-multi value="ua" checked>
			<input type="checkbox" data-ffc-autosave-key="signals" data-ffc-autosave-multi value="ip">
			<input type="checkbox" data-ffc-autosave-key="signals" data-ffc-autosave-multi value="tz" checked>
		`;
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({ success: true, data: {} })));
		window.FFC.Admin.bootAutoSaveFields();

		// Toggling any sibling fires the anchor's change handler.
		window.$('[value="ip"]').prop('checked', true).trigger('change');
		vi.advanceTimersByTime(400);

		expect(postSpy).toHaveBeenCalledTimes(1);
		expect(postSpy.mock.calls[0][1].value).toEqual(['ua', 'ip', 'tz']);
	});

	it('binds only the first occurrence of a multi key as the anchor', () => {
		document.body.innerHTML = `
			<input type="checkbox" data-ffc-autosave-key="g" data-ffc-autosave-multi value="a">
			<input type="checkbox" data-ffc-autosave-key="g" data-ffc-autosave-multi value="b">
		`;
		const spy = vi.spyOn(window.FFC.Admin, 'autoSaveField').mockImplementation(() => ({ destroy: () => {} }));
		window.FFC.Admin.bootAutoSaveFields();
		// Only one anchor wired despite two siblings sharing the key.
		expect(spy).toHaveBeenCalledTimes(1);
		expect(spy.mock.calls[0][1].$group.length).toBe(2);
		spy.mockRestore();
	});
});
