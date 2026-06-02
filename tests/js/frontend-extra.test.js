// Sprint E coverage for `assets/js/ffc-frontend.js` — covers paths that
// `frontend-deep.test.js` left out:
//
//   - handleMagicLinkVerification (load-time AJAX driven by token in
//     data attribute / hash / query string / no-token bail).
//   - displayVerificationResult legacy fallback (no `html` field).
//   - showVerificationError (rendered after a magic-link failure).
//   - .ffc-verification-form submit — empty / valid / refresh_captcha /
//     network error.
//   - .ffc-manual-verify-btn click — reloads the page.
//   - PDF download button — happy path + missing generator branch +
//     JSON parse error.
//   - textarea auto-resize on input.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// FFC.request (the migration target) wraps jQuery.post() in a Promise.
// Mock $.post and return a chain whose .done / .fail callback the
// FFC.request internals invoke.
function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(); return chain; };
	return chain;
}

// Microtask flush so .then/.catch reactions run before assertions.
function flush() { return Promise.resolve().then(() => Promise.resolve()); }

// Poll until `fn()` is truthy or the timeout elapses. The magic-link handler
// is wrapped in setTimeout(100) and then runs an async AJAX chain, so a fixed
// sleep races under CI load (it lost the race intermittently). Polling resolves
// as soon as the expected DOM/call appears and only waits the full window when
// it genuinely never happens.
async function waitFor(fn, { timeout = 1500, interval = 10 } = {}) {
	const start = Date.now();
	while (Date.now() - start < timeout) {
		if (fn()) return;
		await new Promise((r) => setTimeout(r, interval));
	}
}


beforeAll(async () => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		strings: {
			fillRequired: 'Please fill all required fields',
			processing: 'Processing…',
			error: 'Error',
			connectionError: 'Connection error',
			certificateValid: 'Document Valid!',
			certificateInvalid: 'Document Invalid',
			formTitle: 'Form',
			authCode: 'Auth Code',
			issueDate: 'Issue Date',
			downloadPDF: 'Download PDF',
			tryManually: 'Or try manual verification',
			enterAuthCode: 'Enter auth code',
			verify: 'Verify',
			enterCode: 'Please enter the code',
			pdfLibrariesFailed: 'PDF generation not available',
		},
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-frontend-helpers.js');
	loadScript('assets/js/ffc-frontend.js');
	await new Promise((r) => setTimeout(r, 0));
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.history.replaceState({}, '', '/');
	window.location.hash = '';
});

afterEach(() => {
	vi.restoreAllMocks();
});

// ----------------------------------------------------------------------
// handleMagicLinkVerification — load-time AJAX
// ----------------------------------------------------------------------

