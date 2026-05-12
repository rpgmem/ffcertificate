// Tests for the early-return and load-side behaviour of:
//
//   - assets/js/ffc-device-signals.js   — bails when crypto.subtle or
//     ThumbmarkJS is unavailable, disables thumbmark telemetry on load.
//   - assets/js/ffc-frontend.js         — exposes window.ffcUtils after
//     load + wires the alert helper.
//
// Both files have heavy real-runtime dependencies (SubtleCrypto, the
// vendored thumbmarkjs library, jQuery AJAX, form submit pipelines)
// that make deep unit testing expensive. Sprint G of #170 covers the
// cheap parts here; the AJAX-heavy and crypto-heavy paths warrant
// integration testing in a real browser environment, tracked as a
// future sprint.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// Save originals once.
const ORIG_CRYPTO     = window.crypto;
const ORIG_THUMBMARK  = window.ThumbmarkJS;
const ORIG_FFC_CONFIG = window.ffc_device_config;

beforeEach(() => {
	// window.crypto is a non-writable property in jsdom — use
	// defineProperty to swap it.
	Object.defineProperty(window, 'crypto', { value: ORIG_CRYPTO, configurable: true });
	window.ThumbmarkJS = ORIG_THUMBMARK;
	window.ffc_device_config = ORIG_FFC_CONFIG;
	document.body.innerHTML = '';
});

// ----------------------------------------------------------------------
// ffc-device-signals.js — early returns
// ----------------------------------------------------------------------

describe('ffc-device-signals — early returns', () => {
	it('bails silently when crypto.subtle is unavailable', () => {
		// jsdom's window.crypto exists but without subtle. We can also
		// nuke it entirely to exercise the first guard.
		const c = { /* no subtle */ };
		// Override with a configurable descriptor so we can restore it later.
		Object.defineProperty(window, 'crypto', { value: c, configurable: true });
		// Ensure ThumbmarkJS doesn't accidentally satisfy the second guard.
		delete window.ThumbmarkJS;

		// Loading should not throw, and should not register any
		// observable side effect.
		expect(() => loadScript('assets/js/ffc-device-signals.js')).not.toThrow();
	});

	it('bails silently when ThumbmarkJS is unavailable', () => {
		// crypto.subtle is faked to PASS the first guard so we hit the
		// second one.
		Object.defineProperty(window, 'crypto', {
			value: { subtle: {}, randomUUID: () => 'uuid', getRandomValues: () => {} },
			configurable: true,
		});
		delete window.ThumbmarkJS;

		expect(() => loadScript('assets/js/ffc-device-signals.js')).not.toThrow();
	});

	it("calls ThumbmarkJS.setOption('logging', false) on first load when both deps are present", () => {
		const setOption = vi.fn();
		const getFingerprintData = vi.fn(() => Promise.resolve({}));
		Object.defineProperty(window, 'crypto', {
			value: { subtle: {}, randomUUID: () => 'uuid', getRandomValues: () => {} },
			configurable: true,
		});
		window.ThumbmarkJS = {
			setOption,
			getFingerprintData,
			stableStringify: JSON.stringify,
		};

		loadScript('assets/js/ffc-device-signals.js');
		expect(setOption).toHaveBeenCalledWith('logging', false);
	});
});

// ----------------------------------------------------------------------
// ffc-frontend.js — public surface after load
// ----------------------------------------------------------------------

describe('ffc-frontend — load-side', () => {
	beforeEach(() => {
		// frontend.js touches window.FFC.Frontend.Masks at module scope;
		// load the helpers module first so that namespace exists.
		window.ffc_ajax = {
			ajax_url: '/wp-admin/admin-ajax.php',
			nonce: 'test-nonce',
			strings: {},
		};
		loadScript('assets/js/ffc-core.js');
		loadScript('assets/js/ffc-frontend-helpers.js');
	});

	it('republishes window.ffcUtils as the Frontend.Masks namespace', () => {
		loadScript('assets/js/ffc-frontend.js');
		// frontend.js does `window.ffcUtils = window.FFC.Frontend.Masks;`
		expect(window.ffcUtils).toBe(window.FFC.Frontend.Masks);
		// And the mask helpers still resolve.
		expect(typeof window.ffcUtils.applyCpfRf).toBe('function');
	});

	it('does not throw when loaded against a page that has no forms', () => {
		document.body.innerHTML = '<div>no forms here</div>';
		expect(() => loadScript('assets/js/ffc-frontend.js')).not.toThrow();
	});

	it('does not throw when loaded against a page with one .ffc-submission-form', () => {
		document.body.innerHTML = `
			<form class="ffc-submission-form" data-form-id="1">
				<input name="cpf_rf" />
				<input type="hidden" name="form_id" value="1" />
			</form>
		`;
		expect(() => loadScript('assets/js/ffc-frontend.js')).not.toThrow();
	});
});
