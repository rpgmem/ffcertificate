/**
 * The terminal's presence verifier (#2745), against a code the app's own
 * script built under WebCrypto, the same fixture the PHP verifier is pinned
 * to in AudiencePresenceCodeTest.
 */
import { describe, it, expect, beforeAll } from 'vitest';
import { webcrypto } from 'node:crypto';
import { loadScript } from './helpers.js';

const NOW = 1800000000;
const SCRIPT_CODE = 'FFP1V505LJ*D0Y86000-*0K UICV$TUM.UGJF63682MR38N1VFGDZU3JC3.:M/QQV6O*SM$RSJBQ.S6E$M-/7*I9%6RQ4BRB5E7F%OKQNJGYPTQ7A3M%MMFPDUYONPD$SNA21GECMED+34Z%5QBILI2U%I.$8 HD0.33JMBXC%74SH7*25:%N1L3B 0FW2BH6QUTXR0RBW/94J5IY%OF*NS670C498QX6E+0RS180U1L1SPIE000.00FPDKOQ$AA*D0Z86000.00000700HPD/L42UR85CC/BHOL%-7RFQ2.PU3NO3V9KHNRR9/N/%1MMU*9K.4VOOBRGE8S3CE8JX4-EJ6TBD4QNVN 8MM-H$87 03J+HM*1* A7ULE1L: H8BB*J0+XVWTKBW1SYUKT1Q9BDP0NXLJ8EUGHPIG4-T5.AFDMK2KO:SH30P/BKL05%UJ+2N3KJ*PSBG5H0E7Q$OU';
const SITE_KEY = 'BKuK2S5iFEMntdf5GCk1h7vr1968vvmBmAoDm85wcCaI3fejfA5qHwJsS_H58PyVqncMWlOm0L92WTsKUjqGCjI';

beforeAll(() => {
	loadScript('assets/js/ffc-presence-verifier.js');
});

const V = () => window.FFCPresenceVerifier;
const options = (extra = {}) => ({ now: NOW, keys: { p1: SITE_KEY }, revoked: [], subtle: webcrypto.subtle, ...extra });

describe('presence verifier', () => {
	it('accepts the code the app script built', async () => {
		const read = await V().verify(SCRIPT_CODE, options());
		expect(read.ok).toBe(true);
		expect(read.userId).toBe(7);
		expect(read.name).toBe('Maria S.');
		expect(read.targetType).toBe('audience_booking');
		expect(read.targetId).toBe(42);
		expect(read.readAt).toBe(NOW - 10);
		expect(read.ticket).toBe(true);
		expect(read.keyId).toMatch(/^[A-Za-z0-9_-]{11}$/);
	});

	it('refuses a revoked phone, an old or future code and an unknown site key', async () => {
		const good = await V().verify(SCRIPT_CODE, options());
		expect((await V().verify(SCRIPT_CODE, options({ revoked: [good.keyId] }))).code).toBe('ffc_presence_code_revoked');
		expect((await V().verify(SCRIPT_CODE, options({ now: NOW + 200 }))).code).toBe('ffc_presence_code_stale');
		expect((await V().verify(SCRIPT_CODE, options({ now: NOW - 200 }))).code).toBe('ffc_presence_code_future');
		expect((await V().verify(SCRIPT_CODE, options({ keys: {} }))).code).toBe('ffc_presence_attestation_signature');
	});

	it('refuses a changed character and text that is not a code', async () => {
		const changed = SCRIPT_CODE.slice(0, 200) + ('A' === SCRIPT_CODE[200] ? 'B' : 'A') + SCRIPT_CODE.slice(201);
		expect((await V().verify(changed, options())).ok).toBe(false);
		expect((await V().verify('HELLO', options())).code).toBe('ffc_presence_code_malformed');
		expect((await V().verify('FFP1bb8', options())).code).toBe('ffc_presence_code_malformed');
		expect((await V().verify('FFP1BB8', options())).code).toBe('ffc_presence_code_malformed');
	});

	it('decodes Base45 as RFC 9285 does', () => {
		expect(Array.from(V().base45('BB8'))).toEqual([65, 66]);
		expect(new TextDecoder().decode(V().base45('%69 VD92EX0'))).toBe('Hello!!');
		expect(V().base45('GGW')).toBeNull();
		expect(V().base45('B')).toBeNull();
	});
});

