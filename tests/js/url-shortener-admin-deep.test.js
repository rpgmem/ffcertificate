// Sprint F1 — deep coverage for ffc-url-shortener-admin.js (247 LOC,
// previously 33.19%). `admin-small.test.js` covers the basic copy
// flow. This file drives the QR download / regenerate / modal / create
// flows + the toast lifecycle.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.$.fx.off = true;
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcUrlShortener = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		nonce: 'url-nonce',
		i18n: {
			copied: 'Copied!',
			copyFailed: 'Copy failed',
			confirm: 'Generate a new short code?',
			regenerated: 'Regenerated!',
			error: 'Error',
		},
	};
	// Stub navigator.clipboard which jsdom doesn't ship.
	Object.defineProperty(navigator, 'clipboard', {
		value: { writeText: vi.fn(() => Promise.resolve()) },
		configurable: true,
	});
	document.execCommand = vi.fn(() => true);
	// Stub URL.createObjectURL / revokeObjectURL for the QR download path.
	window.URL.createObjectURL = vi.fn(() => 'blob:fake');
	window.URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
	vi.restoreAllMocks();
});

async function loadAdmin() {
	loadScript('assets/js/ffc-url-shortener-admin.js');
	await new Promise((r) => setTimeout(r, 0));
}

// ----------------------------------------------------------------------
// Toast — exercised via the copy handler that calls showToast
// ----------------------------------------------------------------------

describe('url-shortener — toast', () => {
	it('appends a toast to <body> with the success class then removes it on fade-out timeout', async () => {
		document.body.innerHTML = `<button class="ffc-copy-shorturl" data-url="https://x/abc">Copy</button>`;
		await loadAdmin();

		vi.useFakeTimers();
		window.$('.ffc-copy-shorturl').trigger('click');
		// Wait for the clipboard promise.
		await Promise.resolve();
		await Promise.resolve();

		// Toast inserted, eventually gets the --visible modifier and is removed.
		expect(document.querySelectorAll('.ffc-shorturl-toast').length).toBeGreaterThanOrEqual(1);
		vi.advanceTimersByTime(20);
		expect(document.querySelector('.ffc-shorturl-toast--visible')).not.toBeNull();
		// Total lifetime: 2000ms visible + 300ms fade-out.
		vi.advanceTimersByTime(2300);
		expect(document.querySelector('.ffc-shorturl-toast')).toBeNull();
		vi.useRealTimers();
	});
});

// ----------------------------------------------------------------------
// Copy: fallback path
// ----------------------------------------------------------------------

describe('url-shortener — copy fallback', () => {
	it('uses execCommand("copy") when navigator.clipboard is missing', async () => {
		Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
		document.body.innerHTML = `<button class="ffc-copy-shorturl" data-url="https://x/abc">Copy</button>`;
		await loadAdmin();

		window.$('.ffc-copy-shorturl').trigger('click');

		expect(document.execCommand).toHaveBeenCalledWith('copy');
	});

	it('uses execCommand fallback when navigator.clipboard rejects', async () => {
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText: vi.fn(() => Promise.reject(new Error('denied'))) },
			configurable: true,
		});
		document.body.innerHTML = `<button class="ffc-copy-shorturl" data-url="https://x/abc">Copy</button>`;
		await loadAdmin();

		window.$('.ffc-copy-shorturl').trigger('click');
		await Promise.resolve();
		await Promise.resolve();

		expect(document.execCommand).toHaveBeenCalledWith('copy');
	});

	it('shows "Copy failed" toast when execCommand throws', async () => {
		Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
		document.execCommand = vi.fn(() => {
			throw new Error('boom');
		});
		document.body.innerHTML = `<button class="ffc-copy-shorturl" data-url="https://x/abc">Copy</button>`;
		await loadAdmin();

		window.$('.ffc-copy-shorturl').trigger('click');

		const toast = document.querySelector('.ffc-shorturl-toast');
		expect(toast).not.toBeNull();
		expect(toast.classList.contains('ffc-shorturl-toast--error')).toBe(true);
		expect(toast.textContent).toBe('Copy failed');
	});
});

// ----------------------------------------------------------------------
// Copy from the admin table cell (.ffc-shorturl-code)
// ----------------------------------------------------------------------

