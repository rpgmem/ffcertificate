// More unit tests for `window.FFCGeofence` defined in
// `assets/js/ffc-geofence-frontend.js` — covers the pure helpers and DOM-
// manipulation methods left at 0% after #160. Sprint A of #168.
//
// Companion to `geofence-frontend.test.js` (validateDateTime / pickHideMode).
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	// `window.ffcGeofenceConfig._global.strings` is what `getString()` looks
	// up; populate it so the i18n-key tests can prove the lookup path.
	window.ffcGeofenceConfig = {
		_global: {
			strings: {
				someKey: 'translated-some-key',
			},
		},
	};
	loadScript('assets/js/ffc-geofence-frontend.js');
});

beforeEach(() => {
	// Each test starts with a fresh form wrapper at #ffc-form-1.
	document.body.innerHTML = `
		<div id="ffc-form-1" class="ffc-form-wrapper">
			<h2 class="ffc-form-title">Title</h2>
			<form class="ffc-submission-form"><input name="x" /></form>
		</div>
	`;
	// Clear localStorage between tests so cache state doesn't bleed.
	window.localStorage.clear();
});

const G = () => window.FFCGeofence;

// ----------------------------------------------------------------------
// getString — i18n lookup with fallback
// ----------------------------------------------------------------------

describe('FFCGeofence.getString', () => {
	it('returns the translated value when the key exists', () => {
		expect(G().getString('someKey', 'fallback')).toBe('translated-some-key');
	});

	it('returns the fallback when the key is missing', () => {
		expect(G().getString('unknownKey', 'fallback-value')).toBe('fallback-value');
	});

	it('returns the fallback when the strings object is missing entirely', () => {
		const saved = window.ffcGeofenceConfig;
		window.ffcGeofenceConfig = undefined;
		try {
			expect(G().getString('anything', 'fb')).toBe('fb');
		} finally {
			window.ffcGeofenceConfig = saved;
		}
	});
});

// ----------------------------------------------------------------------
// Pure formatters — formatDate / formatTime / escapeHtml
// ----------------------------------------------------------------------

describe('FFCGeofence.formatDate', () => {
	it('zero-pads single-digit month and day', () => {
		expect(G().formatDate(new Date(2026, 0, 5))).toBe('2026-01-05');  // Jan = month 0
	});

	it('handles a two-digit month and day without padding', () => {
		expect(G().formatDate(new Date(2026, 11, 31))).toBe('2026-12-31'); // Dec = month 11
	});
});

describe('FFCGeofence.formatTime', () => {
	it('zero-pads single-digit hour and minute', () => {
		expect(G().formatTime(new Date(2026, 0, 1, 7, 5))).toBe('07:05');
	});

	it('handles double-digit hour and minute', () => {
		expect(G().formatTime(new Date(2026, 0, 1, 23, 59))).toBe('23:59');
	});
});

describe('FFCGeofence.escapeHtml', () => {
	it('escapes <, >, &, and quote characters', () => {
		const out = G().escapeHtml('<script>alert("x&y")</script>');
		// Implementation uses textContent → innerHTML, so the encoded form
		// is the standard HTML entity set. Just assert the dangerous chars
		// are gone.
		expect(out).not.toContain('<script>');
		expect(out).toContain('&lt;');
		expect(out).toContain('&gt;');
		expect(out).toContain('&amp;');
	});

	it('leaves plain text unchanged', () => {
		expect(G().escapeHtml('hello world')).toBe('hello world');
	});
});

// ----------------------------------------------------------------------
// Haversine distance — calculateDistance / deg2rad
// ----------------------------------------------------------------------

describe('FFCGeofence.calculateDistance', () => {
	it('returns 0 for identical coordinates', () => {
		expect(G().calculateDistance(0, 0, 0, 0)).toBe(0);
	});

	it('approximates the well-known São Paulo → Rio distance (~360 km)', () => {
		// Source coords: SP (-23.5505, -46.6333), RJ (-22.9068, -43.1729).
		// Real great-circle distance ≈ 360 km. Function returns metres.
		const metres = G().calculateDistance(-23.5505, -46.6333, -22.9068, -43.1729);
		const km = metres / 1000;
		expect(km).toBeGreaterThan(355);
		expect(km).toBeLessThan(365);
	});

	it('is symmetric — d(a,b) == d(b,a)', () => {
		const ab = G().calculateDistance(10, 20, 30, 40);
		const ba = G().calculateDistance(30, 40, 10, 20);
		expect(Math.abs(ab - ba)).toBeLessThan(1e-6);
	});
});