describe('frontend.handleMagicLinkVerification', () => {
	// The load-time handler is wrapped in setTimeout(100ms). The
	// $(document).ready() block also fires once during the test file's
	// beforeAll. We invoke the same flow by triggering displayVerification
	// indirectly via a reloaded script — but the cleanest path is to
	// simulate the manual route: the IIFE registers a submit handler on
	// .ffc-verification-form which goes through the same code paths.
	// Here we cover the documented branches by exercising the handler
	// directly via reloading inside a token-bearing fixture.

	function mountContainer({ withToken = false, tokenInHash = false, tokenInQuery = false } = {}) {
		const dataAttr = withToken ? 'data-token="magic-token"' : '';
		document.body.innerHTML = `
			<div class="ffc-magic-link-container" ${dataAttr}>
				<div class="ffc-verification-manual">manual form</div>
				<div class="ffc-verify-loading" style="display:none">loading</div>
			</div>
		`;
		if (tokenInHash) {
			window.location.hash = 'token=hash-token';
		}
		if (tokenInQuery) {
			window.history.replaceState({}, '', '/?token=query-token');
		}
	}

	it('bails when no container is present (no AJAX fired)', async () => {
		// No fixture mounted — the load-time handler bails. Just re-load
		// to trigger another pass and assert nothing throws.
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));
		loadScript('assets/js/ffc-frontend.js');
		// The ready() callback fires next microtask; let it run.
		return new Promise((r) => setTimeout(r, 0)).then(() => {
			// Magic link verification uses setTimeout(100) before checking —
			// but with no container it bails immediately. Some AJAX may
			// still fire for the form-submission path. Assert that the
			// magic-link action specifically wasn't called.
			const magicCalls = postSpy.mock.calls.filter(
				(c) => c[1] && c[1].action === 'ffc_verify_magic_token',
			);
			expect(magicCalls).toEqual([]);
		});
	});

	it('reads token from data-token attribute and POSTs ffc_verify_magic_token', async () => {
		mountContainer({ withToken: true });
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		loadScript('assets/js/ffc-frontend.js');
		// The handler is wrapped in setTimeout(100); poll until it fires.
		await waitFor(() => postSpy.mock.calls.some(
			(c) => c[1] && c[1].action === 'ffc_verify_magic_token',
		));

		const calls = postSpy.mock.calls.filter(
			(c) => c[1] && c[1].action === 'ffc_verify_magic_token',
		);
		expect(calls.length).toBeGreaterThan(0);
		expect(calls[0][1].token).toBe('magic-token');
	});

	it('falls back to the hash token when no data-token is set', async () => {
		mountContainer({ tokenInHash: true });
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		loadScript('assets/js/ffc-frontend.js');
		await waitFor(() => postSpy.mock.calls.some(
			(c) => c[1] && c[1].action === 'ffc_verify_magic_token',
		));

		const calls = postSpy.mock.calls.filter(
			(c) => c[1] && c[1].action === 'ffc_verify_magic_token',
		);
		expect(calls[0][1].token).toBe('hash-token');
	});

	it('falls back to the query-string token when no data-token or hash', async () => {
		mountContainer({ tokenInQuery: true });
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		loadScript('assets/js/ffc-frontend.js');
		await waitFor(() => postSpy.mock.calls.some(
			(c) => c[1] && c[1].action === 'ffc_verify_magic_token',
		));

		const calls = postSpy.mock.calls.filter(
			(c) => c[1] && c[1].action === 'ffc_verify_magic_token',
		);
		expect(calls[0][1].token).toBe('query-token');
	});

	it('returns without calling AJAX when there is no token anywhere', async () => {
		mountContainer({});
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		loadScript('assets/js/ffc-frontend.js');
		await new Promise((r) => setTimeout(r, 120));

		const calls = postSpy.mock.calls.filter(
			(c) => c[1] && c[1].action === 'ffc_verify_magic_token',
		);
		expect(calls).toEqual([]);
	});

	it('on AJAX error: shows the connection-error message via showVerificationError', async () => {
		mountContainer({ withToken: true });
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));

		loadScript('assets/js/ffc-frontend.js');
		await waitFor(() => window.$('.ffc-verification-error').length === 1);

		// showVerificationError replaces container HTML with .ffc-verification-error.
		expect(window.$('.ffc-verification-error').length).toBe(1);
	});

	it('on success=false: surfaces the server message', async () => {
		mountContainer({ withToken: true });
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Token expired' } } }));

		loadScript('assets/js/ffc-frontend.js');
		await waitFor(() => window.$('.ffc-verification-error').length === 1);

		expect(window.$('.ffc-verification-error').text()).toContain('Token expired');
	});

	it('on success=true with html: writes server HTML directly into the container', async () => {
		mountContainer({ withToken: true });
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: { html: '<div class="result">Valid!</div>' },
			} }));

		loadScript('assets/js/ffc-frontend.js');
		await waitFor(() => window.$('.ffc-magic-link-container .result').length === 1);

		expect(window.$('.ffc-magic-link-container .result').text()).toBe('Valid!');
	});

	it('on success=true with html + pdf_data: writes data-pdf-data on download buttons', async () => {
		mountContainer({ withToken: true });
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					html: '<button class="ffc-download-pdf-btn">PDF</button>',
					pdf_data: { template: 'A', form_title: 'T' },
				},
			} }));

		loadScript('assets/js/ffc-frontend.js');
		await waitFor(() => window.$('.ffc-download-pdf-btn').length === 1);

		const $btn = window.$('.ffc-download-pdf-btn');
		expect($btn.length).toBe(1);
		expect(JSON.parse($btn.attr('data-pdf-data'))).toEqual({ template: 'A', form_title: 'T' });
	});

	it('on success=true legacy (no html): renders the success block from fields', async () => {
		mountContainer({ withToken: true });
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					form_title: 'My Form',
					auth_code: 'AAAA-1111',
					submission_date: '2026-05-01',
					template: 'tpl',
				},
			} }));

		loadScript('assets/js/ffc-frontend.js');
		await waitFor(() => window.$('.ffc-magic-link-container .ffc-verification-success').length === 1);

		const $container = window.$('.ffc-magic-link-container');
		expect($container.find('.ffc-verification-success').length).toBe(1);
		expect($container.text()).toContain('My Form');
		expect($container.text()).toContain('AAAA-1111');
		// Download button gets the pdf_data shape composed from legacy fields.
		const $btn = $container.find('.ffc-download-pdf-btn');
		expect($btn.length).toBe(1);
	});
});

// ----------------------------------------------------------------------
// .ffc-verification-form submit
// ----------------------------------------------------------------------

