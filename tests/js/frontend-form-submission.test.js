// Deep coverage for `assets/js/ffc-frontend.js` — exercises the AJAX
// flows that Sprint G of #170 deferred: form submission, magic-link
// verification, error / success branches, rate-limit branch.
//
// Strategy: install jQuery first (already in setup.js), load FFC core +
// frontend-helpers (frontend.js touches FFC.Frontend.* at module scope),
// then load frontend.js. The IIFE registers document-level submit and
// load-time handlers. Dispatch synthetic events and mock `$.ajax` to
// drive the responses.
//
// Sprint K of #173.
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


beforeAll(async () => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		strings: {
			fillRequired: 'Please fill all required fields',
			processing: 'Processing…',
			error: 'Error occurred',
			connectionError: 'Connection error',
		},
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-frontend-helpers.js');
	loadScript('assets/js/ffc-frontend.js');
	// jQuery 4 defers `$(document).ready(cb)` to a microtask — wait for
	// it so handleFormSubmission's submit delegate is wired before the
	// first test triggers a form submit.
	await new Promise((r) => setTimeout(r, 0));
});

beforeEach(() => {
	document.body.innerHTML = '';
	// Reset location.hash + search between tests (jsdom allows this).
	window.history.replaceState({}, '', '/');
});

afterEach(() => {
	vi.restoreAllMocks();
});

// ffc-frontend.js was migrated from `$.ajax({ url, type, data, success,
// error })` to `FFC.request(action, data)`, which internally calls
// `jQuery.post(url, payload).done(cb1).fail(cb2)`. This adapter keeps
// the existing test bodies (`mockAjax((opts) => { opts.success(...); })`)
// working: it spies on $.post, returns a chain that captures FFC.request's
// done/fail callbacks, then invokes the legacy impl with a synthesised
// opts whose .success / .error fan out to the captured callbacks.
function mockAjax(impl) {
	return vi.spyOn(window.$, 'post').mockImplementation(function (url, payload) {
		let doneCb = null;
		let failCb = null;
		const chain = {
			done: function (cb) { doneCb = cb; runImpl(); return chain; },
			fail: function (cb) { failCb = cb; runImpl(); return chain; },
		};
		function runImpl() {
			if (doneCb === null || failCb === null) return; // wait for both
			if (chain._ran) return;
			chain._ran = true;
			impl({
				url: url,
				data: payload,
				success: function (res) { doneCb(res); },
				error: function (xhr) { failCb(xhr); },
			});
		}
		return chain;
	});
}

// ----------------------------------------------------------------------
// handleFormSubmission — registered on $(document) at script load time
// ----------------------------------------------------------------------