describe('url-shortener — table-cell copy', () => {
	it('copies the data-url on .ffc-shorturl-code click', async () => {
		// jsdom (like browsers) hoists a bare <td> out of <body>; wrap it
		// in a valid <table> so the cell stays in the DOM.
		document.body.innerHTML = `
			<table><tbody><tr>
				<td class="ffc-shorturl-code" data-url="https://x/table">x/table</td>
			</tr></tbody></table>
		`;
		await loadAdmin();

		window.$('.ffc-shorturl-code').trigger('click');
		await Promise.resolve();

		expect(navigator.clipboard.writeText).toHaveBeenCalledWith('https://x/table');
	});
});

// ----------------------------------------------------------------------
// QR download (PNG / SVG)
// ----------------------------------------------------------------------

describe('url-shortener — QR download', () => {
	function mountQrButton(format, attrs = {}) {
		const dataAttrs = Object.entries(attrs)
			.map(([k, v]) => `data-${k}="${v}"`)
			.join(' ');
		document.body.innerHTML = `
			<button class="ffc-download-qr" data-format="${format}" ${dataAttrs}>QR</button>
		`;
	}

	it('PNG: POSTs ffc_download_qr_png with the post_id and triggers a blob download', async () => {
		mountQrButton('png', { 'post-id': '42' });
		await loadAdmin();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation((url, payload, cb) => {
			cb({ success: true, data: { data: 'YWJj', filename: 'qr.png', mime: 'image/png' } });
			return { fail: () => ({}) };
		});

		window.$('.ffc-download-qr').trigger('click');

		expect(postSpy).toHaveBeenCalled();
		const payload = postSpy.mock.calls[0][1];
		// jQuery's .data('post-id') coerces "42" → 42; assert numeric.
		expect(payload).toMatchObject({ action: 'ffc_download_qr_png', nonce: 'url-nonce', post_id: 42 });
		expect(window.URL.createObjectURL).toHaveBeenCalled();
		expect(window.URL.revokeObjectURL).toHaveBeenCalledWith('blob:fake');
	});

	it('SVG: POSTs ffc_download_qr_svg with the code (no post_id)', async () => {
		mountQrButton('svg', { code: 'abc123' });
		await loadAdmin();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation((url, payload, cb) => {
			cb({ success: true, data: { data: 'PHN2Zy8+', filename: 'qr.svg', mime: 'image/svg+xml' } });
			return { fail: () => ({}) };
		});

		window.$('.ffc-download-qr').trigger('click');

		expect(postSpy.mock.calls[0][1]).toMatchObject({
			action: 'ffc_download_qr_svg',
			nonce: 'url-nonce',
			code: 'abc123',
		});
	});

	it('shows an error toast when response.success=false', async () => {
		mountQrButton('png', { code: 'abc' });
		await loadAdmin();
		vi.spyOn(window.$, 'post').mockImplementation((url, payload, cb) => {
			cb({ success: false, data: { message: 'No such code' } });
			return { fail: () => ({}) };
		});

		window.$('.ffc-download-qr').trigger('click');

		const toast = document.querySelector('.ffc-shorturl-toast--error');
		expect(toast).not.toBeNull();
		expect(toast.textContent).toBe('No such code');
		// Button re-enabled.
		expect(window.$('.ffc-download-qr').prop('disabled')).toBe(false);
	});

	it('shows the localised error toast on network failure', async () => {
		mountQrButton('png', { code: 'abc' });
		await loadAdmin();
		let failCb;
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			fail: (cb) => {
				failCb = cb;
				return {};
			},
		}));

		window.$('.ffc-download-qr').trigger('click');
		failCb();

		const toast = document.querySelector('.ffc-shorturl-toast--error');
		expect(toast.textContent).toBe('Error');
	});
});

// ----------------------------------------------------------------------
// Regenerate
// ----------------------------------------------------------------------