describe('frontend.verificationForm submit', () => {
	function mountForm() {
		document.body.innerHTML = `
			<div class="ffc-verification-container">
				<form class="ffc-verification-form">
					<div class="ffc-form-field">
						<input type="text" name="ffc_auth_code" value="" />
					</div>
					<input type="text" name="ffc_captcha_ans" value="abc" />
					<input type="hidden" name="ffc_captcha_hash" value="hash-1" />
					<input type="hidden" name="ffc_honeypot_trap" value="" />
					<span class="ffc-captcha-label-text">Original</span>
					<button type="submit">Verify</button>
				</form>
			</div>
		`;
	}

	it('shows an accessible alert when authCode is empty', async () => {
		mountForm();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		window.$('.ffc-verification-form').trigger('submit');
		await flush();

		expect(postSpy).not.toHaveBeenCalled();
		expect(window.$('.ffc-accessible-alert').length).toBe(1);
	});

	it('POSTs ffc_verify_certificate with the gathered fields when authCode is set', async () => {
		mountForm();
		window.$('input[name="ffc_auth_code"]').val('AAAA-1111');
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		window.$('.ffc-verification-form').trigger('submit');
		await flush();

		// Previous tests have re-loaded ffc-frontend.js several times, each
		// installing its own delegate on $(document). The submit fires all
		// of them; each call carries the same payload. Assert on the first
		// captured call rather than the call count.
		expect(postSpy).toHaveBeenCalled();
		expect(postSpy.mock.calls[0][1]).toMatchObject({
			action: 'ffc_verify_certificate',
			ffc_auth_code: 'AAAA-1111',
			ffc_captcha_ans: 'abc',
			ffc_captcha_hash: 'hash-1',
		});
	});

	it('refreshes the captcha when response.data.refresh_captcha is set', async () => {
		mountForm();
		window.$('input[name="ffc_auth_code"]').val('CODE');
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: false,
				data: {
					refresh_captcha: true,
					new_label: 'New Question',
					new_hash: 'new-hash',
					message: 'Wrong captcha',
				},
			} }));

		window.$('.ffc-verification-form').trigger('submit');
		await flush();

		expect(window.$('.ffc-captcha-label-text').text()).toBe('New Question');
		expect(window.$('input[name="ffc_captcha_hash"]').val()).toBe('new-hash');
		expect(window.$('.ffc-verify-error').text()).toContain('Wrong captcha');
	});

	it('on network error: shows the connection-error alert', async () => {
		mountForm();
		window.$('input[name="ffc_auth_code"]').val('CODE');
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));

		window.$('.ffc-verification-form').trigger('submit');
		await flush();

		expect(window.$('.ffc-accessible-alert').text()).toContain('Connection error');
	});
});

// ----------------------------------------------------------------------
// .ffc-download-pdf-btn click
// ----------------------------------------------------------------------

describe('frontend.downloadPdfBtn', () => {
	it('calls window.ffcGeneratePDF with parsed data + filename', async () => {
		document.body.innerHTML = `
			<div>
				<button class="ffc-download-pdf-btn"
					data-pdf-data='{"filename":"my.pdf","template":"x"}'>PDF</button>
			</div>
		`;
		window.ffcGeneratePDF = vi.fn();

		window.$('.ffc-download-pdf-btn').trigger('click');
		await flush();

		expect(window.ffcGeneratePDF).toHaveBeenCalledWith(
			{ filename: 'my.pdf', template: 'x' },
			'my.pdf',
		);
	});

	it('shows an alert when window.ffcGeneratePDF is not a function', async () => {
		document.body.innerHTML = `
			<div>
				<button class="ffc-download-pdf-btn"
					data-pdf-data='{"template":"x"}'>PDF</button>
			</div>
		`;
		delete window.ffcGeneratePDF;

		window.$('.ffc-download-pdf-btn').trigger('click');
		await flush();

		expect(window.$('.ffc-accessible-alert').text()).toContain('PDF generation not available');
	});

	it('catches malformed JSON and surfaces an alert', async () => {
		document.body.innerHTML = `
			<div>
				<button class="ffc-download-pdf-btn" data-pdf-data='{invalid json}'>PDF</button>
			</div>
		`;

		// Silence the console.error noise from the catch.
		vi.spyOn(console, 'error').mockImplementation(() => {});
		window.$('.ffc-download-pdf-btn').trigger('click');
		await flush();

		expect(window.$('.ffc-accessible-alert').length).toBe(1);
	});
});

// ----------------------------------------------------------------------
// .ffc-manual-verify-btn click — reloads via location.href assignment
// ----------------------------------------------------------------------

describe('frontend.manualVerifyBtn', () => {
	it('reassigns window.location.href on click', async () => {
		document.body.innerHTML = `<button class="ffc-manual-verify-btn">Manual</button>`;

		// jsdom's Location is a host object; replace it wholesale with a
		// plain stub so we can observe href assignments.
		const original = window.location;
		const stub = { pathname: '/some/path', href: '/initial' };
		Object.defineProperty(window, 'location', {
			configurable: true,
			value: stub,
			writable: true,
		});

		window.$('.ffc-manual-verify-btn').trigger('click');
		await flush();

		expect(stub.href).toBe('/some/path');

		Object.defineProperty(window, 'location', {
			configurable: true,
			value: original,
			writable: true,
		});
	});
});

// ----------------------------------------------------------------------
// Textarea auto-resize (registered inside ready())
// ----------------------------------------------------------------------

describe('frontend.textareaAutoResize', () => {
	it('updates inline height to scrollHeight on input', async () => {
		document.body.innerHTML = `<textarea class="ffc-textarea"></textarea>`;
		// jsdom returns scrollHeight=0 by default — patch it so the
		// assertion has something to read.
		Object.defineProperty(window.HTMLTextAreaElement.prototype, 'scrollHeight', {
			configurable: true,
			get() {
				return 123;
			},
		});

		window.$('.ffc-textarea').trigger('input');
		await flush();

		expect(document.querySelector('.ffc-textarea').style.height).toBe('123px');
	});
});