describe('codes built here, with keys made for the test', () => {
	const subtle = webcrypto.subtle;
	const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
	const enc = new TextEncoder();
	let site;
	let device;
	let other;
	let sitePoint;
	let devicePoint;

	const b45 = (bytes) => {
		let out = '';
		for (let i = 0; i < bytes.length; i += 2) {
			if (i + 1 < bytes.length) {
				const n = bytes[i] * 256 + bytes[i + 1];
				out += ALPHABET[n % 45] + ALPHABET[Math.floor(n / 45) % 45] + ALPHABET[Math.floor(n / 2025)];
			} else {
				out += ALPHABET[bytes[i] % 45] + ALPHABET[Math.floor(bytes[i] / 45)];
			}
		}
		return out;
	};
	const b64url = (bytes) => Buffer.from(bytes).toString('base64url');
	const u32 = (n) => [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255];
	const cat = (...parts) => Uint8Array.from(parts.flatMap((p) => Array.from(p)));
	const sign = async (key, domain, body) => new Uint8Array(await subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, key, cat(enc.encode(domain), body)));
	const pair = () => subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, ['sign', 'verify']);

	beforeAll(async () => {
		site = await pair();
		device = await pair();
		other = await pair();
		sitePoint = new Uint8Array(await subtle.exportKey('raw', site.publicKey));
		devicePoint = new Uint8Array(await subtle.exportKey('raw', device.publicKey));
	});

	async function attestation({ expires = NOW + 600, name = 'Ana L.', kid = 'k1' } = {}) {
		const body = cat([1, kid.length], enc.encode(kid), u32(9), devicePoint, u32(NOW - 60), u32(expires), [enc.encode(name).length], enc.encode(name));
		return cat(body, await sign(site.privateKey, 'FFC-PRESENCE-ATTESTATION\0', body));
	}

	async function ticket({ target = 42, user = 9, expires = NOW + 600, kid = 'k1', key = site.privateKey } = {}) {
		const body = cat([1, kid.length], enc.encode(kid), [1], u32(target), u32(user), u32(expires));
		return cat(body, await sign(key, 'FFC-PRESENCE-TICKET\0', body));
	}

	async function code({ att, tick = new Uint8Array(0), target = 42, readAt = NOW, key = device.privateKey } = {}) {
		const a = att || (await attestation());
		const body = cat([1, a.length >> 8, a.length & 255], a, [1], u32(target), u32(readAt), [tick.length], tick);
		return 'FFP1' + b45(cat(body, await sign(key, 'FFC-PRESENCE-READING\0', body)));
	}

	const check = (text, extra = {}) => V().verify(text, { now: NOW, keys: { k1: b64url(sitePoint) }, revoked: [], subtle, ...extra });

	it('accepts a code without a ticket and one with a matching ticket', async () => {
		const plain = await check(await code());
		expect(plain.ok).toBe(true);
		expect(plain.ticket).toBe(false);
		expect(plain.name).toBe('Ana L.');
		const ticketed = await check(await code({ tick: await ticket() }));
		expect(ticketed.ok).toBe(true);
		expect(ticketed.ticket).toBe(true);
	});

	it('refuses an expired attestation and a reading signed by another phone', async () => {
		expect((await check(await code({ att: await attestation({ expires: NOW - 1 }) }))).code).toBe('ffc_presence_attestation_expired');
		expect((await check(await code({ key: other.privateKey }))).code).toBe('ffc_presence_code_signature');
		expect((await check(await code(), { keys: { k1: b64url(new Uint8Array(65)) } })).code).toBe('ffc_presence_attestation_signature');
	});

	it('refuses a ticket for someone else, an expired or forged one, and one under an unknown key', async () => {
		expect((await check(await code({ tick: await ticket({ user: 8 }) }))).code).toBe('ffc_presence_ticket_mismatch');
		expect((await check(await code({ tick: await ticket({ target: 41 }) }))).code).toBe('ffc_presence_ticket_mismatch');
		expect((await check(await code({ tick: await ticket({ expires: NOW - 1 }) }))).code).toBe('ffc_presence_ticket_expired');
		expect((await check(await code({ tick: await ticket({ key: other.privateKey }) }))).code).toBe('ffc_presence_ticket_signature');
		expect((await check(await code({ tick: await ticket({ kid: 'k9' }) }))).code).toBe('ffc_presence_ticket_signature');
		expect((await check(await code({ tick: new Uint8Array(5) }))).code).toBe('ffc_presence_ticket_malformed');
	});

	it('refuses a code whose attestation cannot be read', async () => {
		expect((await check(await code({ att: new Uint8Array([2, 1, 65]) }))).code).toBe('ffc_presence_attestation_malformed');
		expect((await check(await code({ target: 42, att: await attestation({ name: 'x'.repeat(49) }) }))).code).toBe('ffc_presence_attestation_malformed');
	});
});