describe('url-shortener — regenerate', () => {
	function mountRegenBtn() {
		document.body.innerHTML = `
			<button class="ffc-regenerate-shorturl" data-post-id="55">Regen</button>
		`;
	}

	it('bails when the user cancels the confirm()', async () => {
		mountRegenBtn();
		await loadAdmin();
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
		const postSpy = vi.spyOn(window.$, 'post');

		window.$('.ffc-regenerate-shorturl').trigger('click');

		expect(confirmSpy).toHaveBeenCalledWith('Generate a new short code?');
		expect(postSpy).not.toHaveBeenCalled();
	});

	it('POSTs ffc_regenerate_short_url and reloads the page on success', async () => {
		mountRegenBtn();
		await loadAdmin();
		vi.spyOn(window, 'confirm').mockReturnValue(true);

		// Replace location with a stub before triggering reload.
		const originalLocation = window.location;
		const stubLocation = { reload: vi.fn(), href: '/', pathname: '/' };
		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: stubLocation,
		});

		vi.spyOn(window.$, 'post').mockImplementation((url, payload, cb) => {
			cb({ success: true });
			return { fail: () => ({}) };
		});

		window.$('.ffc-regenerate-shorturl').trigger('click');

		expect(stubLocation.reload).toHaveBeenCalled();
		expect(document.querySelector('.ffc-shorturl-toast').textContent).toBe('Regenerated!');

		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: originalLocation,
		});
	});

	it('shows the server error toast when response.success=false', async () => {
		mountRegenBtn();
		await loadAdmin();
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.$, 'post').mockImplementation((url, payload, cb) => {
			cb({ success: false, data: { message: 'Quota exceeded' } });
			return { fail: () => ({}) };
		});

		window.$('.ffc-regenerate-shorturl').trigger('click');

		const toast = document.querySelector('.ffc-shorturl-toast--error');
		expect(toast.textContent).toBe('Quota exceeded');
	});

	it('shows the localised error on network failure', async () => {
		mountRegenBtn();
		await loadAdmin();
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		let failCb;
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			fail: (cb) => {
				failCb = cb;
				return {};
			},
		}));

		window.$('.ffc-regenerate-shorturl').trigger('click');
		failCb();

		const toast = document.querySelector('.ffc-shorturl-toast--error');
		expect(toast.textContent).toBe('Error');
	});
});

// ----------------------------------------------------------------------
// QR modal (.ffc-show-qr-modal)
// ----------------------------------------------------------------------

describe('url-shortener — QR modal', () => {
	function mountQrModalTrigger() {
		document.body.innerHTML = `
			<button class="ffc-show-qr-modal"
				data-code="C1"
				data-url="https://x/C1"
				data-title="Short C1">QR</button>
			<div id="ffc-qr-modal" style="display:none">
				<h3 class="ffc-qr-modal__title"></h3>
				<p class="ffc-qr-modal__url"></p>
				<button class="ffc-copy-shorturl">Copy</button>
				<button class="ffc-download-qr" data-format="png">DL</button>
				<img class="ffc-qr-modal__img" />
				<div class="ffc-qr-modal__spinner"></div>
				<div class="ffc-qr-modal__preview"></div>
				<button class="ffc-qr-modal__close">×</button>
				<div class="ffc-qr-modal__backdrop"></div>
			</div>
		`;
	}

	it('populates title + url + copy/download data attrs and fetches the QR PNG', async () => {
		mountQrModalTrigger();
		await loadAdmin();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation((url, payload, cb) => {
			cb({ success: true, data: { data: 'PG5n', filename: 'qr.png', mime: 'image/png' } });
			return { fail: () => ({}) };
		});

		window.$('.ffc-show-qr-modal').trigger('click');

		expect(window.$('#ffc-qr-modal .ffc-qr-modal__title').text()).toBe('Short C1');
		expect(window.$('#ffc-qr-modal .ffc-qr-modal__url').text()).toBe('https://x/C1');
		expect(window.$('#ffc-qr-modal .ffc-copy-shorturl').data('url')).toBe('https://x/C1');
		expect(window.$('#ffc-qr-modal .ffc-download-qr').data('code')).toBe('C1');
		// AJAX fetch for the QR.
		expect(postSpy.mock.calls[0][1].action).toBe('ffc_download_qr_png');
		// Image src set with the base64 payload.
		expect(window.$('#ffc-qr-modal .ffc-qr-modal__img').attr('src')).toBe('data:image/png;base64,PG5n');
	});

	it('renders the server error in the preview pane when success=false', async () => {
		mountQrModalTrigger();
		await loadAdmin();
		vi.spyOn(window.$, 'post').mockImplementation((url, payload, cb) => {
			cb({ success: false, data: { message: 'Not allowed' } });
			return { fail: () => ({}) };
		});

		window.$('.ffc-show-qr-modal').trigger('click');

		expect(window.$('#ffc-qr-modal .ffc-qr-modal__preview').text()).toBe('Not allowed');
	});

	it('renders the network error in the preview pane', async () => {
		mountQrModalTrigger();
		await loadAdmin();
		let failCb;
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			fail: (cb) => {
				failCb = cb;
				return {};
			},
		}));

		window.$('.ffc-show-qr-modal').trigger('click');
		failCb();

		expect(window.$('#ffc-qr-modal .ffc-qr-modal__preview').text()).toBe('Failed to load QR Code');
	});

	it('close button hides the modal', async () => {
		mountQrModalTrigger();
		await loadAdmin();
		vi.spyOn(window.$, 'post').mockImplementation(() => ({ fail: () => ({}) }));
		window.$('.ffc-show-qr-modal').trigger('click');
		expect(window.$('#ffc-qr-modal').css('display')).not.toBe('none');

		window.$('.ffc-qr-modal__close').trigger('click');
		expect(window.$('#ffc-qr-modal').css('display')).toBe('none');
	});

	it('Escape closes the modal', async () => {
		mountQrModalTrigger();
		await loadAdmin();
		vi.spyOn(window.$, 'post').mockImplementation(() => ({ fail: () => ({}) }));
		window.$('.ffc-show-qr-modal').trigger('click');

		const ev = window.$.Event('keydown', { key: 'Escape' });
		window.$(document).trigger(ev);

		expect(window.$('#ffc-qr-modal').css('display')).toBe('none');
	});

	it('backdrop click closes the modal', async () => {
		mountQrModalTrigger();
		await loadAdmin();
		vi.spyOn(window.$, 'post').mockImplementation(() => ({ fail: () => ({}) }));
		window.$('.ffc-show-qr-modal').trigger('click');

		window.$('.ffc-qr-modal__backdrop').trigger('click');
		expect(window.$('#ffc-qr-modal').css('display')).toBe('none');
	});
});

