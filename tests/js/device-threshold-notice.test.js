// Tests for `assets/js/ffc-device-threshold-notice.js`.
//
// The script is a plain-DOM IIFE (no jQuery) extracted from an inline
// <script> in class-ffc-device-threshold-upgrade-notice.php (Item 10 of the
// frontend audit). On load it:
//   1. Finds `.ffc-device-threshold-notice`; bails if absent.
//   2. Delegates click on the notice; only acts on `.notice-dismiss`.
//   3. POSTs `action` + `_ajax_nonce` (read from data-attributes) to the
//      global `ajaxurl` via fetch, falling back to XMLHttpRequest.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-device-threshold-notice.js';

function installNotice() {
	document.body.innerHTML = `
		<div class="notice notice-info is-dismissible ffc-device-threshold-notice"
			data-ffc-action="ffc_dismiss_device_threshold_v632"
			data-ffc-nonce="abc123">
			<p>nudge</p>
			<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>
		</div>
	`;
}

beforeEach(() => {
	window.ajaxurl = 'https://example.com/wp-admin/admin-ajax.php';
});

afterEach(() => {
	document.body.innerHTML = '';
	delete window.ajaxurl;
	vi.restoreAllMocks();
});

describe('ffc-device-threshold-notice', () => {
	it('does nothing when the notice is absent', () => {
		document.body.innerHTML = '<div>no notice</div>';
		const fetchSpy = vi.fn();
		window.fetch = fetchSpy;
		expect(() => loadScript(SCRIPT)).not.toThrow();
		expect(fetchSpy).not.toHaveBeenCalled();
	});

	it('POSTs the dismissal via fetch when .notice-dismiss is clicked', () => {
		installNotice();
		const fetchSpy = vi.fn(() => Promise.resolve());
		window.fetch = fetchSpy;
		loadScript(SCRIPT);

		document.querySelector('.notice-dismiss').click();

		expect(fetchSpy).toHaveBeenCalledTimes(1);
		const [url, opts] = fetchSpy.mock.calls[0];
		expect(url).toBe(window.ajaxurl);
		expect(opts.method).toBe('POST');
		expect(opts.credentials).toBe('same-origin');
		expect(opts.headers['Content-Type']).toBe('application/x-www-form-urlencoded');
		expect(opts.body).toBe(
			'action=ffc_dismiss_device_threshold_v632&_ajax_nonce=abc123'
		);
	});

	it('ignores clicks that are not on .notice-dismiss', () => {
		installNotice();
		const fetchSpy = vi.fn(() => Promise.resolve());
		window.fetch = fetchSpy;
		loadScript(SCRIPT);

		document.querySelector('.ffc-device-threshold-notice p').click();

		expect(fetchSpy).not.toHaveBeenCalled();
	});

	it('falls back to XMLHttpRequest when fetch is unavailable', () => {
		installNotice();
		const realFetch = window.fetch;
		// Force the XHR branch.
		window.fetch = undefined;

		const open = vi.fn();
		const setRequestHeader = vi.fn();
		const send = vi.fn();
		const XhrMock = vi.fn(function () {
			this.open = open;
			this.setRequestHeader = setRequestHeader;
			this.send = send;
		});
		const realXHR = window.XMLHttpRequest;
		window.XMLHttpRequest = XhrMock;

		loadScript(SCRIPT);
		document.querySelector('.notice-dismiss').click();

		expect(open).toHaveBeenCalledWith('POST', window.ajaxurl, true);
		expect(setRequestHeader).toHaveBeenCalledWith(
			'Content-Type',
			'application/x-www-form-urlencoded'
		);
		expect(send).toHaveBeenCalledWith(
			'action=ffc_dismiss_device_threshold_v632&_ajax_nonce=abc123'
		);

		window.XMLHttpRequest = realXHR;
		window.fetch = realFetch;
	});
});
