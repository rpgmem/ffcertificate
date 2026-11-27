/**
 * Certificates screen of the installable app (#2781): the featured newest
 * certificate, the compact rows, the search, and sharing.
 */
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const NOW = 1800000000;
const DAY = 86400;

const STRINGS = {
	loading: 'loading',
	failed: 'failed',
	empty: 'empty',
	noMatch: 'no match',
	new: 'New',
	valid: 'Valid',
	issued: 'Issued %s',
	code: 'Code %s',
	open: 'Open PDF',
	noAccess: 'no access',
};

const CERTS = [
	{ id: 3, form_title: 'Inclusive Education', submission_date: '19/11/2026', submission_date_raw: NOW - DAY, auth_code: 'A-FDVS-UJA0-BAMQ', pdf_url: 'https://example.org/v/3' },
	{ id: 2, form_title: 'Literacy Seminar', submission_date: '03/10/2026', submission_date_raw: NOW - 50 * DAY, auth_code: 'B-1111-2222-3333', pdf_url: 'https://example.org/v/2' },
	{ id: 1, form_title: 'Coordinators Meeting', submission_date: '12/08/2026', submission_date_raw: NOW - 100 * DAY, auth_code: '', pdf_url: '' },
];

beforeAll(() => {
	loadScript('assets/js/ffc-app-certificates.js');
});

function markup() {
	document.body.innerHTML = `
		<div data-ffc-app-certificates>
			<input type="search" data-ffc-app-part="search">
			<p data-ffc-app-part="status"></p>
			<div data-ffc-app-part="featured"></div>
			<ul data-ffc-app-part="list"></ul>
		</div>`;
	return document.querySelector('[data-ffc-app-certificates]');
}

const reply = (status, body) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
const settle = async () => { for (let i = 0; i < 10; i++) { await new Promise((r) => setTimeout(r, 0)); } };

describe('app certificates screen', () => {
	beforeEach(() => {
		window.ffcAppCertificates = { rest: 'https://example.org/wp-json/ffc/v1/user/certificates', nonce: 'n', newDays: 7, strings: STRINGS };
	});

	it('features the newest, lists the rest with a chevron, and marks a recent one as new', async () => {
		const el = markup();
		const fetch = vi.fn(() => reply(200, { certificates: CERTS }));
		window.FFCAppCertificates.boot({ fetch, now: () => NOW * 1000 });
		await settle();
		expect(fetch.mock.calls[0][0]).toBe(window.ffcAppCertificates.rest);
		expect(fetch.mock.calls[0][1].headers['X-WP-Nonce']).toBe('n');
		const card = el.querySelector('.ffc-app-certificate-card');
		expect(card.querySelector('h2').textContent).toBe('Inclusive Education');
		expect(card.querySelector('.ffc-app-certificate-badge').textContent).toBe('New');
		expect(card.querySelector('.ffc-app-certificate-status-valid').textContent).toBe('Valid');
		expect(card.querySelector('.ffc-app-certificate-code').textContent).toBe('Code A-FDVS-UJA0-BAMQ');
		expect(card.querySelector('a.ffc-btn-primary').getAttribute('href')).toBe('https://example.org/v/3');
		const rows = el.querySelectorAll('.ffc-app-certificate-row');
		expect(rows).toHaveLength(2);
		expect(rows[0].querySelector('a').getAttribute('href')).toBe('https://example.org/v/2');
		expect(rows[0].querySelector('.ffc-icon-chevron-right')).not.toBeNull();
		expect(rows[1].querySelector('a')).toBeNull();
		expect(el.querySelector('.ffc-app-certificate-row .ffc-app-certificate-status'), 'the rows carry no badge: every one is valid').toBeNull();
		expect(el.querySelector('[data-ffc-app-part="status"]').textContent).toBe('');
	});

	it('filters by course or by code, and says when nothing matches', async () => {
		const el = markup();
		window.FFCAppCertificates.boot({ fetch: () => reply(200, { certificates: CERTS }), now: () => NOW * 1000 });
		await settle();
		const search = el.querySelector('[data-ffc-app-part="search"]');
		search.value = 'b1111';
		search.dispatchEvent(new Event('input'));
		expect(el.querySelector('.ffc-app-certificate-card h2').textContent).toBe('Literacy Seminar');
		expect(el.querySelector('.ffc-app-certificate-badge')).toBeNull();
		search.value = 'coordinators';
		search.dispatchEvent(new Event('input'));
		expect(el.querySelector('.ffc-app-certificate-card h2').textContent).toBe('Coordinators Meeting');
		expect(el.querySelector('.ffc-app-certificate-note').textContent).toBe('no access');
		search.value = 'nothing here';
		search.dispatchEvent(new Event('input'));
		expect(el.querySelector('.ffc-app-certificate-card')).toBeNull();
		expect(el.querySelector('[data-ffc-app-part="status"]').textContent).toBe('no match');
	});

	it('offers the PDF and no way to share its address', async () => {
		const el = markup();
		window.FFCAppCertificates.boot({ fetch: () => reply(200, { certificates: CERTS }), now: () => NOW * 1000 });
		await settle();
		expect(el.querySelector('.ffc-app-certificate-card a.ffc-icon-download')).not.toBeNull();
		expect(el.querySelector('.ffc-icon-share'), 'the certificate travels as the file').toBeNull();
	});

	it('opens a certificate inside the app when the verification page opens there', async () => {
		window.ffcAppCertificates.verify = { page: 'https://example.org/v/', app: 'https://example.org/app/?p=9' };
		const certs = [{ ...CERTS[0], pdf_url: 'https://example.org/v/#token=abc' }, { ...CERTS[1], pdf_url: 'https://example.org/v/#token=def' }];
		const el = markup();
		window.FFCAppCertificates.boot({ fetch: () => reply(200, { certificates: certs }), now: () => NOW * 1000 });
		await settle();
		const open = el.querySelector('.ffc-app-certificate-card a.ffc-btn-primary');
		expect(open.getAttribute('href')).toBe('https://example.org/app/?p=9#token=abc');
		expect(open.getAttribute('target'), 'the app keeps its frame').toBeNull();
		expect(el.querySelector('.ffc-app-certificate-row a').getAttribute('href')).toBe('https://example.org/app/?p=9#token=def');
	});

	it('keeps the site address when the app cannot draw the verification page', async () => {
		window.ffcAppCertificates.verify = { page: 'https://example.org/v/', app: 'https://example.org/v/' };
		const el = markup();
		window.FFCAppCertificates.boot({ fetch: () => reply(200, { certificates: CERTS }), now: () => NOW * 1000 });
		await settle();
		const open = el.querySelector('.ffc-app-certificate-card a.ffc-btn-primary');
		expect(open.getAttribute('href')).toBe('https://example.org/v/3');
		expect(open.getAttribute('target')).toBe('_blank');
	});

	it('says when there is nothing, and when the site does not answer', async () => {
		let el = markup();
		window.FFCAppCertificates.boot({ fetch: () => reply(200, { certificates: [] }), now: () => NOW * 1000 });
		await settle();
		expect(el.querySelector('[data-ffc-app-part="status"]').textContent).toBe('empty');

		el = markup();
		window.FFCAppCertificates.boot({ fetch: () => reply(500, null), now: () => NOW * 1000 });
		await settle();
		expect(el.querySelector('[data-ffc-app-part="status"]').textContent).toBe('failed');

		delete window.ffcAppCertificates;
		expect(window.FFCAppCertificates.boot()).toEqual([]);
	});
});