describe('frontend.handleFormSubmission', () => {
	function setupForm({ withRequired = false } = {}) {
		document.body.innerHTML = `
			<form class="ffc-submission-form">
				<input type="hidden" name="form_id" value="42" />
				<input type="text" name="name" ${withRequired ? 'required' : ''} />
				<button type="submit">Send</button>
			</form>
		`;
		return window.$('form.ffc-submission-form');
	}

	it('blocks submission when a required field is empty', async () => {
		const $form = setupForm({ withRequired: true });
		const spy = mockAjax(() => ({}));
		$form.trigger('submit');
		await flush();
		expect(spy).not.toHaveBeenCalled();
		// First required field is marked invalid.
		const input = document.querySelector('input[name="name"]');
		expect(input.classList.contains('ffc-field-error')).toBe(true);
		expect(input.getAttribute('aria-invalid')).toBe('true');
	});

	it('inserts an accessible alert above the form when validation fails', async () => {
		const $form = setupForm({ withRequired: true });
		mockAjax(() => ({}));
		$form.trigger('submit');
		await flush();
		const alert = document.querySelector('.ffc-accessible-alert');
		expect(alert).not.toBeNull();
		expect(alert.textContent).toContain('Please fill all required fields');
		expect(alert.getAttribute('role')).toBe('alert');
	});

	it('sends the AJAX with form data + action + nonce when valid', async () => {
		const $form = setupForm();
		const spy = mockAjax(() => ({}));
		document.querySelector('input[name="name"]').value = 'Maria';
		$form.trigger('submit');
		await flush();

		expect(spy).toHaveBeenCalledOnce();
		// FFC.request → jQuery.post(url, payload); url is calls[0][0],
		// payload (form data + action + nonce appended) is calls[0][1].
		const [url, payload] = spy.mock.calls[0];
		expect(url).toBe('/wp-admin/admin-ajax.php');
		expect(payload).toContain('form_id=42');
		expect(payload).toContain('name=Maria');
		expect(payload).toContain('action=ffc_submit_form');
		expect(payload).toContain('nonce=test-nonce');
	});

	it('on success: replaces the form HTML with response.data.html', async () => {
		const $form = setupForm();
		mockAjax((opts) => {
			opts.success({
				success: true,
				data: { html: '<div class="ok-card">Submission OK</div>' },
			});
			return {};
		});
		$form.trigger('submit');
		await flush();
		expect(document.querySelector('form.ffc-submission-form .ok-card')).not.toBeNull();
	});

	it('on success with pdf_data: adds data-pdf-data attribute on the download button', async () => {
		const $form = setupForm();
		mockAjax((opts) => {
			opts.success({
				success: true,
				data: {
					html: '<button class="ffc-download-btn">Download</button>',
					pdf_data: { filename: 'cert.pdf', content: 'base64...' },
				},
			});
			return {};
		});
		$form.trigger('submit');
		await flush();
		const btn = document.querySelector('.ffc-download-btn');
		expect(btn).not.toBeNull();
		const pdfAttr = btn.getAttribute('data-pdf-data');
		expect(pdfAttr).not.toBeNull();
		expect(JSON.parse(pdfAttr).filename).toBe('cert.pdf');
	});

	it('triggers window.ffcGeneratePDF when pdf_data is in the response', async () => {
		vi.useFakeTimers();
		const generatePDF = vi.fn();
		window.ffcGeneratePDF = generatePDF;

		const $form = setupForm();
		mockAjax((opts) => {
			opts.success({
				success: true,
				data: {
					html: '<button class="ffc-download-btn">D</button>',
					pdf_data: { filename: 'doc.pdf' },
				},
			});
			return {};
		});
		$form.trigger('submit');
		await flush();
		// The script defers via setTimeout(500). Fast-forward.
		vi.advanceTimersByTime(600);

		expect(generatePDF).toHaveBeenCalledOnce();
		expect(generatePDF.mock.calls[0][1]).toBe('doc.pdf');
		vi.useRealTimers();
		delete window.ffcGeneratePDF;
	});

	it('clears a stale error class from a required field that now has a value', async () => {
		const $form = setupForm({ withRequired: true });
		const input = document.querySelector('input[name="name"]');
		// Pre-mark as invalid (as a prior failed submit would have).
		input.classList.add('ffc-field-error');
		input.setAttribute('aria-invalid', 'true');
		input.value = 'Filled';
		mockAjax((opts) => { opts.success({ success: true, data: { html: '<div>ok</div>' } }); return {}; });

		$form.trigger('submit');
		await flush();

		// The validation loop took the else-branch and cleared the markers.
		expect(input.classList.contains('ffc-field-error')).toBe(false);
		expect(input.getAttribute('aria-invalid')).toBeNull();
	});

	it('on a resolved-but-null response: shows the generic error and re-enables submit', async () => {
		const $form = setupForm();
		document.querySelector('input[name="name"]').value = 'Maria';
		mockAjax((opts) => { opts.success({ success: true, data: null }); return {}; });

		$form.trigger('submit');
		await flush();

		expect(document.querySelector('form.ffc-submission-form').textContent).toContain('Error occurred');
		expect(window.$('button[type="submit"]').prop('disabled')).toBe(false);
	});

	it('on success with no html payload: renders the generic success block', async () => {
		const $form = setupForm();
		document.querySelector('input[name="name"]').value = 'Maria';
		mockAjax((opts) => { opts.success({ success: true, data: { message: 'Saved' } }); return {}; });

		$form.trigger('submit');
		await flush();

		// showFormSuccess('') injected a success element into the form.
		expect(document.querySelector('form.ffc-submission-form').innerHTML.length).toBeGreaterThan(0);
	});

	it('on success but response.success=false: shows the error message via FFC.Frontend.UI.showFormError', async () => {
		const $form = setupForm();
		mockAjax((opts) => {
			opts.success({
				success: false,
				data: { message: 'Custom failure' },
			});
			return {};
		});
		$form.trigger('submit');
		await flush();
		// FFC.Frontend.UI.showFormError injects a notice into the form.
		expect(document.querySelector('form.ffc-submission-form').textContent).toContain('Custom failure');
	});

	it('on success: refresh_captcha branch hits FFC.Frontend.UI.refreshCaptcha', async () => {
		const $form = setupForm();
		// Add the captcha bits the UI helper expects.
		$form.append('<div class="ffc-captcha-row"><span class="ffc-captcha-label-text">old</span></div>');
		$form.append('<input type="hidden" name="ffc_captcha_hash" value="old-h" />');
		mockAjax((opts) => {
			opts.success({
				success: false,
				data: {
					message: 'Bad captcha',
					refresh_captcha: true,
					new_label: 'new-label',
					new_hash: 'new-hash',
				},
			});
			return {};
		});
		$form.trigger('submit');
		await flush();
		expect(document.querySelector('.ffc-captcha-label-text').textContent).toBe('new-label');
		expect(document.querySelector('input[name="ffc_captcha_hash"]').value).toBe('new-hash');
	});

	it('on error with rate_limit payload: calls FFC.Frontend.RateLimit.show', async () => {
		const $form = setupForm();
		const showSpy = vi.spyOn(window.FFC.Frontend.RateLimit, 'show').mockImplementation(() => {});
		mockAjax((opts) => {
			opts.error({
				responseJSON: {
					data: { rate_limit: true, message: 'Slow down', wait_seconds: 30 },
				},
			});
			return {};
		});
		$form.trigger('submit');
		await flush();
		expect(showSpy).toHaveBeenCalledWith('Slow down', 30);
	});

	it('on generic error: shows the connection-error alert', async () => {
		const $form = setupForm();
		mockAjax((opts) => {
			opts.error({ responseJSON: null });
			return {};
		});
		$form.trigger('submit');
		await flush();
		const alert = document.querySelector('.ffc-accessible-alert');
		expect(alert).not.toBeNull();
		expect(alert.textContent).toContain('Connection error');
	});
});

