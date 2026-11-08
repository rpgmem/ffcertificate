// Tests for assets/js/ffc-cache-actions.js — the small page-init
// script that intercepts the Settings → Cache "Purge page caches"
// button and turns the full-page redirect into an AJAX call + toast.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'cache-nonce',
		strings: {},
	};
	// The script reads its strings once, at load.
	window.ffcCacheActions = {
		strings: {
			working: 'Working…',
			success: 'Done.',
			error: 'Action failed.',
			confirmClear: 'Localised confirm?',
		},
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-admin.js');
	loadScript('assets/js/ffc-cache-actions.js');
});

beforeEach(() => {
	document.body.innerHTML = `
		<div class="wrap"><h1>Cache</h1>
			<a href="?action=clear_cache" class="ffc-btn ffc-cache-clear-btn" data-ffc-action="ffc_cache_clear" data-ffc-confirm="Purge the page caches?">Purge page caches</a>
		</div>
	`;
	delete window.ffcCacheActions.nonces;
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('FFC Cache Actions — Purge page caches', () => {
	it('bails when the user declines the plugin dialog', async () => {
		vi.spyOn(window.FFC, 'confirmDialog').mockReturnValue(Promise.resolve(false));
		const requestSpy = vi.spyOn(window.FFC, 'request');

		const ev = window.$.Event('click');
		window.$('.ffc-cache-clear-btn').trigger(ev);
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy).not.toHaveBeenCalled();
		expect(ev.isDefaultPrevented()).toBe(true);
	});

	it('on yes + success: posts ffc_cache_clear, toasts and restores the button', async () => {
		vi.spyOn(window.FFC, 'confirmDialog').mockReturnValue(Promise.resolve(true));
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({ message: 'Page caches purged.' });
		const notifySpy = vi.spyOn(window.FFC.Admin, 'notify').mockImplementation(() => {});

		const $btn = window.$('.ffc-cache-clear-btn');
		$btn.trigger('click');
		await Promise.resolve(); await Promise.resolve();
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy).toHaveBeenCalledWith('ffc_cache_clear', {});
		expect(notifySpy).toHaveBeenCalledWith('Page caches purged.', 'success');
		expect($btn.prop('disabled')).toBe(false);
		expect($btn.text()).toBe('Purge page caches');
	});

	it('falls back to the localised success string when the response has no message', async () => {
		vi.spyOn(window.FFC, 'confirmDialog').mockReturnValue(Promise.resolve(true));
		vi.spyOn(window.FFC, 'request').mockResolvedValue({});
		const notifySpy = vi.spyOn(window.FFC.Admin, 'notify').mockImplementation(() => {});

		window.$('.ffc-cache-clear-btn').trigger('click');
		await Promise.resolve(); await Promise.resolve();
		await Promise.resolve(); await Promise.resolve();

		expect(notifySpy).toHaveBeenCalledWith('Done.', 'success');
	});

	it('shows the working badge while the request is pending', async () => {
		vi.spyOn(window.FFC, 'confirmDialog').mockReturnValue(Promise.resolve(true));
		vi.spyOn(window.FFC, 'request').mockReturnValue(new Promise(() => {}));

		const $btn = window.$('.ffc-cache-clear-btn');
		$btn.trigger('click');
		await Promise.resolve(); await Promise.resolve();

		expect($btn.prop('disabled')).toBe(true);
		expect($btn.text()).toBe('Working…');
	});

	it('on error: shows the server error message and re-enables the button', async () => {
		vi.spyOn(window.FFC, 'confirmDialog').mockReturnValue(Promise.resolve(true));
		vi.spyOn(window.FFC, 'request').mockRejectedValue(new Error('Server down'));
		const notifySpy = vi.spyOn(window.FFC.Admin, 'notify').mockImplementation(() => {});

		const $btn = window.$('.ffc-cache-clear-btn');
		$btn.trigger('click');
		await Promise.resolve(); await Promise.resolve();
		await Promise.resolve(); await Promise.resolve();

		expect(notifySpy).toHaveBeenCalledWith('Server down', 'error');
		expect($btn.prop('disabled')).toBe(false);
	});

	it('uses the data-ffc-confirm attribute when present (overrides the localised string)', async () => {
		const confirmSpy = vi.spyOn(window.FFC, 'confirmDialog').mockReturnValue(Promise.resolve(false));

		window.$('.ffc-cache-clear-btn').trigger('click');
		await Promise.resolve(); await Promise.resolve();

		expect(confirmSpy.mock.calls[0][0]).toMatchObject({ message: 'Purge the page caches?', category: 'caution' });
	});

	it('falls back to the localised question when the button carries none', async () => {
		window.$('.ffc-cache-clear-btn').removeAttr('data-ffc-confirm');
		const confirmSpy = vi.spyOn(window.FFC, 'confirmDialog').mockReturnValue(Promise.resolve(false));

		window.$('.ffc-cache-clear-btn').trigger('click');
		await Promise.resolve(); await Promise.resolve();

		expect(confirmSpy.mock.calls[0][0]).toMatchObject({ message: 'Localised confirm?', category: 'caution' });
	});
});

describe('FFC Cache Actions — payload / guards', () => {
	it('attaches the per-action nonce to the request payload when localized', async () => {
		window.ffcCacheActions.nonces = { ffc_cache_clear: 'clear-nonce' };
		vi.spyOn(window.FFC, 'confirmDialog').mockReturnValue(Promise.resolve(true));
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({});
		vi.spyOn(window.FFC.Admin, 'notify').mockImplementation(() => {});

		window.$('.ffc-cache-clear-btn').trigger('click');
		await Promise.resolve(); await Promise.resolve();
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy).toHaveBeenCalledWith('ffc_cache_clear', { nonce: 'clear-nonce' });
		delete window.ffcCacheActions.nonces;
	});

	it('is a no-op for a matching button that carries no data-ffc-action', async () => {
		document.body.innerHTML =
			'<a href="#" class="ffc-btn ffc-cache-clear-btn">No action</a>';
		const confirmSpy = vi.spyOn(window.FFC, 'confirmDialog');
		const requestSpy = vi.spyOn(window.FFC, 'request');

		window.$('.ffc-cache-clear-btn').trigger('click');
		await Promise.resolve(); await Promise.resolve();

		expect(confirmSpy).not.toHaveBeenCalled();
		expect(requestSpy).not.toHaveBeenCalled();
	});

	it('no longer intercepts the retired "Warm cache now" button', async () => {
		document.body.innerHTML =
			'<a href="#" class="ffc-btn ffc-cache-warm-btn" data-ffc-action="ffc_cache_warm">Warm</a>';
		const requestSpy = vi.spyOn(window.FFC, 'request');

		const ev = window.$.Event('click');
		window.$('.ffc-cache-warm-btn').trigger(ev);

		expect(requestSpy).not.toHaveBeenCalled();
		expect(ev.isDefaultPrevented()).toBe(false);
	});
});

describe('FFC Cache Actions — load-time guard', () => {
	it('returns at load when FFC.Admin.notify is unavailable', () => {
		const savedAdmin = window.FFC.Admin;
		window.FFC.Admin = {};
		expect(() => loadScript('assets/js/ffc-cache-actions.js')).not.toThrow();
		window.FFC.Admin = savedAdmin;
	});
});
