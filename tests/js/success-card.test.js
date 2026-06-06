// Tests for the success-card additions in Sprint 1 of #313 (6.6.2):
//   - .ffc-copy-btn → clipboard write (async API + legacy fallback)
//   - filterPlatformGuidance hides the non-matching <li data-platform="…">
//
// Strategy mirrors tests/js/frontend-form-submission.test.js: load the same
// ffc-core + frontend-helpers + frontend.js bundle that the page uses,
// then drive the document via jsdom events. The helpers we want to
// exercise are private to the IIFE; we exercise them via their public
// surface (DOM click handlers and the `data.html` injection path).
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(async () => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		strings: {
			copy: 'Copy',
			copied: 'Copied!',
			copyFailed: 'Could not copy',
			fillRequired: 'Please fill all required fields',
			processing: 'Processing…',
			error: 'Error occurred',
			connectionError: 'Connection error',
		},
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-frontend-helpers.js');
	loadScript('assets/js/ffc-frontend.js');
	await new Promise((r) => setTimeout(r, 0));
});

beforeEach(() => {
	document.body.innerHTML = '';
	// Reset Object.defineProperty overrides between tests.
	Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
	Object.defineProperty(navigator, 'userAgent', {
		configurable: true,
		value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
	});
	Object.defineProperty(navigator, 'maxTouchPoints', { configurable: true, value: 0 });
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('success-card copy button', () => {
	function installCopyButton(value) {
		document.body.innerHTML = `
			<div class="ffc-success-response">
				<button class="ffc-copy-btn" data-ffc-copy="${value}">Copy</button>
			</div>
		`;
		return window.$('.ffc-copy-btn');
	}

	it('writes to the async clipboard API and swaps the label to "Copied!"', async () => {
		const writeText = vi.fn(() => Promise.resolve());
		Object.defineProperty(navigator, 'clipboard', {
			configurable: true,
			value: { writeText },
		});
		const $btn = installCopyButton('ABC-12345');
		$btn.trigger('click');
		// writeText resolves on microtask; flush.
		await Promise.resolve().then(() => Promise.resolve());
		expect(writeText).toHaveBeenCalledWith('ABC-12345');
		expect($btn.text()).toBe('Copied!');
	});

	it('falls back to document.execCommand when async clipboard rejects', async () => {
		const writeText = vi.fn(() => Promise.reject(new Error('denied')));
		Object.defineProperty(navigator, 'clipboard', {
			configurable: true,
			value: { writeText },
		});
		document.execCommand = function () { return true; };
		const execSpy = vi.spyOn(document, 'execCommand');
		const $btn = installCopyButton('https://example.org/?token=abc');
		$btn.trigger('click');
		await Promise.resolve().then(() => Promise.resolve());
		expect(execSpy).toHaveBeenCalledWith('copy');
		expect($btn.text()).toBe('Copied!');
	});

	it('shows the failure label when both clipboard paths fail', async () => {
		// No async clipboard, execCommand returns false.
		document.execCommand = function () { return false; };
		const execSpy = vi.spyOn(document, 'execCommand');
		const $btn = installCopyButton('XYZ');
		$btn.trigger('click');
		await Promise.resolve().then(() => Promise.resolve());
		expect(execSpy).toHaveBeenCalledWith('copy');
		expect($btn.text()).toBe('Could not copy');
	});

	it('does nothing when data-ffc-copy is empty', () => {
		document.body.innerHTML = `<button class="ffc-copy-btn">Copy</button>`;
		document.execCommand = function () { return true; };
		const execSpy = vi.spyOn(document, 'execCommand');
		window.$('.ffc-copy-btn').trigger('click');
		expect(execSpy).not.toHaveBeenCalled();
	});
});

describe('success-card platform guidance filter', () => {
	function setupFormThenInject(platformUA, maxTouchPoints = 0) {
		Object.defineProperty(navigator, 'userAgent', { configurable: true, value: platformUA });
		Object.defineProperty(navigator, 'maxTouchPoints', { configurable: true, value: maxTouchPoints });
		// Reuse the form-submission code path: we mock $.post so the
		// success branch fires synchronously and injects our HTML.
		document.body.innerHTML = `
			<form class="ffc-submission-form">
				<input type="hidden" name="form_id" value="1" />
				<button type="submit">Send</button>
			</form>
		`;
		const successHTML = `
			<div class="ffc-success-response">
				<ul class="ffc-success-where-is-file">
					<li data-platform="android">android line</li>
					<li data-platform="ios">ios line</li>
					<li data-platform="desktop">desktop line</li>
				</ul>
			</div>
		`;
		vi.spyOn(window.$, 'post').mockImplementation(function () {
			let doneCb = null;
			const chain = {
				done(cb) { doneCb = cb; doneCb({ success: true, data: { html: successHTML } }); return chain; },
				fail() { return chain; },
			};
			return chain;
		});
		window.$('form.ffc-submission-form').trigger('submit');
	}

	function visibleLines() {
		return Array.from(document.querySelectorAll('li[data-platform]'))
			.filter((li) => li.style.display !== 'none')
			.map((li) => li.getAttribute('data-platform'));
	}

	it('hides android + desktop lines on iPhone UA', async () => {
		setupFormThenInject('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)');
		await Promise.resolve().then(() => Promise.resolve());
		expect(visibleLines()).toEqual(['ios']);
	});

	it('hides ios + desktop lines on Android UA', async () => {
		setupFormThenInject('Mozilla/5.0 (Linux; Android 14; Pixel 7)');
		await Promise.resolve().then(() => Promise.resolve());
		expect(visibleLines()).toEqual(['android']);
	});

	it('hides ios + android lines on desktop UA', async () => {
		setupFormThenInject('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
		await Promise.resolve().then(() => Promise.resolve());
		expect(visibleLines()).toEqual(['desktop']);
	});

	it('treats iPadOS (Macintosh UA + maxTouchPoints>1) as iOS', async () => {
		setupFormThenInject('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15', 5);
		await Promise.resolve().then(() => Promise.resolve());
		expect(visibleLines()).toEqual(['ios']);
	});
});