describe('FFCGeofence.deg2rad', () => {
	it('converts 180° to π', () => {
		expect(G().deg2rad(180)).toBeCloseTo(Math.PI, 10);
	});

	it('converts 0° to 0', () => {
		expect(G().deg2rad(0)).toBe(0);
	});

	it('converts -90° to -π/2', () => {
		expect(G().deg2rad(-90)).toBeCloseTo(-Math.PI / 2, 10);
	});
});

// ----------------------------------------------------------------------
// Browser detection — isSafari
// ----------------------------------------------------------------------

describe('FFCGeofence.isSafari', () => {
	const origUA = navigator.userAgent;
	const origMaxTouch = navigator.maxTouchPoints;

	function setUA(ua, maxTouchPoints = 0) {
		Object.defineProperty(navigator, 'userAgent', { value: ua, configurable: true });
		Object.defineProperty(navigator, 'maxTouchPoints', { value: maxTouchPoints, configurable: true });
	}

	afterEach(() => {
		setUA(origUA, origMaxTouch);
	});

	it('returns true for iPhone UA', () => {
		setUA('Mozilla/5.0 (iPhone; CPU iPhone OS 15_4 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1');
		expect(G().isSafari()).toBe(true);
	});

	it('returns true for desktop Safari UA', () => {
		setUA('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/15.0 Safari/605.1.15');
		expect(G().isSafari()).toBe(true);
	});

	it('returns true for iPad-as-desktop (maxTouchPoints > 1 + Macintosh UA)', () => {
		setUA('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/15.0 Safari/605.1.15', 5);
		expect(G().isSafari()).toBe(true);
	});

	it('returns false for Chrome UA', () => {
		setUA('Mozilla/5.0 (Windows NT 10.0; Win64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36');
		expect(G().isSafari()).toBe(false);
	});

	it('returns false for Firefox UA', () => {
		setUA('Mozilla/5.0 (X11; Linux x86_64; rv:122.0) Gecko/20100101 Firefox/122.0');
		expect(G().isSafari()).toBe(false);
	});
});

// ----------------------------------------------------------------------
// Location cache — localStorage roundtrip with TTL
// ----------------------------------------------------------------------

describe('FFCGeofence.getLocationCache / setLocationCache', () => {
	it('round-trips a location under the same formId', () => {
		G().setLocationCache('ffc-form-42', { latitude: -23.5, longitude: -46.6, accuracy: 10 }, 600);
		const got = G().getLocationCache('ffc-form-42');
		expect(got).toEqual({ latitude: -23.5, longitude: -46.6, accuracy: 10 });
	});

	it('returns null when no cache entry exists for the formId', () => {
		expect(G().getLocationCache('ffc-form-missing')).toBeNull();
	});

	it('returns null and clears the entry when the TTL expired', () => {
		// Set with TTL of -10s — already expired.
		G().setLocationCache('ffc-form-7', { latitude: 1, longitude: 2 }, -10);
		expect(G().getLocationCache('ffc-form-7')).toBeNull();
		expect(window.localStorage.getItem('ffc_geo_ffc-form-7')).toBeNull();
	});

	it('returns null when the cached JSON is malformed', () => {
		window.localStorage.setItem('ffc_geo_ffc-form-junk', 'not-json{');
		expect(G().getLocationCache('ffc-form-junk')).toBeNull();
	});
});

// ----------------------------------------------------------------------
// Blocked-message rendering — handleBlocked / showBlockedMessage
// ----------------------------------------------------------------------

describe('FFCGeofence.handleBlocked', () => {
	function wrapper() { return window.jQuery('#ffc-form-1'); }

	it("hides the form entirely when hideMode === 'hide'", () => {
		G().handleBlocked(wrapper(), 'hide', 'Blocked.');
		const el = document.getElementById('ffc-form-1');
		// jQuery .hide() sets inline display:none
		expect(el.style.display).toBe('none');
	});

	it("hides the form fields and shows a blocked message when hideMode === 'message'", () => {
		G().handleBlocked(wrapper(), 'message', 'You are blocked');
		const root = document.getElementById('ffc-form-1');
		expect(root.querySelector('.ffc-submission-form').style.display).toBe('none');
		expect(root.querySelector('.ffc-form-title').style.display).toBe('none');
		const blocked = root.querySelector('.ffc-geofence-blocked');
		expect(blocked).not.toBeNull();
		expect(blocked.textContent).toContain('You are blocked');
	});

	it('escapes HTML in the blocked message to prevent XSS', () => {
		G().handleBlocked(wrapper(), 'message', '<img src=x onerror=alert(1)>');
		const blocked = document.querySelector('#ffc-form-1 .ffc-geofence-blocked');
		// The img tag must not have been parsed as HTML.
		expect(blocked.innerHTML).not.toContain('<img');
		expect(blocked.innerHTML).toContain('&lt;img');
	});
});

