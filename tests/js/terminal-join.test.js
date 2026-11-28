/**
 * The terminal's on-site sign-up code (#2968): the bytes it signs, the key it
 * keeps, the signature the site checks and the QR it draws.
 */
import { describe, it, expect, beforeAll } from 'vitest';
import { webcrypto } from 'node:crypto';
import { loadScript } from './helpers.js';
import { memoryIndexedDB } from './memory-indexeddb.js';

const J = () => window.FFCTerminalJoin;
const subtle = webcrypto.subtle;

beforeAll(() => {
	loadScript('libs/js/qrcodegen-1.8.0.js');
	loadScript('assets/js/ffc-terminal-join.js');
});

const fromB64url = (text) => new Uint8Array(Buffer.from(text.replace(/-/g, '+').replace(/_/g, '/'), 'base64'));

describe('sign-up code', () => {
	it('names an audience booking by its bare id and a form by type:id', () => {
		expect(J().target(11)).toEqual({ type: 1, id: 11 });
		expect(J().target('11')).toEqual({ type: 1, id: 11 });
		expect(J().target('certificate_form:12')).toEqual({ type: 2, id: 12 });
		expect(J().target('unknown:3')).toBeNull();
		expect(J().target('x')).toBeNull();
	});

	it('lays the 14 signed bytes out as the site reads them', () => {
		const body = J().body(7, 2, 12, 1800000000);
		expect(Array.from(body)).toEqual([1, 0, 0, 0, 7, 2, 0, 0, 0, 12, 0x6b, 0x49, 0xd2, 0x00]);
		const message = J().message(body);
		expect(new TextDecoder().decode(message.slice(0, 18))).toBe('FFC-TERMINAL-JOIN\u0000');
		expect(Array.from(message.slice(18))).toEqual(Array.from(body));
	});

	it('signs a code the public key verifies, and refuses a key it cannot name', async () => {
		const pair = await subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify']);
		const code = await J().code(subtle, pair.privateKey, 7, 'certificate_form:12', 1800000000);
		const raw = fromB64url(code);
		expect(raw.length).toBe(78);
		expect(code).toMatch(/^[A-Za-z0-9_-]+$/);
		const valid = await subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, pair.publicKey, raw.slice(14), J().message(raw.slice(0, 14)));
		expect(valid).toBe(true);
		expect(await J().code(subtle, pair.privateKey, 7, 'unknown:1', 1800000000)).toBeNull();
		expect(await J().code(subtle, pair.privateKey, 0, 11, 1800000000)).toBeNull();
	});

	it('makes the key once, keeps it, and fingerprints it as the site does', async () => {
		const idb = memoryIndexedDB();
		const first = await J().key(idb, subtle);
		const again = await J().key(idb, subtle);
		expect(first.raw.length).toBe(65);
		expect(first.raw[0]).toBe(4);
		expect(Array.from(again.raw)).toEqual(Array.from(first.raw));
		expect(first.privateKey.extractable).toBe(false);
		const digest = new Uint8Array(await subtle.digest('SHA-256', first.raw));
		const hex = Array.from(digest).map((b) => b.toString(16).padStart(2, '0')).join('').slice(0, 16);
		expect(await J().fingerprint(subtle, first.raw)).toBe(hex);
	});

	it('builds the address and draws its QR', () => {
		expect(J().url('https://example.com/', 'abc')).toBe('https://example.com/?ffc_join=abc');
		expect(J().url('https://example.com/?lang=pt', 'abc')).toBe('https://example.com/?lang=pt&ffc_join=abc');
		const svg = J().qrSvg(window.qrcodegen, J().url('https://example.com/', 'a'.repeat(104)));
		expect(svg).toMatch(/^<svg [^>]*viewBox="0 0 \d+ \d+"/);
		expect(svg).toContain('<path d="M');
		expect(J().qrSvg(null, 'x')).toBe('');
	});
});