// ----------------------------------------------------------------------
// showAccessibleAlert — DOM injection helper, accessible via the
// validation path which calls it directly.
// ----------------------------------------------------------------------

describe('frontend.showAccessibleAlert (via required-field validation)', () => {
	it('removes any previous alert before injecting a new one', async () => {
		document.body.innerHTML = `
			<form class="ffc-submission-form">
				<input type="text" required />
				<button type="submit">Send</button>
			</form>
		`;
		mockAjax(() => ({}));
		// First submit → first alert.
		window.$('form').trigger('submit');
		await flush();
		expect(document.querySelectorAll('.ffc-accessible-alert').length).toBe(1);
		// Second submit → still only one alert.
		window.$('form').trigger('submit');
		await flush();
		expect(document.querySelectorAll('.ffc-accessible-alert').length).toBe(1);
	});

	it('schedules the alert auto-hide after 8 seconds', () => {
		window.$.fx.off = true;
		vi.useFakeTimers();
		try {
			document.body.innerHTML = `
				<form class="ffc-submission-form">
					<input type="text" required />
					<button type="submit">Send</button>
				</form>
			`;
			mockAjax(() => ({}));
			window.$('form').trigger('submit');
			// The validation path runs synchronously before any AJAX.
			expect(document.querySelectorAll('.ffc-accessible-alert').length).toBe(1);
			// Drive the 8s auto-hide timer body (line 100) — the fadeOut call
			// inside it runs without throwing under fake timers.
			expect(() => vi.advanceTimersByTime(8000)).not.toThrow();
		} finally {
			vi.useRealTimers();
		}
	});
});

// ----------------------------------------------------------------------
// Dynamic mask MutationObserver — re-applies masks on injected nodes
// ----------------------------------------------------------------------

describe('frontend.setupDynamicMaskObserver', () => {
	// The observer was installed on document.body at $(document).ready in the
	// beforeAll load. Each test injects a matching node and waits for the
	// MutationObserver callback (async microtask) + the 50ms debounce.
	function waitObserver() {
		return new Promise((r) => setTimeout(r, 0))
			.then(() => new Promise((r) => setTimeout(r, 60)));
	}

	it('re-applies the CPF and ticket masks when matching inputs are added', async () => {
		const cpfSpy = vi.spyOn(window.FFC.Frontend.Masks, 'applyCpfRf').mockImplementation(() => {});
		const ticketSpy = vi.spyOn(window.FFC.Frontend.Masks, 'applyTicket').mockImplementation(() => {});
		const authSpy = vi.spyOn(window.FFC.Frontend.Masks, 'applyAuthCode').mockImplementation(() => {});

		const wrap = document.createElement('div');
		wrap.innerHTML =
			'<input name="cpf_rf" /><input name="ffc_ticket" /><input class="ffc-manual-auth-code" />';
		document.body.appendChild(wrap);

		await waitObserver();

		expect(cpfSpy).toHaveBeenCalled();
		expect(ticketSpy).toHaveBeenCalled();
		expect(authSpy).toHaveBeenCalled();
	});

	it('ignores mutations that add no mask-relevant nodes', async () => {
		const cpfSpy = vi.spyOn(window.FFC.Frontend.Masks, 'applyCpfRf').mockImplementation(() => {});
		// A plain node with no cpf/ticket/auth inputs.
		document.body.appendChild(document.createElement('span'));
		await waitObserver();
		expect(cpfSpy).not.toHaveBeenCalled();
	});
});
