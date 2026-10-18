// "My Calls" (#1889): the client-side pager over the server's rows, and the
// info popovers.
//
// The fixtures mirror what `RecruitmentDashboardSection` prints: a section
// whose table holds group label rows and data rows, followed by an empty
// `nav.ffc-mycalls-pager` carrying the page size and the strings.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-recruitment-my-calls.js');
});

const pagerAttrs = (perPage) =>
	`data-per-page="${perPage}" data-label-previous="Previous page" data-label-next="Next page" ` +
	'data-label-page="Page %d" data-label-range="Showing %1$s–%2$s of %3$s"';

function waiting(groups) {
	let body = '';
	Object.keys(groups).forEach((group) => {
		body += `<tr class="ffc-mycalls-group-row" data-group="${group}"><td colspan="5">${group}` +
			'<span class="ffc-mycalls-group-continued"> (continued)</span></td></tr>';
		for (let i = 1; i <= groups[group]; i++) {
			body += `<tr class="ffc-mycalls-row" data-group="${group}"><td>${group}-${i}</td></tr>`;
		}
	});
	return `<section class="ffc-mycalls-section"><table><tbody>${body}</tbody></table>` +
		`<nav class="ffc-mycalls-pager" aria-label="Pages: Waiting" ${pagerAttrs(5)}></nav></section>`;
}

function closed(count) {
	let body = '';
	for (let i = 1; i <= count; i++) {
		body += `<tr class="ffc-mycalls-row"><td>row-${i}</td></tr>`;
	}
	return `<section class="ffc-mycalls-section"><table><tbody>${body}</tbody></table>` +
		`<nav class="ffc-mycalls-pager" aria-label="Pages: Closed" ${pagerAttrs(2)}></nav></section>`;
}

const visibleRows = (section) =>
	Array.from(section.querySelectorAll('tr.ffc-mycalls-row')).filter((r) => !r.hidden).map((r) => r.textContent);

const visibleGroups = (section) =>
	Array.from(section.querySelectorAll('tr.ffc-mycalls-group-row')).filter((r) => !r.hidden)
		.map((r) => r.getAttribute('data-group') + (r.classList.contains('is-continued') ? ' (continued)' : ''));

const pageButton = (section, label) => section.querySelector(`.ffc-mycalls-page-btn[aria-label="${label}"]`);

describe('pager', () => {
	it('pages Waiting by 5 and repeats a group label with "(continued)" on the next page', () => {
		document.body.innerHTML = waiting({ final: 3, preliminary: 4 });
		window.FFCMyCalls.init();
		const section = document.querySelector('.ffc-mycalls-section');

		expect(visibleRows(section)).toEqual(['final-1', 'final-2', 'final-3', 'preliminary-1', 'preliminary-2']);
		expect(visibleGroups(section)).toEqual(['final', 'preliminary']);
		expect(section.querySelector('.ffc-mycalls-pager-range').textContent).toBe('Showing 1–5 of 7');

		pageButton(section, 'Page 2').click();

		expect(visibleRows(section)).toEqual(['preliminary-3', 'preliminary-4']);
		expect(visibleGroups(section)).toEqual(['preliminary (continued)']);
		expect(section.querySelector('.ffc-mycalls-pager-range').textContent).toBe('Showing 6–7 of 7');

		pageButton(section, 'Previous page').click();
		expect(visibleGroups(section)).toEqual(['final', 'preliminary']);
	});

	it('does not mark a group continued when it starts at the top of a page', () => {
		document.body.innerHTML = waiting({ final: 5, preliminary: 2 });
		window.FFCMyCalls.init();
		const section = document.querySelector('.ffc-mycalls-section');

		pageButton(section, 'Page 2').click();
		expect(visibleGroups(section)).toEqual(['preliminary']);
	});

	it('pages Closed by 2, marks the current page with aria-current and disables the ends', () => {
		document.body.innerHTML = closed(5);
		window.FFCMyCalls.init();
		const section = document.querySelector('.ffc-mycalls-section');

		expect(visibleRows(section)).toEqual(['row-1', 'row-2']);
		const pages = section.querySelectorAll('.ffc-mycalls-page-btn:not(.ffc-btn-icon)');
		expect(Array.from(pages).map((b) => b.textContent)).toEqual(['1', '2', '3']);
		expect(pageButton(section, 'Page 1').getAttribute('aria-current')).toBe('page');
		expect(pageButton(section, 'Page 1').className).not.toContain('ffc-btn-primary');
		expect(pageButton(section, 'Previous page').disabled).toBe(true);
		expect(pageButton(section, 'Previous page').className).toContain('ffc-icon-chevron-left');
		expect(pageButton(section, 'Next page').className).toContain('ffc-icon-chevron-right');

		pageButton(section, 'Next page').click();
		expect(visibleRows(section)).toEqual(['row-3', 'row-4']);
		expect(pageButton(section, 'Page 2').getAttribute('aria-current')).toBe('page');
		expect(pageButton(section, 'Page 1').hasAttribute('aria-current')).toBe(false);
		expect(document.activeElement).toBe(pageButton(section, 'Page 2'));

		pageButton(section, 'Page 3').click();
		expect(visibleRows(section)).toEqual(['row-5']);
		expect(pageButton(section, 'Next page').disabled).toBe(true);

		// A disabled key does nothing.
		pageButton(section, 'Next page').click();
		expect(visibleRows(section)).toEqual(['row-5']);
	});

	it('draws no pager and hides no row when one page holds them all', () => {
		document.body.innerHTML = closed(2);
		window.FFCMyCalls.init();
		const section = document.querySelector('.ffc-mycalls-section');

		expect(visibleRows(section)).toEqual(['row-1', 'row-2']);
		expect(section.querySelector('.ffc-mycalls-pager').innerHTML).toBe('');
		expect(window.FFCMyCalls.initPager(section.querySelector('.ffc-mycalls-pager'))).toBe(false);
	});

	it('ignores a pager without a table or a page size', () => {
		document.body.innerHTML = '<nav class="ffc-mycalls-pager" data-per-page="2"></nav>' +
			'<section class="ffc-mycalls-section"><table><tbody></tbody></table><nav class="ffc-mycalls-pager"></nav></section>';
		const pagers = document.querySelectorAll('.ffc-mycalls-pager');

		expect(window.FFCMyCalls.initPager(pagers[0])).toBe(false);
		expect(window.FFCMyCalls.initPager(pagers[1])).toBe(false);
	});

	it('falls back to English strings when the attributes are missing', () => {
		document.body.innerHTML = '<section class="ffc-mycalls-section"><table><tbody>' +
			'<tr class="ffc-mycalls-row"><td>a</td></tr><tr class="ffc-mycalls-row"><td>b</td></tr>' +
			'<tr class="ffc-mycalls-row"><td>c</td></tr></tbody></table>' +
			'<nav class="ffc-mycalls-pager" data-per-page="2"></nav></section>';
		window.FFCMyCalls.init();
		const section = document.querySelector('.ffc-mycalls-section');

		expect(section.querySelector('.ffc-mycalls-pager-range').textContent).toBe('1–2 of 3');
		expect(pageButton(section, 'Page 2')).not.toBeNull();
		expect(pageButton(section, 'Next page')).not.toBeNull();
	});
});

