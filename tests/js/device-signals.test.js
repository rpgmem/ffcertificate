// Deep coverage for `assets/js/ffc-device-signals.js` — exercises the
// full fingerprint pipeline that Sprint G of #170 deferred:
//   - generateUuid + getOrCreateDeviceId (localStorage roundtrip)
//   - sha256Hex via mocked window.crypto.subtle.digest
//   - mapComponents (14 signal slots)
//   - collectSignals (getFingerprintData → mapComponents → hash each)
//   - attachToForms (writes JSON into <input name="ffc_device_signals">)
//
// Strategy: replace SubtleCrypto + ThumbmarkJS with deterministic
// fakes, load the script, wait for the form-attach promise to settle,
// and assert against the hidden input's serialised payload.
//
// Sprint J of #173.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const ORIG_CRYPTO     = window.crypto;
const ORIG_THUMBMARK  = window.ThumbmarkJS;
const ORIG_FFC_CONFIG = window.ffc_device_config;

/**
 * Deterministic 32-byte hash mock for SubtleCrypto.digest. The output
 * varies with input (so different signals produce different hex strings)
 * but is stable across runs so assertions can be exact.
 */
function fakeDigest(_algorithm, data) {
	const view = new Uint8Array(data);
	const out = new Uint8Array(32);
	for (let i = 0; i < 32; i++) {
		out[i] = (view.length > 0 ? view[i % view.length] + i : i) & 0xff;
	}
	return Promise.resolve(out.buffer);
}

function installCryptoMock() {
	Object.defineProperty(window, 'crypto', {
		value: {
			subtle: { digest: vi.fn(fakeDigest) },
			randomUUID: () => '11111111-2222-3333-4444-555555555555',
			getRandomValues: (arr) => {
				for (let i = 0; i < arr.length; i++) arr[i] = i & 0xff;
				return arr;
			},
		},
		configurable: true,
	});
}

function installThumbmarkMock(fingerprintData = {}) {
	window.ThumbmarkJS = {
		setOption: vi.fn(),
		stableStringify: (v) => JSON.stringify(v),
		getFingerprintData: vi.fn(() => Promise.resolve(fingerprintData)),
	};
}

function fullFingerprintFixture() {
	return {
		system: {
			useragent: 'Mozilla/5.0 (Linux) AppleWebKit/537.36 Chrome/120.0.0.0',
			platform: 'Linux',
			hardwareConcurrency: 8,
		},
		locales: {
			timezone: 'America/Sao_Paulo',
			languages: 'pt-BR',
		},
		hardware: {
			deviceMemory: 16,
		},
		screen:       { width: 1920, height: 1080 },
		canvas:       { hash: 'canvas-abc' },
		audio:        { fingerprint: 0.123456 },
		webgl:        { vendor: 'NVIDIA' },
		fonts:        ['Arial', 'Helvetica'],
		plugins:      ['Chrome PDF'],
		permissions:  { notifications: 'default' },
		mediaQueries: { dark: false },
		math:         { sin: 0.5 },
	};
}

beforeEach(() => {
	document.body.innerHTML = '';
	window.localStorage.clear();
	Object.defineProperty(window, 'crypto', { value: ORIG_CRYPTO, configurable: true });
	window.ThumbmarkJS = ORIG_THUMBMARK;
	window.ffc_device_config = ORIG_FFC_CONFIG;
});

afterEach(() => {
	vi.restoreAllMocks();
});

// Wait long enough for the chained promises in collectSignals to settle.
function flush() { return new Promise((r) => setTimeout(r, 0)).then(() => new Promise((r) => setTimeout(r, 0))); }

