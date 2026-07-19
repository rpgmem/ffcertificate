// Tests for ffc-doc-toc.js — the sticky/collapsible Quick Navigation TOC
// behaviour on page=ffc-settings&tab=documentation. The IntersectionObserver
// is mocked here so we can drive intersection callbacks deterministically
// (jsdom does not implement IO natively).
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

let observers;

class MockIntersectionObserver {
	constructor(callback) {
		this.callback = callback;
		this.targets = [];
		observers.push(this);
	}
	observe(target) {
		this.targets.push(target);
	}
	disconnect() {
		this.targets = [];
	}
	// Test helper to simulate the browser firing the IO callback.
	fire(isIntersecting) {
		this.callback(
			this.targets.map((target) => ({ target, isIntersecting })),
			this
		);
	}
}

function buildDom({ withTocCard = true, withSentinel = true } = {}) {
	const sentinel = withSentinel
		? '<div class="ffc-doc-toc-sentinel" aria-hidden="true"></div>'
		: '';
	const toc = withTocCard
		? `<div class="card ffc-doc-toc">
				<h3>Quick Navigation</h3>
				<ul class="ffc-doc-toc-list">
					<li><a href="#shortcodes">1. Shortcodes</a></li>
					<li><a href="#variables">2. Variables</a></li>
				</ul>
			</div>`
		: '';
	document.body.innerHTML = `<div class="wrap">${sentinel}${toc}</div>`;
}

beforeEach(() => {
	observers = [];
	window.IntersectionObserver = MockIntersectionObserver;
	document.body.innerHTML = '';
});

afterEach(() => {
	delete window.IntersectionObserver;
	vi.restoreAllMocks();
});

describe('ffc-doc-toc — initialisation', () => {
	it('no-ops when the TOC card is absent from the page', () => {
		buildDom({ withTocCard: false });
		loadScript('assets/js/ffc-doc-toc.js');

		expect(observers).toHaveLength(0);
	});

	it('no-ops when the sentinel is missing', () => {
		buildDom({ withSentinel: false });
		loadScript('assets/js/ffc-doc-toc.js');

		expect(observers).toHaveLength(0);
	});

	it('no-ops when IntersectionObserver is not supported', () => {
		buildDom();
		delete window.IntersectionObserver;
		loadScript('assets/js/ffc-doc-toc.js');

		// No observer was constructed and the TOC stays expanded.
		expect(observers).toHaveLength(0);
		expect(document.querySelector('.ffc-doc-toc').classList.contains('is-collapsed')).toBe(false);
	});

	it('observes the sentinel when the markup is present and IO is supported', () => {
		buildDom();
		loadScript('assets/js/ffc-doc-toc.js');

		expect(observers).toHaveLength(1);
		expect(observers[0].targets).toHaveLength(1);
		expect(observers[0].targets[0]).toBe(document.querySelector('.ffc-doc-toc-sentinel'));
	});

	it('defers init to DOMContentLoaded while the document is still loading', () => {
		buildDom();
		const readyStateSpy = vi
			.spyOn(document, 'readyState', 'get')
			.mockReturnValue('loading');
		const addSpy = vi.spyOn(document, 'addEventListener');

		loadScript('assets/js/ffc-doc-toc.js');

		// init was deferred, so no observer constructed yet.
		expect(observers).toHaveLength(0);
		const dclCall = addSpy.mock.calls.find((c) => c[0] === 'DOMContentLoaded');
		expect(dclCall).toBeTruthy();

		// Restore readyState before firing init so init() runs its body.
		readyStateSpy.mockRestore();
		dclCall[1]();
		expect(observers).toHaveLength(1);
	});
});

describe('ffc-doc-toc — IntersectionObserver-driven collapse', () => {
	it('adds .is-collapsed when the sentinel leaves the viewport', () => {
		buildDom();
		loadScript('assets/js/ffc-doc-toc.js');

		observers[0].fire(false); // sentinel out of view → user scrolled past
		expect(document.querySelector('.ffc-doc-toc').classList.contains('is-collapsed')).toBe(true);
	});

	it('removes .is-collapsed when the sentinel re-enters the viewport', () => {
		buildDom();
		loadScript('assets/js/ffc-doc-toc.js');

		observers[0].fire(false);
		observers[0].fire(true); // user scrolled back to the top
		expect(document.querySelector('.ffc-doc-toc').classList.contains('is-collapsed')).toBe(false);
	});
});

describe('ffc-doc-toc — click behaviour', () => {
	it('toggles .is-collapsed when the user clicks the strip (non-anchor)', () => {
		buildDom();
		loadScript('assets/js/ffc-doc-toc.js');

		const toc = document.querySelector('.ffc-doc-toc');
		// Start in the auto-collapsed state.
		observers[0].fire(false);
		expect(toc.classList.contains('is-collapsed')).toBe(true);

		// Click on the title (h3) → expand.
		toc.querySelector('h3').click();
		expect(toc.classList.contains('is-collapsed')).toBe(false);

		// Click again → collapse.
		toc.querySelector('h3').click();
		expect(toc.classList.contains('is-collapsed')).toBe(true);
	});

	it('forces collapse and lets the navigation proceed when an anchor is clicked', () => {
		buildDom();
		loadScript('assets/js/ffc-doc-toc.js');

		const toc = document.querySelector('.ffc-doc-toc');
		// Start expanded.
		observers[0].fire(true);
		expect(toc.classList.contains('is-collapsed')).toBe(false);

		toc.querySelector('a').click();
		// After clicking an anchor, the strip is re-collapsed so the next
		// scroll the user makes restarts from the auto-collapsed state.
		expect(toc.classList.contains('is-collapsed')).toBe(true);
	});

	it('does not collapse the card when a branch <summary> is toggled', () => {
		buildDom();
		const list = document.querySelector('.ffc-doc-toc-list');
		const branch = document.createElement('li');
		branch.className = 'ffc-doc-toc-branch';
		branch.innerHTML =
			'<details><summary>Group</summary><ul><li><a href="#x">X</a></li></ul></details>';
		list.appendChild(branch);
		loadScript('assets/js/ffc-doc-toc.js');

		const toc = document.querySelector('.ffc-doc-toc');
		observers[0].fire(true); // expanded
		expect(toc.classList.contains('is-collapsed')).toBe(false);

		// Toggling a branch must leave the whole card expanded.
		toc.querySelector('summary').click();
		expect(toc.classList.contains('is-collapsed')).toBe(false);
	});
});
