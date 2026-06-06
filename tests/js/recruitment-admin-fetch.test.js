// Tests for assets/js/ffc-recruitment-admin.js — the thin REST `fetch`
// wrapper published on window.ffcRecruitmentAdmin.fetch. Covers the
// undefined-config bail, the helper publication, nonce + same-origin
// credential injection, and the { status, body } success / null-body
// failure shaping.
//
// (The delegated create-endpoint / colour-endpoint handlers live in the
//  sibling suite tests/js/recruitment-admin.test.js.)
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcRecruitmentAdmin = undefined;
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('ffcRecruitmentAdmin.fetch', () => {
	it('bails when window.ffcRecruitmentAdmin is undefined', () => {
		// No localized object → script returns early; nothing is exposed.
		loadScript('assets/js/ffc-recruitment-admin.js');
		expect(window.ffcRecruitmentAdmin).toBeUndefined();
	});

	it('publishes a fetch helper when the localized object exists', () => {
		window.ffcRecruitmentAdmin = { restRoot: '/wp-json/ffc/v1/recruitment/', nonce: 'r-nonce' };
		loadScript('assets/js/ffc-recruitment-admin.js');
		expect(typeof window.ffcRecruitmentAdmin.fetch).toBe('function');
	});

	it('injects the X-WP-Nonce header + same-origin credentials', async () => {
		window.ffcRecruitmentAdmin = { restRoot: '/wp-json/ffc/v1/recruitment/', nonce: 'r-nonce' };
		loadScript('assets/js/ffc-recruitment-admin.js');

		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
			status: 200,
			json: () => Promise.resolve({ ok: true }),
		});

		await window.ffcRecruitmentAdmin.fetch('notices', { method: 'GET' });

		expect(fetchMock).toHaveBeenCalledOnce();
		const [url, opts] = fetchMock.mock.calls[0];
		expect(url).toBe('/wp-json/ffc/v1/recruitment/notices');
		expect(opts.headers['X-WP-Nonce']).toBe('r-nonce');
		expect(opts.credentials).toBe('same-origin');
	});

	it('resolves { status, body } on success', async () => {
		window.ffcRecruitmentAdmin = { restRoot: '/wp-json/ffc/v1/recruitment/', nonce: 'n' };
		loadScript('assets/js/ffc-recruitment-admin.js');

		vi.spyOn(globalThis, 'fetch').mockResolvedValue({
			status: 201,
			json: () => Promise.resolve({ id: 42 }),
		});
		const result = await window.ffcRecruitmentAdmin.fetch('notices');
		expect(result.status).toBe(201);
		expect(result.body).toEqual({ id: 42 });
	});

	it('resolves with body=null when JSON parsing fails', async () => {
		window.ffcRecruitmentAdmin = { restRoot: '/wp-json/ffc/v1/recruitment/', nonce: 'n' };
		loadScript('assets/js/ffc-recruitment-admin.js');

		vi.spyOn(globalThis, 'fetch').mockResolvedValue({
			status: 500,
			json: () => Promise.reject(new Error('not json')),
		});
		const result = await window.ffcRecruitmentAdmin.fetch('x');
		expect(result.status).toBe(500);
		expect(result.body).toBeNull();
	});
});
