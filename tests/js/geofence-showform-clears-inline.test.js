// Regression test for the post-geofence "only title shows" complaint
// reported after #191.
//
// What #191 changed:
//   - CSS hide rule `.ffc-shortcode.ffc-has-geofence .ffc-submission-form`
//     now actually matches (used to be a broken descendant selector).
//   - That correctly kills the form-flash before validation completes.
//
// What broke as a consequence:
//   - validateGeolocation calls `.hide()` on the form before requesting
//     GPS, which sets an inline `display: none`.
//   - On success, showForm() only adds `.ffc-validated` to the wrapper —
//     it never clears the inline display.
//   - The matching show rule (`.ffc-shortcode.ffc-has-geofence.ffc-validated
//     .ffc-submission-form { display: block !important; }`) should beat
//     inline non-important per CSS spec, but in the user's browser the
//     form stayed hidden. Whether the cause is a browser-specific quirk
//     or a real spec deviation, the script must not rely on the
//     `!important` override — it should clear the inline style explicitly
//     so the form becomes visible regardless of CSS resolution order.
//
// Refreshing the page hides the bug because the cache-hit path in
// `validateGeolocation` short-circuits BEFORE the `.hide()` call, so
// there's no inline display to clear.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-geofence-frontend.js');
	loadScript('assets/js/ffc-geofence-datetime.js');
	loadScript('assets/js/ffc-geofence-gps.js');
	loadScript('assets/js/ffc-geofence-preflight.js');
});

function installLocationHttps() {
	const original = window.location;
	Object.defineProperty(window, 'location', {
		configurable: true,
		writable: true,
		value: { protocol: 'https:', hostname: 'example.com', pathname: '/' },
	});
	return () => {
		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: original,
		});
	};
}

function mountForm(id = 777) {
	document.body.innerHTML = `
		<div id="ffc-form-${id}" class="ffc-shortcode ffc-form-wrapper ffc-has-geofence">
			<h2 class="ffc-form-title">Title</h2>
			<form class="ffc-submission-form">body</form>
		</div>
	`;
	return window.$('#ffc-form-' + id);
}

beforeEach(() => {
	document.body.innerHTML = '';
	// Clear any cached locations from prior tests.
	try {
		Object.keys(window.localStorage).forEach((k) => {
			if (k.startsWith('ffc_geo_')) {
				window.localStorage.removeItem(k);
			}
		});
	} catch (_) {
		/* ignore */
	}
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('FFCGeofence.showForm — clears inline display:none from validateGeolocation', () => {
	it('after a no-cache GPS success: form body has no inline display:none', () => {
		const restoreLoc = installLocationHttps();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (success) => {
					success({ coords: { latitude: 0, longitude: 0, accuracy: 5 } });
				},
			},
		});
		const $w = mountForm(777);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1 }],
		});

		// Wrapper has the validated class.
		expect($w.hasClass('ffc-validated')).toBe(true);
		// Inline style.display has been cleared so the form can become
		// visible via the CSS show rule. jQuery's `.show()` resets it to
		// '' (empty string) when no prior non-default value existed.
		const inlineDisplay = $w.find('.ffc-submission-form')[0].style.display;
		expect(inlineDisplay).not.toBe('none');

		restoreLoc();
	});

	it('after a cache-hit showForm: also no inline display:none', () => {
		vi.useFakeTimers();
		const restoreLoc = installLocationHttps();
		// Seed a pass token (recent successful validation).
		window.localStorage.setItem(
			'ffc_geo_ffc-form-778',
			JSON.stringify({
				validated: true,
				expires: Math.floor(Date.now() / 1000) + 600,
			}),
		);
		// Also stub geolocation so it's not undefined.
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: vi.fn() },
		});
		const $w = mountForm(778);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1 }],
		});

		// Cache-hit now mounts the spinner first and only resolves to
		// showForm after MIN_LOADING_MS — fast-forward past it.
		vi.advanceTimersByTime(window.FFCGeofence.MIN_LOADING_MS + 50);

		expect($w.hasClass('ffc-validated')).toBe(true);
		const inlineDisplay = $w.find('.ffc-submission-form')[0].style.display;
		expect(inlineDisplay).not.toBe('none');

		restoreLoc();
		vi.useRealTimers();
	});

	it('direct showForm() call clears prior inline display:none', () => {
		const $w = mountForm(779);
		// Pretend validateGeolocation set display:none on the form.
		$w.find('.ffc-submission-form').hide();
		expect($w.find('.ffc-submission-form')[0].style.display).toBe('none');

		window.FFCGeofence.showForm($w);

		expect($w.hasClass('ffc-validated')).toBe(true);
		expect($w.find('.ffc-submission-form')[0].style.display).not.toBe('none');
	});
});
