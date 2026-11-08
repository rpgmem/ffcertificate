// FFC.request on an HTTP error (#2341): a 429 or 403 that carries the
// plugin's own message reaches the caller as the server's message; a
// failure with no such body stays a connection error.
import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	delete window.FFC;
	window.ffc_ajax = { nonce: 'n', ajax_url: '/x', strings: {} };
	loadScript('assets/js/ffc-core.js');
});

function failWith(xhr) {
	const original = window.$.post;
	window.$.post = function () {
		const chain = {
			done() { return chain; },
			fail(cb) { cb(xhr); return chain; },
		};
		return chain;
	};
	return () => { window.$.post = original; };
}

async function rejection() {
	try {
		await window.FFC.request('ffc_public_csv_info', 'form_id=1');
	} catch (err) {
		return err;
	}
	throw new Error('should have rejected');
}

describe('FFC.request — HTTP errors', () => {
	it('uses the message of a 429 body and marks it as the server speaking', async () => {
		const restore = failWith({ status: 429, responseJSON: { success: false, data: { message: 'Too many requests. Please wait 1 minute.' } } });
		const err = await rejection();
		expect(err.fromServer).toBe(true);
		expect(err.message).toBe('Too many requests. Please wait 1 minute.');
		expect(err.data.message).toBe('Too many requests. Please wait 1 minute.');
		restore();
	});

	it('accepts a bare string as the error data', async () => {
		const restore = failWith({ status: 403, responseJSON: { success: false, data: 'Invalid access hash.' } });
		const err = await rejection();
		expect(err.fromServer).toBe(true);
		expect(err.message).toBe('Invalid access hash.');
		restore();
	});

	it('stays a connection error when the body carries no message', async () => {
		const restore = failWith({ status: 502, responseJSON: undefined });
		const err = await rejection();
		expect(err.fromServer).toBe(false);
		expect(err.message).toBe('Connection error');
		restore();
	});

	it('stays a connection error when the data has an empty message', async () => {
		const restore = failWith({ status: 500, responseJSON: { success: false, data: { message: '' } } });
		const err = await rejection();
		expect(err.fromServer).toBe(false);
		restore();
	});
});
