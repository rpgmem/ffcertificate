// Tests for `assets/js/ffc-dismissible-notice.js` (#839 S7a).
//
// Generic plain-DOM IIFE: for every `.ffc-js-dismiss-notice`, when WordPress's
// `.notice-dismiss` button is clicked, POST `action` + `_ajax_nonce` (read from
// data-attributes) to the global `ajaxurl` via fetch, falling back to
// XMLHttpRequest. Bails when no such notice exists.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-dismissible-notice.js';

function installNotice(extra = '') {
	document.body.innerHTML = `
		<div class="notice notice-error is-dismissible ffc-js-dismiss-notice ffc-encryption-key-health-notice"
			data-ffc-action="ffc_dismiss_encryption_key_health"
			data-ffc-nonce="sig123">
			<p>keys unsafe</p>
			<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>
		</div>
		${extra}
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

describe('ffc-dismissible-notice', () => {
	it('does nothing when no matching notice is present', () => {
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
			'action=ffc_dismiss_encryption_key_health&_ajax_nonce=sig123'
		);
	});

	it('ignores clicks that are not on .notice-dismiss', () => {
		installNotice();
		const fetchSpy = vi.fn(() => Promise.resolve());
		window.fetch = fetchSpy;
		loadScript(SCRIPT);

		document.querySelector('.ffc-js-dismiss-notice p').click();

		expect(fetchSpy).not.toHaveBeenCalled();
	});

	it('does not POST when the data attributes are missing', () => {
		document.body.innerHTML = `
			<div class="notice ffc-js-dismiss-notice">
				<button type="button" class="notice-dismiss"></button>
			</div>
		`;
		const fetchSpy = vi.fn(() => Promise.resolve());
		window.fetch = fetchSpy;
		loadScript(SCRIPT);

		document.querySelector('.notice-dismiss').click();

		expect(fetchSpy).not.toHaveBeenCalled();
	});

	it('binds every matching notice on the page', () => {
		installNotice(`
			<div class="notice ffc-js-dismiss-notice" data-ffc-action="other_action" data-ffc-nonce="n2">
				<button type="button" class="notice-dismiss second"></button>
			</div>
		`);
		const fetchSpy = vi.fn(() => Promise.resolve());
		window.fetch = fetchSpy;
		loadScript(SCRIPT);

		document.querySelector('.notice-dismiss.second').click();

		expect(fetchSpy).toHaveBeenCalledTimes(1);
		expect(fetchSpy.mock.calls[0][1].body).toBe('action=other_action&_ajax_nonce=n2');
	});

	it('falls back to XMLHttpRequest when fetch is unavailable', () => {
		installNotice();
		const realFetch = window.fetch;
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
		expect(send).toHaveBeenCalledWith(
			'action=ffc_dismiss_encryption_key_health&_ajax_nonce=sig123'
		);

		window.XMLHttpRequest = realXHR;
		window.fetch = realFetch;
	});
});
