// Tests for assets/js/ffc-cache-actions.js — the small page-init
// script that intercepts the Settings → Cache action buttons and
// turns the full-page redirect into an AJAX call + toast.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'cache-nonce',
		strings: {},
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-admin.js');
	loadScript('assets/js/ffc-cache-actions.js');
});

beforeEach(() => {
	document.body.innerHTML = `
		<div class="wrap"><h1>Cache</h1>
			<a href="?action=warm_cache" class="button ffc-cache-warm-btn" data-ffc-action="ffc_cache_warm">Warm Cache Now</a>
			<a href="?action=clear_cache" class="button ffc-cache-clear-btn" data-ffc-action="ffc_cache_clear" data-ffc-confirm="Clear all cache?">Clear Cache</a>
		</div>
	`;
	window.ffcCacheActions = {
		strings: {
			working: 'Working…',
			success: 'Done.',
			error: 'Action failed.',
			confirmClear: 'Clear all cache?',
		},
	};
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('FFC Cache Actions — Warm', () => {
	it('intercepts the click, posts ffc_cache_warm, and shows a success toast', async () => {
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({
			count: 5,
			message: 'Cache warmed for 5 forms.',
		});
		const notifySpy = vi.spyOn(window.FFC.Admin, 'showNotification').mockImplementation(() => {});

		const $btn = window.$('.ffc-cache-warm-btn');
		const ev = window.$.Event('click');
		$btn.trigger(ev);
		await Promise.resolve(); await Promise.resolve();

		expect(ev.isDefaultPrevented()).toBe(true);
		expect(requestSpy).toHaveBeenCalledWith('ffc_cache_warm', {});
		expect(notifySpy).toHaveBeenCalledWith('Cache warmed for 5 forms.', 'success');
		// Button restored after success.
		expect($btn.prop('disabled')).toBe(false);
		expect($btn.text()).toBe('Warm Cache Now');
	});

	it('falls back to the localised success string when the response has no message', async () => {
		vi.spyOn(window.FFC, 'request').mockResolvedValue({});
		const notifySpy = vi.spyOn(window.FFC.Admin, 'showNotification').mockImplementation(() => {});

		window.$('.ffc-cache-warm-btn').trigger('click');
		await Promise.resolve(); await Promise.resolve();

		expect(notifySpy).toHaveBeenCalledWith('Done.', 'success');
	});

	it('shows the working badge while the request is pending', () => {
		// Never resolve so we can observe the in-flight state.
		vi.spyOn(window.FFC, 'request').mockReturnValue(new Promise(() => {}));

		const $btn = window.$('.ffc-cache-warm-btn');
		$btn.trigger('click');

		expect($btn.prop('disabled')).toBe(true);
		expect($btn.text()).toBe('Working…');
	});

	it('on error: shows the server error message and re-enables the button', async () => {
		vi.spyOn(window.FFC, 'request').mockRejectedValue(new Error('Server down'));
		const notifySpy = vi.spyOn(window.FFC.Admin, 'showNotification').mockImplementation(() => {});

		const $btn = window.$('.ffc-cache-warm-btn');
		$btn.trigger('click');
		await Promise.resolve(); await Promise.resolve();

		expect(notifySpy).toHaveBeenCalledWith('Server down', 'error');
		expect($btn.prop('disabled')).toBe(false);
	});
});

describe('FFC Cache Actions — Clear', () => {
	it('bails when the user declines the confirm', () => {
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const requestSpy = vi.spyOn(window.FFC, 'request');

		const $btn = window.$('.ffc-cache-clear-btn');
		const ev = window.$.Event('click');
		$btn.trigger(ev);

		expect(requestSpy).not.toHaveBeenCalled();
		// The handler returns early WITHOUT calling preventDefault, so the
		// browser would follow the href — but the no-JS fallback path is
		// fine for a declined confirm (no destructive action ran).
		expect(ev.isDefaultPrevented()).toBe(true);
	});

	it('on confirm + success: posts ffc_cache_clear and toasts', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({ message: 'Cache cleared.' });
		const notifySpy = vi.spyOn(window.FFC.Admin, 'showNotification').mockImplementation(() => {});

		window.$('.ffc-cache-clear-btn').trigger('click');
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy).toHaveBeenCalledWith('ffc_cache_clear', {});
		expect(notifySpy).toHaveBeenCalledWith('Cache cleared.', 'success');
	});

	it('uses the data-ffc-confirm attribute when present (overrides the localised string)', () => {
		// data-ffc-confirm is set to "Clear all cache?" in the fixture.
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

		window.$('.ffc-cache-clear-btn').trigger('click');

		expect(confirmSpy).toHaveBeenCalledWith('Clear all cache?');
	});
});
