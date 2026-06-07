// Tests for the FFC.rest helper — sibling of FFC.request for endpoints
// living on the WP REST surface (X-WP-Nonce header, JSON body, full URL).
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'core-nonce',
		strings: {
			error: 'Generic error',
			connectionError: 'Lost connection',
		},
	};
	loadScript('assets/js/ffc-core.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.FFC.config.restNonce = '';
});

afterEach(() => {
	vi.restoreAllMocks();
});

function mockAjax(impl) {
	return vi.spyOn(window.$, 'ajax').mockImplementation(impl);
}

function ajaxResolver(response, fail) {
	return (opts) => {
		if (fail) {
			if (opts.error) opts.error(response);
		} else if (opts.success) {
			opts.success(response);
		}
		return {};
	};
}

describe('FFC.rest — request composition', () => {
	it('defaults to GET, sends data as query string, includes X-WP-Nonce header', async () => {
		const ajaxSpy = mockAjax(ajaxResolver({ items: [1, 2] }));

		const result = await window.FFC.rest('/wp-json/ffc/v1/user/summary', {
			data: { foo: 'bar' },
			nonce: 'rest-n',
		});

		expect(ajaxSpy).toHaveBeenCalledOnce();
		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.url).toBe('/wp-json/ffc/v1/user/summary');
		expect(opts.method).toBe('GET');
		expect(opts.data).toEqual({ foo: 'bar' });
		expect(opts.contentType).toBeUndefined();

		// Verify beforeSend installs X-WP-Nonce header.
		const xhr = { setRequestHeader: vi.fn() };
		opts.beforeSend(xhr);
		expect(xhr.setRequestHeader).toHaveBeenCalledWith('X-WP-Nonce', 'rest-n');

		expect(result).toEqual({ items: [1, 2] });
	});

	it('encodes JSON body for write methods (POST/PUT/PATCH/DELETE)', async () => {
		const ajaxSpy = mockAjax(ajaxResolver({ ok: true }));

		await window.FFC.rest('/wp-json/ffc/v1/user/profile', {
			method: 'PUT',
			data: { first_name: 'Alex' },
			nonce: 'rest-n',
		});

		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.method).toBe('PUT');
		expect(opts.contentType).toBe('application/json');
		expect(opts.data).toBe(JSON.stringify({ first_name: 'Alex' }));
	});

	it('uppercases the method', async () => {
		mockAjax(ajaxResolver({}));

		await window.FFC.rest('/x', { method: 'post', data: { a: 1 } });

		const opts = window.$.ajax.mock.calls[0][0];
		expect(opts.method).toBe('POST');
		expect(opts.contentType).toBe('application/json');
	});

	it('omits body entirely when data is undefined or null', async () => {
		mockAjax(ajaxResolver({}));

		await window.FFC.rest('/x', { method: 'DELETE', nonce: 'n' });

		const opts = window.$.ajax.mock.calls[0][0];
		expect(opts.data).toBeUndefined();
		expect(opts.contentType).toBeUndefined();
	});

	it('omits X-WP-Nonce header when no nonce resolves', async () => {
		mockAjax(ajaxResolver({}));

		await window.FFC.rest('/x');

		const xhr = { setRequestHeader: vi.fn() };
		window.$.ajax.mock.calls[0][0].beforeSend(xhr);
		expect(xhr.setRequestHeader).not.toHaveBeenCalled();
	});

	it('falls back to FFC.config.restNonce when options.nonce is absent', async () => {
		window.FFC.config.restNonce = 'config-rest-n';
		mockAjax(ajaxResolver({}));

		await window.FFC.rest('/x');

		const xhr = { setRequestHeader: vi.fn() };
		window.$.ajax.mock.calls[0][0].beforeSend(xhr);
		expect(xhr.setRequestHeader).toHaveBeenCalledWith('X-WP-Nonce', 'config-rest-n');
	});

	it('forwards a numeric options.timeout onto the $.ajax options', async () => {
		const ajaxSpy = mockAjax(ajaxResolver({}));

		await window.FFC.rest('/x', { method: 'GET', timeout: 5000 });

		expect(ajaxSpy.mock.calls[0][0].timeout).toBe(5000);
	});

	it('options.nonce wins over config.restNonce', async () => {
		window.FFC.config.restNonce = 'config-rest-n';
		mockAjax(ajaxResolver({}));

		await window.FFC.rest('/x', { nonce: 'override' });

		const xhr = { setRequestHeader: vi.fn() };
		window.$.ajax.mock.calls[0][0].beforeSend(xhr);
		expect(xhr.setRequestHeader).toHaveBeenCalledWith('X-WP-Nonce', 'override');
	});
});

describe('FFC.rest — error handling', () => {
	it('rejects with WP_REST_Response.message when server returns an error body', async () => {
		mockAjax(ajaxResolver({ responseJSON: { message: 'Invalid request' } }, true));

		await expect(window.FFC.rest('/x')).rejects.toThrow('Invalid request');
	});

	it('falls back to the localised connection error when no message is present', async () => {
		mockAjax(ajaxResolver({}, true));

		await expect(window.FFC.rest('/x')).rejects.toThrow('Lost connection');
	});

	it('attaches the xhr to the rejected error for caller introspection', async () => {
		const fakeXhr = { responseJSON: { message: 'forbidden' }, status: 403 };
		mockAjax(ajaxResolver(fakeXhr, true));

		try {
			await window.FFC.rest('/x');
			throw new Error('should have rejected');
		} catch (err) {
			expect(err.message).toBe('forbidden');
			expect(err.xhr).toBe(fakeXhr);
		}
	});
});
