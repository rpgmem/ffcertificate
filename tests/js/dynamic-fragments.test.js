// Tests for `assets/js/ffc-dynamic-fragments.js`.
//
// The script is a pure IIFE that:
//   1. Bails early if the page has none of the FFC interactive elements
//      (`.ffc-captcha-row`, `.ffc-verification-form`, etc.).
//   2. Bails if it can't find an ajax URL via `ffcDynamic` / `ffc_ajax`
//      / `ffcCalendar`.
//   3. Otherwise fires an XHR to `ffc_get_dynamic_fragments` and patches
//      the DOM with fresh captcha, nonces, user data, and geofence config.
//
// Sprint F of #170.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// Stash the original XMLHttpRequest so tests can restore it.
const RealXHR = window.XMLHttpRequest;

class MockXHR {
	constructor() {
		MockXHR.lastInstance = this;
		this.status = 200;
		this.responseText = '{}';
		this.requestHeaders = {};
		this.payload = '';
	}
	open(method, url) { this.method = method; this.url = url; }
	setRequestHeader(k, v) { this.requestHeaders[k] = v; }
	send(payload) { this.payload = payload; MockXHR.sendCount++; }
	// Test helper — triggers the onload handler with the canned response.
	deliver(responseObj, status = 200) {
		this.status = status;
		this.responseText = JSON.stringify(responseObj);
		if (typeof this.onload === 'function') this.onload();
	}
}
MockXHR.sendCount = 0;
MockXHR.lastInstance = null;

function installXHRMock() {
	MockXHR.sendCount = 0;
	MockXHR.lastInstance = null;
	window.XMLHttpRequest = MockXHR;
}

beforeEach(() => {
	document.body.innerHTML = '';
	window.XMLHttpRequest = RealXHR;
	window.ffcDynamic = undefined;
	window.ffc_ajax = undefined;
	window.ffcCalendar = undefined;
	window.ffcGeofenceConfig = undefined;
});

describe('ffc-dynamic-fragments — early returns', () => {
	it('does nothing when no FFC element is on the page', () => {
		installXHRMock();
		document.body.innerHTML = '<div>nothing here</div>';
		window.ffcDynamic = { ajaxUrl: '/ajax' };
		loadScript('assets/js/ffc-dynamic-fragments.js');
		expect(MockXHR.sendCount).toBe(0);
	});

	it('does nothing when no ajaxUrl source is configured', () => {
		installXHRMock();
		document.body.innerHTML = '<div class="ffc-captcha-row"></div>';
		// ffcDynamic / ffc_ajax / ffcCalendar all undefined.
		loadScript('assets/js/ffc-dynamic-fragments.js');
		expect(MockXHR.sendCount).toBe(0);
	});

	it('fires XHR when there is a captcha row + an ajaxUrl', () => {
		installXHRMock();
		document.body.innerHTML = '<div class="ffc-captcha-row"></div>';
		window.ffcDynamic = { ajaxUrl: '/wp-admin/admin-ajax.php' };
		loadScript('assets/js/ffc-dynamic-fragments.js');
		expect(MockXHR.sendCount).toBe(1);
		expect(MockXHR.lastInstance.method).toBe('POST');
		expect(MockXHR.lastInstance.url).toBe('/wp-admin/admin-ajax.php');
		expect(MockXHR.lastInstance.payload).toContain('action=ffc_get_dynamic_fragments');
	});

	it('falls back to ffc_ajax when ffcDynamic is missing', () => {
		installXHRMock();
		document.body.innerHTML = '<div class="ffc-form-container"></div>';
		window.ffc_ajax = { ajax_url: '/fallback' };
		loadScript('assets/js/ffc-dynamic-fragments.js');
		expect(MockXHR.lastInstance.url).toBe('/fallback');
	});

	it('collects form_ids from ffc-form-* wrappers into the payload', () => {
		installXHRMock();
		document.body.innerHTML = `
			<div class="ffc-form-wrapper" id="ffc-form-42">
				<div class="ffc-captcha-row"></div>
			</div>
			<div class="ffc-form-wrapper" id="ffc-form-99"></div>
		`;
		window.ffcDynamic = { ajaxUrl: '/x' };
		loadScript('assets/js/ffc-dynamic-fragments.js');
		expect(MockXHR.lastInstance.payload).toContain('form_ids%5B%5D=42');
		expect(MockXHR.lastInstance.payload).toContain('form_ids%5B%5D=99');
	});
});

