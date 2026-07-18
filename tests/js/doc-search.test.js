// Tests for ffc-doc-search.js — the in-page search/filter on the
// Documentation settings tab. Section cards (those with an anchored <h3 id>)
// and the Quick-Navigation links are shown/hidden as the user types.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-doc-search.js';

function buildDom({ withInput = true } = {}) {
	const input = withInput
		? '<input type="search" id="ffc-doc-search">'
		: '';
	document.body.innerHTML = `
		<div class="ffc-settings-wrap">
			<div class="card"><h2>Intro card, no anchor</h2></div>
			<div class="card ffc-doc-toc">
				<h3>Quick Navigation</h3>
				${input}
				<ul class="ffc-doc-toc-list">
					<li class="ffc-doc-toc-section">Reference</li>
					<li><a href="#shortcodes">Shortcodes</a></li>
					<li><a href="#emails">Emails and Delivery</a></li>
				</ul>
			</div>
			<div class="card" id="card-shortcodes"><h3 id="shortcodes">Shortcodes</h3><p>Add the ffc_form shortcode.</p></div>
			<div class="card" id="card-emails"><h3 id="emails">Emails and Delivery</h3><p>SMTP and multipart delivery.</p></div>
		</div>
	`;
}

beforeEach(() => {
	document.body.innerHTML = '';
});

afterEach(() => {
	document.body.innerHTML = '';
});

function type(value) {
	const input = document.getElementById('ffc-doc-search');
	input.value = value;
	input.dispatchEvent(new window.Event('input', { bubbles: true }));
}

describe('ffc-doc-search', () => {
	it('no-ops when the search input is absent', () => {
		buildDom({ withInput: false });
		expect(() => loadScript(SCRIPT)).not.toThrow();
	});

	it('hides section cards that do not match the query', () => {
		buildDom();
		loadScript(SCRIPT);
		type('smtp');

		expect(document.getElementById('card-emails').style.display).toBe('');
		expect(document.getElementById('card-shortcodes').style.display).toBe('none');
	});

	it('never hides the intro card or the Quick-Navigation card', () => {
		buildDom();
		loadScript(SCRIPT);
		type('zzz-no-match');

		const intro = document.querySelector('.card:not(.ffc-doc-toc) h2').closest('.card');
		const toc = document.querySelector('.ffc-doc-toc');
		expect(intro.style.display).toBe('');
		expect(toc.style.display).toBe('');
	});

	it('filters the Quick-Navigation links by text', () => {
		buildDom();
		loadScript(SCRIPT);
		type('emails');

		const items = document.querySelectorAll('.ffc-doc-toc-list li:not(.ffc-doc-toc-section)');
		// "Shortcodes" hidden, "Emails and Delivery" visible.
		expect(items[0].style.display).toBe('none');
		expect(items[1].style.display).toBe('');
	});

	it('restores everything when the query is cleared', () => {
		buildDom();
		loadScript(SCRIPT);
		type('smtp');
		expect(document.getElementById('card-shortcodes').style.display).toBe('none');

		type('');
		expect(document.getElementById('card-shortcodes').style.display).toBe('');
		expect(document.getElementById('card-emails').style.display).toBe('');
	});
});
