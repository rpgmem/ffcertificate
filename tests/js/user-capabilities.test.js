// Tests for assets/js/ffc-user-capabilities.js — the grouped per-user
// capability manager interactions: grant/revoke-all presets, live search
// across label + slug, collapsible group cards, copy-slug, and the live
// per-group "granted" counter.
//
// The script binds to elements found under .ffc-cap-panel at init() time,
// so each test injects fresh markup and calls window.ffcUserPermissionsInit().

import { describe, it, expect, beforeEach, afterEach, beforeAll, vi } from 'vitest';
import { loadScript } from './helpers.js';

function panelMarkup() {
	return `
	<div class="ffc-cap-panel">
		<div class="ffc-cap-toolbar">
			<input type="search" class="ffc-cap-search">
			<button type="button" data-ffc-preset="all">all</button>
			<button type="button" data-ffc-preset="none">none</button>
		</div>
		<section class="ffc-cap-group" data-ffc-group="certificate">
			<button type="button" class="ffc-cap-group-h" aria-expanded="true">
				<span class="ffc-cap-count">1</span>/2
			</button>
			<div class="ffc-cap-group-body">
				<div class="ffc-cap-row" data-ffc-cap-name="view own certificates ffc_view_own_certificates" data-ffc-cap-slug="ffc_view_own_certificates">
					<input type="checkbox" class="ffc-cap-checkbox" checked>
					<button type="button" class="ffc-cap-copy" data-ffc-copy="ffc_view_own_certificates">C</button>
				</div>
				<div class="ffc-cap-row" data-ffc-cap-name="download own certificates ffc_download_own_certificates" data-ffc-cap-slug="ffc_download_own_certificates">
					<input type="checkbox" class="ffc-cap-checkbox">
				</div>
			</div>
		</section>
		<section class="ffc-cap-group is-collapsed" data-ffc-group="admin_recruitment">
			<button type="button" class="ffc-cap-group-h" aria-expanded="false">
				<span class="ffc-cap-count">0</span>/1
			</button>
			<div class="ffc-cap-group-body">
				<div class="ffc-cap-row" data-ffc-cap-name="view sensitive data (pii) ffc_view_recruitment_pii" data-ffc-cap-slug="ffc_view_recruitment_pii">
					<input type="checkbox" class="ffc-cap-checkbox">
				</div>
			</div>
		</section>
	</div>`;
}

function setup() {
	document.body.innerHTML = panelMarkup();
	window.ffcUserPermissionsInit();
}

function counts() {
	return [...document.querySelectorAll('.ffc-cap-count')].map((c) => c.textContent);
}

beforeAll(() => {
	loadScript('assets/js/ffc-user-capabilities.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('presets', () => {
	it('"Grant all" checks every box and refreshes the per-group counts', () => {
		setup();
		document.querySelector('[data-ffc-preset="all"]').click();

		const boxes = [...document.querySelectorAll('.ffc-cap-checkbox')];
		expect(boxes.every((b) => b.checked)).toBe(true);
		expect(counts()).toEqual(['2', '1']);
	});

	it('"Revoke all" unchecks every box and zeroes the counts', () => {
		setup();
		document.querySelector('[data-ffc-preset="none"]').click();

		const boxes = [...document.querySelectorAll('.ffc-cap-checkbox')];
		expect(boxes.some((b) => b.checked)).toBe(false);
		expect(counts()).toEqual(['0', '0']);
	});
});

describe('search', () => {
	it('filters rows by slug, hides non-matching groups, and expands matches', () => {
		setup();
		const search = document.querySelector('.ffc-cap-search');
		search.value = 'pii';
		search.dispatchEvent(new Event('input'));

		const certGroup = document.querySelector('[data-ffc-group="certificate"]');
		const admGroup = document.querySelector('[data-ffc-group="admin_recruitment"]');

		expect(certGroup.hidden).toBe(true);
		expect(admGroup.hidden).toBe(false);
		// The matching admin card auto-expands so the hit isn't hidden.
		expect(admGroup.classList.contains('is-collapsed')).toBe(false);
		expect(document.querySelector('[data-ffc-cap-slug="ffc_view_recruitment_pii"]').hidden).toBe(false);
	});

	it('clearing the query restores all rows and groups', () => {
		setup();
		const search = document.querySelector('.ffc-cap-search');
		search.value = 'pii';
		search.dispatchEvent(new Event('input'));
		search.value = '';
		search.dispatchEvent(new Event('input'));

		expect(document.querySelector('[data-ffc-group="certificate"]').hidden).toBe(false);
		expect([...document.querySelectorAll('.ffc-cap-row')].every((r) => !r.hidden)).toBe(true);
	});
});

describe('collapse', () => {
	it('toggles is-collapsed and aria-expanded when the header is clicked', () => {
		setup();
		const group = document.querySelector('[data-ffc-group="certificate"]');
		const header = group.querySelector('.ffc-cap-group-h');

		header.click();
		expect(group.classList.contains('is-collapsed')).toBe(true);
		expect(header.getAttribute('aria-expanded')).toBe('false');

		header.click();
		expect(group.classList.contains('is-collapsed')).toBe(false);
		expect(header.getAttribute('aria-expanded')).toBe('true');
	});
});

describe('copy slug', () => {
	it('writes the slug to the clipboard and shows a confirmation glyph', () => {
		setup();
		const writeText = vi.fn();
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

		const btn = document.querySelector('.ffc-cap-copy');
		btn.click();

		expect(writeText).toHaveBeenCalledWith('ffc_view_own_certificates');
		expect(btn.textContent).toBe('✓');
	});
});

describe('live count', () => {
	it('updates a group count when one of its checkboxes changes', () => {
		setup();
		const admBox = document.querySelector('[data-ffc-group="admin_recruitment"] .ffc-cap-checkbox');
		admBox.checked = true;
		admBox.dispatchEvent(new Event('change'));

		const admCount = document.querySelector('[data-ffc-group="admin_recruitment"] .ffc-cap-count');
		expect(admCount.textContent).toBe('1');
	});
});