describe('ffc-dynamic-fragments — applyFragments via XHR onload', () => {
	beforeEach(() => {
		installXHRMock();
	});

	function setupAndDeliver(html, response) {
		document.body.innerHTML = html;
		window.ffcDynamic = { ajaxUrl: '/x' };
		loadScript('assets/js/ffc-dynamic-fragments.js');
		MockXHR.lastInstance.deliver(response);
	}

	it('updates the per-form captcha (label, hash, blanks the answer)', () => {
		setupAndDeliver(
			`<div class="ffc-form-wrapper" id="ffc-form-7">
				<div class="ffc-captcha-row"><span class="ffc-captcha-label-text">old-label</span></div>
				<input type="hidden" name="ffc_captcha_hash" value="old-hash" />
				<input type="text" name="ffc_captcha_ans" value="123" />
			</div>`,
			{
				success: true,
				data: {
					captchas: {
						'7': { label: 'new-label', hash: 'new-hash' },
					},
				},
			}
		);
		expect(document.querySelector('.ffc-captcha-label-text').textContent).toBe('new-label');
		expect(document.querySelector('input[name="ffc_captcha_hash"]').value).toBe('new-hash');
		expect(document.querySelector('input[name="ffc_captcha_ans"]').value).toBe('');
	});

	it('updates the default captcha when no per-form captchas matched', () => {
		setupAndDeliver(
			`<div class="ffc-form-container">
				<div class="ffc-captcha-row"><span class="ffc-captcha-label-text">stale</span></div>
				<input type="hidden" name="ffc_captcha_hash" value="stale-hash" />
				<input type="text" name="ffc_captcha_ans" value="abc" />
			</div>`,
			{
				success: true,
				data: {
					captcha: { label: 'fresh', hash: 'fresh-hash' },
				},
			}
		);
		expect(document.querySelector('.ffc-captcha-label-text').textContent).toBe('fresh');
		expect(document.querySelector('input[name="ffc_captcha_hash"]').value).toBe('fresh-hash');
		expect(document.querySelector('input[name="ffc_captcha_ans"]').value).toBe('');
	});

	it('refreshes ffcGeofenceConfig and triggers FFCGeofence.recheck()', () => {
		// Pre-existing geofence config + recheck spy.
		window.ffcGeofenceConfig = { 7: { datetime: { enabled: false } } };
		window.FFCGeofence = { recheck: vi.fn() };

		setupAndDeliver(
			`<div class="ffc-form-wrapper" id="ffc-form-7"><div class="ffc-captcha-row"></div></div>`,
			{
				success: true,
				data: { geofence: { 7: { datetime: { enabled: true, dateStart: '2026-01-01' } } } },
			}
		);
		expect(window.ffcGeofenceConfig[7].datetime.enabled).toBe(true);
		expect(window.FFCGeofence.recheck).toHaveBeenCalledOnce();
	});

	it('does nothing when response.success is false', () => {
		setupAndDeliver(
			`<div class="ffc-form-wrapper" id="ffc-form-9">
				<div class="ffc-captcha-row"><span class="ffc-captcha-label-text">stale</span></div>
			</div>`,
			{ success: false, data: { captchas: { 9: { label: 'x', hash: 'y' } } } }
		);
		expect(document.querySelector('.ffc-captcha-label-text').textContent).toBe('stale');
	});

	it('does nothing when xhr.status !== 200', () => {
		document.body.innerHTML = '<div class="ffc-form-wrapper" id="ffc-form-1"><div class="ffc-captcha-row"><span class="ffc-captcha-label-text">stale</span></div></div>';
		window.ffcDynamic = { ajaxUrl: '/x' };
		loadScript('assets/js/ffc-dynamic-fragments.js');
		MockXHR.lastInstance.deliver({ success: true, data: { captcha: { label: 'fresh', hash: 'h' } } }, 500);
		expect(document.querySelector('.ffc-captcha-label-text').textContent).toBe('stale');
	});

	it('silently swallows JSON parse errors', () => {
		document.body.innerHTML = '<div class="ffc-form-container"><div class="ffc-captcha-row"></div></div>';
		window.ffcDynamic = { ajaxUrl: '/x' };
		loadScript('assets/js/ffc-dynamic-fragments.js');
		const xhr = MockXHR.lastInstance;
		xhr.responseText = 'not-json{';
		// Should not throw.
		expect(() => { if (typeof xhr.onload === 'function') xhr.onload(); }).not.toThrow();
	});

	it('refreshes the ffc_ajax / ffcCalendar / ffcAudience nonces and hidden nonce fields', () => {
		window.ffc_ajax = { ajax_url: '/x', nonce: 'frontend-stale' };
		window.ffcCalendar = { ajaxurl: '/x', nonce: 'ss-stale' };
		window.ffcAudience = { nonce: 'rest-stale', searchUsersNonce: 'su-stale' };

		setupAndDeliver(
			`<div class="ffc-form-container"><div class="ffc-captcha-row"></div></div>
			 <form id="ffc-self-scheduling-form"><input name="nonce" value="ss-field-stale" /></form>
			 <div class="ffc-public-csv-download"><input name="_ffc_pcd_nonce" value="pcd-stale" /></div>`,
			{
				success: true,
				data: {
					nonces: {
						ffc_frontend_nonce: 'frontend-fresh',
						ffc_self_scheduling_nonce: 'ss-fresh',
						ffc_public_csv_download: 'pcd-fresh',
						wp_rest: 'rest-fresh',
						ffc_search_users: 'su-fresh',
					},
				},
			}
		);

		expect(window.ffc_ajax.nonce).toBe('frontend-fresh');
		expect(window.ffcCalendar.nonce).toBe('ss-fresh');
		expect(window.ffcAudience.nonce).toBe('rest-fresh');
		expect(window.ffcAudience.searchUsersNonce).toBe('su-fresh');
		expect(document.querySelector('#ffc-self-scheduling-form input[name="nonce"]').value).toBe('ss-fresh');
		expect(document.querySelector('.ffc-public-csv-download input[name="_ffc_pcd_nonce"]').value).toBe('pcd-fresh');

		delete window.ffcAudience;
	});

	it('pre-fills and locks the booking name/email fields', () => {
		setupAndDeliver(
			`<div class="ffc-booking-form"><div class="ffc-captcha-row"></div></div>
			 <input id="ffc-booking-name" />
			 <input id="ffc-booking-email" />`,
			{
				success: true,
				data: { user: { name: 'Maria', email: 'maria@example.com' } },
			}
		);

		const name = document.getElementById('ffc-booking-name');
		const email = document.getElementById('ffc-booking-email');
		expect(name.value).toBe('Maria');
		expect(name.getAttribute('readonly')).toBe('readonly');
		expect(email.value).toBe('maria@example.com');
		expect(email.getAttribute('readonly')).toBe('readonly');
	});
});