describe('ffc-device-signals — fingerprint pipeline', () => {
	it('does not touch any form when no .ffc-submission-form exists on the page', async () => {
		installCryptoMock();
		installThumbmarkMock(fullFingerprintFixture());
		window.ffc_device_config = { signals: ['ua', 'tz'] };

		document.body.innerHTML = '<div>no forms here</div>';
		loadScript('assets/js/ffc-device-signals.js');
		await flush();

		// No hidden input was created.
		expect(document.querySelector('input[name="ffc_device_signals"]')).toBeNull();
	});

	it('creates a hidden #ffc_device_signals input on each ffc-submission-form', async () => {
		installCryptoMock();
		installThumbmarkMock(fullFingerprintFixture());
		window.ffc_device_config = { signals: ['ua', 'tz', 'screen'] };

		document.body.innerHTML = `
			<form class="ffc-submission-form" id="f1"></form>
			<form class="ffc-submission-form" id="f2"></form>
		`;
		loadScript('assets/js/ffc-device-signals.js');
		await flush();

		expect(document.querySelector('#f1 input[name="ffc_device_signals"]')).not.toBeNull();
		expect(document.querySelector('#f2 input[name="ffc_device_signals"]')).not.toBeNull();
	});

	it('hashes each enabled signal and serialises the result as JSON', async () => {
		installCryptoMock();
		installThumbmarkMock(fullFingerprintFixture());
		window.ffc_device_config = { signals: ['ua', 'tz', 'screen', 'canvas'] };

		document.body.innerHTML = '<form class="ffc-submission-form"></form>';
		loadScript('assets/js/ffc-device-signals.js');
		await flush();

		const input = document.querySelector('input[name="ffc_device_signals"]');
		expect(input).not.toBeNull();
		const payload = JSON.parse(input.value);
		// Each enabled signal that mapComponents produced should land as
		// a 64-char hex digest.
		for (const key of ['ua', 'tz', 'screen', 'canvas']) {
			expect(payload[key]).toBeTypeOf('string');
			expect(payload[key]).toMatch(/^[0-9a-f]{64}$/);
		}
		// SHA-256 mock was called once per enabled signal.
		expect(window.crypto.subtle.digest).toHaveBeenCalled();
	});

	it('skips signals that are absent from ffc_device_config.signals (allowlist)', async () => {
		installCryptoMock();
		installThumbmarkMock(fullFingerprintFixture());
		window.ffc_device_config = { signals: ['ua'] }; // only `ua` enabled

		document.body.innerHTML = '<form class="ffc-submission-form"></form>';
		loadScript('assets/js/ffc-device-signals.js');
		await flush();

		const payload = JSON.parse(document.querySelector('input[name="ffc_device_signals"]').value);
		expect(Object.keys(payload)).toEqual(['ua']);
	});

	it("persists a cookie-style device UUID via localStorage when 'cookie' signal is enabled", async () => {
		installCryptoMock();
		installThumbmarkMock(fullFingerprintFixture());
		window.ffc_device_config = { signals: ['cookie'] };

		document.body.innerHTML = '<form class="ffc-submission-form"></form>';
		loadScript('assets/js/ffc-device-signals.js');
		await flush();

		// The script writes a fresh UUID to localStorage on first run.
		expect(window.localStorage.getItem('ffc_device_id')).toBe('11111111-2222-3333-4444-555555555555');
		// And ships the hash in the payload.
		const payload = JSON.parse(document.querySelector('input[name="ffc_device_signals"]').value);
		expect(payload.cookie).toMatch(/^[0-9a-f]{64}$/);
	});

	it('reuses the persisted device UUID on subsequent loads', async () => {
		installCryptoMock();
		installThumbmarkMock(fullFingerprintFixture());
		window.ffc_device_config = { signals: ['cookie'] };

		// Must match the script's UUID-shape regex `/^[0-9a-f-]{30,40}$/i`
		// (hex + dashes, 30-40 chars). Anything else triggers a regen.
		window.localStorage.setItem('ffc_device_id', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		document.body.innerHTML = '<form class="ffc-submission-form"></form>';
		loadScript('assets/js/ffc-device-signals.js');
		await flush();

		expect(window.localStorage.getItem('ffc_device_id')).toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
	});

	it('falls back to getRandomValues when crypto.randomUUID is unavailable', async () => {
		installCryptoMock();
		// Drop randomUUID — script must use the getRandomValues fallback.
		delete window.crypto.randomUUID;
		installThumbmarkMock(fullFingerprintFixture());
		window.ffc_device_config = { signals: ['cookie'] };

		document.body.innerHTML = '<form class="ffc-submission-form"></form>';
		loadScript('assets/js/ffc-device-signals.js');
		await flush();

		const stored = window.localStorage.getItem('ffc_device_id');
		expect(stored).toMatch(/^[0-9a-f-]+$/); // looks like a UUID.
		// Sanity: not the randomUUID value used in installCryptoMock.
		expect(stored).not.toBe('11111111-2222-3333-4444-555555555555');
	});

	it('produces a partial payload (cookie only) when ThumbmarkJS.getFingerprintData rejects', async () => {
		installCryptoMock();
		installThumbmarkMock(); // valid mock
		window.ThumbmarkJS.getFingerprintData = vi.fn(() => Promise.reject(new Error('thumbmark down')));
		window.ffc_device_config = { signals: ['cookie', 'ua', 'tz'] };

		document.body.innerHTML = '<form class="ffc-submission-form"></form>';
		loadScript('assets/js/ffc-device-signals.js');
		await flush();

		const payload = JSON.parse(document.querySelector('input[name="ffc_device_signals"]').value);
		expect(Object.keys(payload)).toEqual(['cookie']);
		expect(payload.cookie).toMatch(/^[0-9a-f]{64}$/);
	});

	it('falls back to JSON.stringify when ThumbmarkJS.stableStringify throws', async () => {
		installCryptoMock();
		installThumbmarkMock(fullFingerprintFixture());
		window.ThumbmarkJS.stableStringify = () => { throw new Error('stringify failed'); };
		window.ffc_device_config = { signals: ['screen'] };

		document.body.innerHTML = '<form class="ffc-submission-form"></form>';
		loadScript('assets/js/ffc-device-signals.js');
		await flush();

		// Screen signal should still hash and land in the payload.
		const payload = JSON.parse(document.querySelector('input[name="ffc_device_signals"]').value);
		expect(payload.screen).toMatch(/^[0-9a-f]{64}$/);
	});

	it('coarse-grains the UA — three-part minor.patch is stripped before hashing', async () => {
		installCryptoMock();
		// The script's regex `/(\d+)\.\d+\.\d+/g, '$1'` strips a 3-part
		// version number, leaving just the major. Two fixtures whose
		// last 3 segments differ should hash identically once stripped.
		const fp1 = fullFingerprintFixture();
		fp1.system.useragent = 'Chrome/120.1.2';
		const fp2 = fullFingerprintFixture();
		fp2.system.useragent = 'Chrome/120.9.8';

		// Run twice with different fingerprints, capture each payload.
		async function runWith(fp) {
			installThumbmarkMock(fp);
			window.ffc_device_config = { signals: ['ua'] };
			document.body.innerHTML = '<form class="ffc-submission-form"></form>';
			loadScript('assets/js/ffc-device-signals.js');
			await flush();
			return JSON.parse(document.querySelector('input[name="ffc_device_signals"]').value).ua;
		}

		const h1 = await runWith(fp1);
		const h2 = await runWith(fp2);
		expect(h1).toBe(h2);
	});

	it('falls back to an ephemeral UUID when localStorage is unavailable', async () => {
		installCryptoMock();
		installThumbmarkMock(fullFingerprintFixture());
		window.ffc_device_config = { signals: ['cookie'] };
		// Force getItem/setItem to throw so getOrCreateDeviceId hits its catch.
		const getSpy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
			throw new Error('storage disabled');
		});
		const setSpy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
			throw new Error('storage disabled');
		});

		document.body.innerHTML = '<form class="ffc-submission-form"></form>';
		loadScript('assets/js/ffc-device-signals.js');
		await flush();

		// A cookie hash still landed in the payload (from the ephemeral id).
		const payload = JSON.parse(document.querySelector('input[name="ffc_device_signals"]').value);
		expect(payload.cookie).toMatch(/^[0-9a-f]{64}$/);
		getSpy.mockRestore();
		setSpy.mockRestore();
	});

	it('falls back to JSON.stringify, then empty string, when both stringifiers throw', async () => {
		installCryptoMock();
		installThumbmarkMock(fullFingerprintFixture());
		window.ThumbmarkJS.stableStringify = () => { throw new Error('stable failed'); };
		window.ffc_device_config = { signals: ['screen'] };

		// Make JSON.stringify throw on the screen object too (circular).
		const circular = {};
		circular.self = circular;
		window.ThumbmarkJS.getFingerprintData = vi.fn(() =>
			Promise.resolve({ ...fullFingerprintFixture(), screen: circular })
		);

		document.body.innerHTML = '<form class="ffc-submission-form"></form>';
		loadScript('assets/js/ffc-device-signals.js');
		await flush();

		// stableStringify threw → JSON.stringify threw (circular) → '' →
		// the empty value is filtered out, so `screen` is absent.
		const payload = JSON.parse(document.querySelector('input[name="ffc_device_signals"]').value);
		expect(payload.screen).toBeUndefined();
	});

	it('produces an empty payload when getFingerprintData rejects and cookie is disabled', async () => {
		installCryptoMock();
		installThumbmarkMock();
		window.ThumbmarkJS.getFingerprintData = vi.fn(() => Promise.reject(new Error('down')));
		// No 'cookie' signal → the catch path returns {} (no cookie to hash).
		window.ffc_device_config = { signals: ['ua', 'tz'] };

		document.body.innerHTML = '<form class="ffc-submission-form"></form>';
		loadScript('assets/js/ffc-device-signals.js');
		await flush();

		const payload = JSON.parse(document.querySelector('input[name="ffc_device_signals"]').value);
		expect(payload).toEqual({});
	});

	it('defers attachToForms to DOMContentLoaded while the document is loading', async () => {
		installCryptoMock();
		installThumbmarkMock(fullFingerprintFixture());
		window.ffc_device_config = { signals: ['ua'] };
		document.body.innerHTML = '<form class="ffc-submission-form"></form>';

		const readyStateSpy = vi
			.spyOn(document, 'readyState', 'get')
			.mockReturnValue('loading');
		const addSpy = vi.spyOn(document, 'addEventListener');

		loadScript('assets/js/ffc-device-signals.js');

		const dclCall = addSpy.mock.calls.find((c) => c[0] === 'DOMContentLoaded');
		expect(dclCall).toBeTruthy();
		readyStateSpy.mockRestore();
		dclCall[1]();
		await flush();

		expect(document.querySelector('input[name="ffc_device_signals"]')).not.toBeNull();
		addSpy.mockRestore();
	});
});