describe('info popover', () => {
	function info(id) {
		return '<div class="ffc-mycalls-info"><button type="button" class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-info ffc-mycalls-info-btn" ' +
			`aria-label="About the reverted call" aria-expanded="false" aria-controls="${id}"></button>` +
			`<div class="ffc-mycalls-pop" id="${id}" hidden><strong>Previous call reverted</strong></div></div>`;
	}

	beforeEach(() => {
		document.body.innerHTML = info('pop-1') + info('pop-2') + '<p class="outside">elsewhere</p>';
		window.FFCMyCalls.init();
	});

	const button = (id) => document.querySelector(`[aria-controls="${id}"]`);
	const pop = (id) => document.getElementById(id);

	it('opens on click, setting aria-expanded and showing the popover', () => {
		expect(button('pop-1').getAttribute('aria-label')).toBe('About the reverted call');

		button('pop-1').click();

		expect(button('pop-1').getAttribute('aria-expanded')).toBe('true');
		expect(pop('pop-1').hidden).toBe(false);
		expect(button('pop-1').closest('.ffc-mycalls-info').classList.contains('is-open')).toBe(true);

		button('pop-1').click();
		expect(button('pop-1').getAttribute('aria-expanded')).toBe('false');
		expect(pop('pop-1').hidden).toBe(true);
	});

	it('keeps one popover open at a time', () => {
		button('pop-1').click();
		button('pop-2').click();

		expect(pop('pop-1').hidden).toBe(true);
		expect(button('pop-1').getAttribute('aria-expanded')).toBe('false');
		expect(pop('pop-2').hidden).toBe(false);
	});

	it('closes on Escape and gives focus back to its button', () => {
		button('pop-1').click();

		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

		expect(pop('pop-1').hidden).toBe(true);
		expect(button('pop-1').getAttribute('aria-expanded')).toBe('false');
		expect(document.activeElement).toBe(button('pop-1'));
	});

	it('ignores other keys', () => {
		button('pop-1').click();
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
		expect(pop('pop-1').hidden).toBe(false);
	});

	it('closes on a click outside, and stays open on a click inside it', () => {
		button('pop-1').click();

		pop('pop-1').querySelector('strong').click();
		expect(pop('pop-1').hidden).toBe(false);

		document.querySelector('.outside').click();
		expect(pop('pop-1').hidden).toBe(true);
		expect(button('pop-1').getAttribute('aria-expanded')).toBe('false');
	});

	it('survives a button whose popover is missing', () => {
		document.body.innerHTML = '<button type="button" class="ffc-mycalls-info-btn" aria-expanded="false"></button>';
		const btn = document.querySelector('.ffc-mycalls-info-btn');

		btn.click();
		expect(btn.getAttribute('aria-expanded')).toBe('true');
		window.FFCMyCalls.closeAll(null);
		expect(btn.getAttribute('aria-expanded')).toBe('false');
	});
});