describe('FFCGeofence.showAdminBypassMessages', () => {
	function wrapper() { return window.jQuery('#ffc-form-1'); }

	it('renders a generic bypass message when bypassInfo is null', () => {
		G().showAdminBypassMessages(wrapper(), null);
		const bypass = document.querySelector('#ffc-form-1 .ffc-geofence-admin-bypass');
		expect(bypass).not.toBeNull();
		expect(bypass.textContent).toMatch(/Bypass|bypass/);
	});

	it('renders a datetime-specific message when hasDatetime is true', () => {
		G().showAdminBypassMessages(wrapper(), { hasDatetime: true, hasGeo: false });
		const messages = document.querySelectorAll('#ffc-form-1 .ffc-geofence-admin-bypass');
		expect(messages.length).toBe(1);
		expect(messages[0].textContent).toMatch(/Date|date|Time|time/);
	});

	it('renders both datetime and geo messages when both flags are set', () => {
		G().showAdminBypassMessages(wrapper(), { hasDatetime: true, hasGeo: true });
		const messages = document.querySelectorAll('#ffc-form-1 .ffc-geofence-admin-bypass');
		expect(messages.length).toBe(2);
	});
});

// ----------------------------------------------------------------------
// Form show / reset — class toggling
// ----------------------------------------------------------------------

describe('FFCGeofence.showForm / resetForm', () => {
	function wrapper() { return window.jQuery('#ffc-form-1'); }

	it("adds 'ffc-validated' class on showForm", () => {
		G().showForm(wrapper());
		expect(document.getElementById('ffc-form-1').classList.contains('ffc-validated')).toBe(true);
	});

	it("removes 'ffc-validated' class and clears blocked messages on resetForm", () => {
		G().handleBlocked(wrapper(), 'message', 'something');
		G().showForm(wrapper());
		// Sanity: both states present.
		expect(document.querySelector('#ffc-form-1 .ffc-geofence-blocked')).not.toBeNull();

		G().resetForm(wrapper());
		expect(document.getElementById('ffc-form-1').classList.contains('ffc-validated')).toBe(false);
		expect(document.querySelector('#ffc-form-1 .ffc-geofence-blocked')).toBeNull();
	});
});

// ----------------------------------------------------------------------
// Loading-message lifecycle
// ----------------------------------------------------------------------

describe('FFCGeofence.show/update/hideLoadingMessage', () => {
	function wrapper() { return window.jQuery('#ffc-form-1'); }

	it('show appends a loading message with a spinner', () => {
		G().showLoadingMessage(wrapper(), 'Detecting…');
		const el = document.querySelector('#ffc-form-1 .ffc-geofence-loading-msg');
		expect(el).not.toBeNull();
		expect(el.textContent).toContain('Detecting…');
		expect(el.querySelector('.ffc-spinner')).not.toBeNull();
	});

	it('update rewrites the message text without losing the spinner', () => {
		G().showLoadingMessage(wrapper(), 'Phase 1');
		G().updateLoadingMessage(wrapper(), 'Phase 2');
		const el = document.querySelector('#ffc-form-1 .ffc-geofence-loading-msg');
		expect(el.textContent).toContain('Phase 2');
		expect(el.textContent).not.toContain('Phase 1');
	});

	it('hide removes the loading-msg element entirely', () => {
		G().showLoadingMessage(wrapper(), 'gone soon');
		G().hideLoadingMessage(wrapper());
		expect(document.querySelector('#ffc-form-1 .ffc-geofence-loading-msg')).toBeNull();
	});
});

// ----------------------------------------------------------------------
// applyGpsFallback — allow vs block branches
// ----------------------------------------------------------------------

describe('FFCGeofence.applyGpsFallback', () => {
	function wrapper() { return window.jQuery('#ffc-form-1'); }

	it("shows the form when gpsFallback === 'allow'", () => {
		G().applyGpsFallback(wrapper(), { gpsFallback: 'allow', hideMode: 'message' });
		expect(document.getElementById('ffc-form-1').classList.contains('ffc-validated')).toBe(true);
	});

	it("blocks the form when gpsFallback === 'block'", () => {
		G().applyGpsFallback(wrapper(), { gpsFallback: 'block', hideMode: 'message' });
		const root = document.getElementById('ffc-form-1');
		expect(root.classList.contains('ffc-validated')).toBe(false);
		expect(document.querySelector('#ffc-form-1 .ffc-geofence-blocked')).not.toBeNull();
	});
});
