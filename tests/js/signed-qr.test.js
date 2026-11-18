// Tests for assets/js/ffc-signed-qr.js — the browser half of the signed QR
// code (#2639): it must accept exactly what PHP signed, refuse anything else,
// and say which of the two it saw.
import { describe, it, expect, beforeAll } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { webcrypto } from 'node:crypto';
import { loadScript } from './helpers.js';

// The vector PHP signed, with a fixed seed; SignedQrVectorTest checks that
// PHP still produces these exact bytes.
const vector = JSON.parse(readFileSync(resolve(process.cwd(), 'tests/fixtures/signed-qr-vector.json'), 'utf8'));
const keys = { k1: vector.public };

const strings = {
	valid: 'Signed by the issuer',
	validText: 'valid text',
	superseded: 'Changed after issue',
	supersededText: 'superseded text',
	invalid: 'Signature not valid',
	invalidText: 'invalid text',
	unknownKeyText: 'unknown key text',
	unsupported: 'Signature not checked',
	unsupportedText: 'unsupported text',
	code: 'Validation code:',
	issued: 'Issued on:',
	name: 'Name:',
	course: 'Course:',
	workload: 'Workload (hours):',
};

beforeAll(() => {
	// jsdom has no SubtleCrypto; Node's is the same WebCrypto a browser has.
	if (!window.crypto || !window.crypto.subtle) {
		Object.defineProperty(window, 'crypto', { value: webcrypto, configurable: true });
	}
	loadScript('assets/js/ffc-signed-qr.js');
});

/**
 * The same signed value with one byte of its payload changed.
 */
function tampered(signed) {
	const [payload, signature] = signed.split('.');
	const text = Buffer.from(payload, 'base64url').toString('utf8').replace('A1B2', 'A1B3');
	return Buffer.from(text, 'utf8').toString('base64url') + '.' + signature;
}

describe('FFCSignedQr.verify', () => {
	it('accepts the full payload PHP signed, accents and escaped separators included', async () => {
		const result = await window.FFCSignedQr.verify(vector.full.s, keys);

		expect(result.state).toBe('valid');
		expect(result.fields).toMatchObject({
			mode: 'full',
			key: 'k1',
			code: 'A1B2C3D4E5F6',
			date: '2026-11-17',
			name: 'José | Maria\\ Ação',
			course: 'Formação em Libras – Módulo I',
			hours: '40',
			hash: vector.hash,
		});
	});

	it('accepts the compact payload', async () => {
		const result = await window.FFCSignedQr.verify(vector.compact.s, keys);

		expect(result.state).toBe('valid');
		expect(result.fields).toMatchObject({ mode: 'compact', code: 'A1B2C3D4E5F6', hash: vector.hash });
	});

	it('refuses a payload changed after signing', async () => {
		const result = await window.FFCSignedQr.verify(tampered(vector.full.s), keys);

		expect(result.state).toBe('invalid');
	});

	it('refuses a signature made by another key', async () => {
		const other = { k1: Buffer.alloc(32, 9).toString('base64url') };

		expect((await window.FFCSignedQr.verify(vector.full.s, other)).state).toBe('invalid');
	});

	it('names a key the site does not have as unknown', async () => {
		expect((await window.FFCSignedQr.verify(vector.full.s, { k2: vector.public })).state).toBe('unknown_key');
	});

	it.each([
		['no dot', 'abc'],
		['not base64url', '!!!.???'],
		['a short signature', vector.full.s.split('.')[0] + '.AAAA'],
		['an unknown version', Buffer.from('v9|k1|x|y|z').toString('base64url') + '.' + vector.full.s.split('.')[1]],
	])('reads %s as malformed', async (_label, signed) => {
		expect((await window.FFCSignedQr.verify(signed, keys)).state).toBe('malformed');
	});
});

describe('FFCSignedQr.signedFrom', () => {
	it('reads the signed value from a whole link', () => {
		const link = 'https://example.test/valid/#token=abc&s=' + vector.compact.s;

		expect(window.FFCSignedQr.signedFrom(link)).toBe(vector.compact.s);
	});

	it('reads it from a fragment, and returns empty for a link with none', () => {
		expect(window.FFCSignedQr.signedFrom('#token=abc&s=xyz.def')).toBe('xyz.def');
		expect(window.FFCSignedQr.signedFrom('https://example.test/valid/#token=abc')).toBe('');
	});
});

describe('FFCSignedQr.split', () => {
	it('undoes the escape of the separator and the backslash', () => {
		expect(window.FFCSignedQr.split('a\\|b|c\\\\|d')).toEqual(['a|b', 'c\\', 'd']);
	});
});

describe('FFCSignedQr.describe', () => {
	it('lists what was signed', async () => {
		const result = await window.FFCSignedQr.verify(vector.full.s, keys);
		const d = window.FFCSignedQr.describe(result, strings, vector.hash);

		expect(d.tone).toBe('success');
		expect(d.title).toBe('Signed by the issuer');
		expect(d.lines).toContain('Name: José | Maria\\ Ação');
		expect(d.lines).toContain('Workload (hours): 40');
	});

	it('warns when the site now holds a different record', async () => {
		const result = await window.FFCSignedQr.verify(vector.compact.s, keys);
		const d = window.FFCSignedQr.describe(result, strings, 'f'.repeat(64));

		expect(d.tone).toBe('warning');
		expect(d.title).toBe('Changed after issue');
	});

	it('says plainly when nothing was checked', () => {
		const d = window.FFCSignedQr.describe({ state: 'unsupported', fields: null }, strings, '');

		expect(d.tone).toBe('info');
		expect(d.lines).toEqual(['unsupported text']);
	});
});