// ----------------------------------------------------------------------
// Create short URL form
// ----------------------------------------------------------------------

describe('url-shortener — create form', () => {
	function mountCreateForm() {
		document.body.innerHTML = `
			<form id="ffc-create-short-url">
				<input id="ffc-shorturl-target" value="https://example.com/long" />
				<input id="ffc-shorturl-title" value="My link" />
				<input type="hidden" id="ffc_short_url_nonce" value="form-nonce" />
				<button type="submit">Create</button>
			</form>
			<div id="ffc-shorturl-result" style="display:none"></div>
		`;
	}

	it('POSTs ffc_create_short_url with target+title+nonce, renders the result, clears the form', async () => {
		mountCreateForm();
		await loadAdmin();

		// Stub location to prevent the auto-reload.
		const originalLocation = window.location;
		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: { reload: vi.fn(), href: '/', pathname: '/' },
		});

		vi.spyOn(window.$, 'post').mockImplementation((url, payload, cb) => {
			cb({ success: true, data: { short_url: 'https://x.test/aB3' } });
			return { fail: () => ({}) };
		});

		window.$('#ffc-create-short-url').trigger('submit');

		expect(window.$('#ffc-shorturl-result').html()).toContain('https://x.test/aB3');
		expect(window.$('#ffc-shorturl-target').val()).toBe('');
		expect(window.$('#ffc-shorturl-title').val()).toBe('');

		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: originalLocation,
		});
	});

	it('shows the server error inline when response.success=false', async () => {
		mountCreateForm();
		await loadAdmin();
		vi.spyOn(window.$, 'post').mockImplementation((url, payload, cb) => {
			cb({ success: false, data: { message: 'Invalid URL' } });
			return { fail: () => ({}) };
		});

		window.$('#ffc-create-short-url').trigger('submit');

		expect(window.$('#ffc-shorturl-result').text()).toContain('Invalid URL');
	});

	it('shows the request-failed message on network failure', async () => {
		mountCreateForm();
		await loadAdmin();
		let failCb;
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			fail: (cb) => {
				failCb = cb;
				return {};
			},
		}));

		window.$('#ffc-create-short-url').trigger('submit');
		failCb();

		expect(window.$('#ffc-shorturl-result').text()).toContain('Request failed');
	});
});
