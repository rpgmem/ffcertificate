// Sprint C — deep coverage for `assets/js/ffc-csv-download.js` (668 LOC).
//
// The existing `csv-and-rereg-frontend.test.js` covers only the
// load-side smoke + a single submit-intercept test. This file drives
// the IIFE's private handlers through their full AJAX flow:
//
//   - onSubmitInfo: success → renderInfoScreen, response-not-success,
//     network error.
//   - Section builders via renderInfoScreen output (restrictions,
//     datetime with/without end date, geo, quiz, csv section).
//   - onDownloadClick: start → batch → done → iframe download +
//     overlay cleanup. Plus start-fails / batch-fails / batch-error.
//   - onCertPreviewClick: success → modal + close handlers (esc / X /
//     backdrop), failure path.
//   - buildSampleData / replacePlaceholders (covered indirectly via
//     showCertPreviewModal).
//   - goBack triggers location.reload.
//
// The IIFE doesn't expose any globals, so each test mounts the
// fixture, reloads the script, awaits ready(), then drives events.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
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


function mountContainer({ cpfField = false } = {}) {
	document.body.innerHTML = `
		<div class="ffc-public-csv-download">
			<div class="ffc-verification-header">header</div>
			<form>
				<input type="text" name="form_id" value="42" />
				<input type="text" name="hash" value="abc" />
				${cpfField ? '<input type="text" name="cpf" id="ffc-pcd-cpf" />' : ''}
				<button type="submit" class="ffc-submit-btn">Info</button>
			</form>
		</div>
	`;
}

function defaultInfo(overrides = {}) {
	return {
		form_title: 'My Form',
		submission_count: 7,
		restrictions: {
			password: true,
			allowlist: true,
			denylist: false,
			ticket: true,
		},
		datetime: {
			has_dates: true,
			has_times: true,
			date_start: '2026-01-01',
			date_end: '2026-12-31',
			time_start: '08:00',
			time_end: '18:00',
		},
		geolocation: { enabled: false },
		quiz: null,
		csv: { count: 0, limit: 100 },
		status: {
			has_end_date: true,
			can_preview_cert: true,
			can_download: true,
		},
		...overrides,
	};
}

async function loadAndReady() {
	// FFC.request — the migration target — lives on window.FFC, which is
	// initialised from window.ffc_ajax. Seed it from ffc_csv_download
	// so the helper's ajaxUrl matches what the test expects, then load
	// ffc-core before the subject script.
	if (! window.FFC) {
		window.ffc_ajax = window.ffc_ajax || {
			ajax_url: window.ffc_csv_download.ajax_url,
			nonce: '',
			strings: window.ffc_csv_download.strings || {},
		};
		loadScript('assets/js/ffc-core.js');
	}
	// Core (window.FFCCsv) first, then the flow modules that extend it.
	loadScript('assets/js/ffc-csv-download.js');
	loadScript('assets/js/ffc-csv-info-screen.js');
	loadScript('assets/js/ffc-csv-cert-preview.js');
	loadScript('assets/js/ffc-csv-download-flow.js');
	loadScript('assets/js/ffc-csv-open-early.js');
	loadScript('assets/js/ffc-csv-extend-end.js');
	loadScript('assets/js/ffc-csv-schedule-exception.js');
	await new Promise((r) => setTimeout(r, 0));
}

beforeEach(() => {
	window.ffc_csv_download = {
		ajax_url: '/wp-admin/admin-ajax.php',
		// The IIFE reads min_display_ms via `|| 1500` so 0 falls back to
		// the default. Use 1 to keep the wait essentially instant.
		min_display_ms: 1,
		strings: {
			validating: 'Validating…',
			generating: 'Generating CSV — %d records…',
			exporting: 'Exporting %1$d / %2$d…',
			complete: 'Download complete!',
			downloading: 'Downloading…',
			connError: 'Connection error.',
			error: 'Error',
			loadingPreview: 'Loading preview…',
			previewCertificate: 'Preview Certificate',
			downloadCsv: 'Download CSV',
			backToForm: 'Back',
			formDetails: 'Form Details',
			accessRestrictions: 'Access Restrictions',
			availability: 'Availability Period',
			passwordRequired: 'Password required',
			approvedUsersOnly: 'Approved users only',
			accessCodeRequired: 'Access code required',
			noEndDateAlert: 'No end date configured.',
		},
	};
	window.$.fx.off = true;
});

afterEach(() => {
	vi.restoreAllMocks();
	document.body.innerHTML = '';
});

// ----------------------------------------------------------------------
// onSubmitInfo AJAX result branches
// ----------------------------------------------------------------------

describe('csv-download — onSubmitInfo', () => {
	it('renders the info screen on AJAX success', async () => {
		mountContainer();
		await loadAndReady();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: defaultInfo() } }));

		window.$('.ffc-public-csv-download form').trigger('submit');
		await flush();

		// Info screen rendered.
		expect(window.$('.ffc-info-screen').length).toBe(1);
		expect(window.$('.ffc-info-screen').text()).toContain('My Form');
		expect(window.$('.ffc-info-screen').text()).toContain('7');
		// Restrictions section rendered with active items.
		expect(window.$('.ffc-info-list-restrictions li').length).toBe(3);
		// Download + preview buttons present.
		expect(window.$('.ffc-btn-download-csv').length).toBe(1);
		expect(window.$('.ffc-btn-cert-preview').length).toBe(1);
	});

	it('shows a flash error when response.success=false', async () => {
		mountContainer();
		await loadAndReady();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Bad hash' } } }));

		window.$('.ffc-public-csv-download form').trigger('submit');
		await flush();

		expect(window.$('.ffc-pcd-message').text()).toContain('Bad hash');
		// Info screen not rendered.
		expect(window.$('.ffc-info-screen').length).toBe(0);
	});

	it('shows the connection error on AJAX network failure', async () => {
		mountContainer();
		await loadAndReady();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));

		window.$('.ffc-public-csv-download form').trigger('submit');
		await flush();

		expect(window.$('.ffc-pcd-message').text()).toContain('Connection error');
	});

	it('disables the submit button while validating', async () => {
		mountContainer();
		await loadAndReady();
		// Never call success/error so the spinner stays.
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		window.$('.ffc-public-csv-download form').trigger('submit');
		await flush();

		expect(window.$('.ffc-submit-btn').prop('disabled')).toBe(true);
		expect(window.$('.ffc-submit-btn').hasClass('ffc-btn-loading')).toBe(true);
	});

	it('renders the no-end-date alert when status.has_end_date is false', async () => {
		mountContainer();
		await loadAndReady();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: defaultInfo({
					status: { has_end_date: false, can_preview_cert: false, can_download: false },
				}),
			} }));

		window.$('.ffc-public-csv-download form').trigger('submit');
		await flush();

		expect(window.$('.ffc-info-alert-warning').text()).toContain('No end date');
	});

	it('renders the disabled download button when status.can_download is false', async () => {
		mountContainer();
		await loadAndReady();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: defaultInfo({
					status: { has_end_date: true, can_preview_cert: false, can_download: false },
				}),
			} }));

		window.$('.ffc-public-csv-download form').trigger('submit');
		await flush();

		expect(window.$('.ffc-btn-download-csv').prop('disabled')).toBe(true);
		expect(window.$('.ffc-btn-cert-preview').length).toBe(0);
	});
});

// ----------------------------------------------------------------------
// Download flow
// ----------------------------------------------------------------------

describe('csv-download — download flow', () => {
	async function reachInfoScreen() {
		mountContainer();
		await loadAndReady();
		const postSpy = vi.spyOn(window.$, 'ajax');
		// First call: info
		postSpy.mockImplementationOnce(() => postChain({ done: { success: true, data: defaultInfo() } }));
		window.$('.ffc-public-csv-download form').trigger('submit');
		await flush();
		return postSpy;
	}

	it('walks start → batch (in progress) → batch (done) and shows complete', async () => {
		const postSpy = await reachInfoScreen();

		// Subsequent calls: start, batch1, batch2 (done).
		const responses = [
			{ success: true, data: { job_id: 'j-1', nonce_batch: 'nb', total: 100 } },
			{ success: true, data: { processed: 50, total: 100, done: false } },
			{ success: true, data: { processed: 100, total: 100, done: true } },
		];
		postSpy.mockImplementation(() => postChain({ done: responses.shift() }));

		window.$('.ffc-btn-download-csv').trigger('click');
		await flush();

		// All 3 AJAX calls fired synchronously.
		expect(postSpy.mock.calls.length).toBeGreaterThanOrEqual(4);
		// onExportComplete schedules the final "Download complete!" status
		// inside a setTimeout(remaining). Flush it.
		await new Promise((r) => setTimeout(r, 5));
		expect(window.$('.ffc-csv-progress-status').text()).toContain('Download complete!');
		// Iframe injected.
		expect(window.$('iframe[src*="ffc_public_csv_download"]').length).toBe(1);
	});

	it('shows the error overlay when start returns success=false', async () => {
		const postSpy = await reachInfoScreen();
		postSpy.mockImplementation(() => postChain({ done: { success: false, data: { message: 'Quota' } } }));

		window.$('.ffc-btn-download-csv').trigger('click');
		await flush();

		expect(window.$('.ffc-csv-progress-error').text()).toContain('Quota');
	});

	it('shows the error overlay on start network failure', async () => {
		const postSpy = await reachInfoScreen();
		postSpy.mockImplementation(() => postChain({ fail: true }));

		window.$('.ffc-btn-download-csv').trigger('click');
		await flush();

		expect(window.$('.ffc-csv-progress-error').text()).toContain('Connection error');
	});

	it('shows the error overlay when a batch returns success=false (string data)', async () => {
		const postSpy = await reachInfoScreen();
		const responses = [
			{ success: true, data: { job_id: 'j-1', nonce_batch: 'nb', total: 10 } },
			{ success: false, data: 'Batch failed' },
		];
		postSpy.mockImplementation(() => postChain({ done: responses.shift() }));

		window.$('.ffc-btn-download-csv').trigger('click');
		await flush();

		expect(window.$('.ffc-csv-progress-error').text()).toBe('Batch failed');
	});

	it('shows the error overlay when a batch returns success=false (object data)', async () => {
		const postSpy = await reachInfoScreen();
		const responses = [
			{ success: true, data: { job_id: 'j-1', nonce_batch: 'nb', total: 10 } },
			{ success: false, data: { message: 'Batch object failure' } },
		];
		postSpy.mockImplementation(() => postChain({ done: responses.shift() }));

		window.$('.ffc-btn-download-csv').trigger('click');
		await flush();

		expect(window.$('.ffc-csv-progress-error').text()).toBe('Batch object failure');
	});

	it('shows the error overlay on batch network failure', async () => {
		const postSpy = await reachInfoScreen();
		const responses = [
			{ success: true, data: { job_id: 'j-1', nonce_batch: 'nb', total: 10 } },
		];
		postSpy.mockImplementation((opts) => {
			if (responses.length) {
				opts.success(responses.shift());
			} else {
				opts.error();
			}
		});

		window.$('.ffc-btn-download-csv').trigger('click');
		await flush();

		expect(window.$('.ffc-csv-progress-error').text()).toContain('Connection error');
	});
});

// ----------------------------------------------------------------------
// Cert preview flow
// ----------------------------------------------------------------------

describe('csv-download — cert preview', () => {
	async function reachInfoScreen() {
		mountContainer();
		await loadAndReady();
		const postSpy = vi.spyOn(window.$, 'ajax');
		postSpy.mockImplementationOnce(() => postChain({ done: { success: true, data: defaultInfo() } }));
		window.$('.ffc-public-csv-download form').trigger('submit');
		await flush();
		return postSpy;
	}

	it('opens the modal on preview success and substitutes placeholders', async () => {
		const postSpy = await reachInfoScreen();
		postSpy.mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					html: '<p>Hi {{name}} — your auth: {{auth_code}}.</p><div>{{qr_code}}</div>',
					bg_image: 'https://example.com/bg.png',
					fields: [],
					// Mirrors the PHP CertificatePreviewSamples::get_map()
					// payload the endpoint now returns.
					previewSamples: { name: 'John Doe', auth_code: 'A1B2-C3D4-E5F6' },
				},
			} }));

		window.$('.ffc-btn-cert-preview').trigger('click');
		await flush();

		expect(window.$('#ffc-preview-modal').length).toBe(1);
		// The iframe content is the substituted HTML.
		const iframe = document.querySelector('#ffc-preview-iframe');
		const doc = iframe.contentDocument;
		const body = doc.body.innerHTML;
		expect(body).toContain('John Doe');
		expect(body).toContain('A1B2-C3D4-E5F6');
		// QR placeholder swapped for an SVG.
		expect(body).toContain('<svg');
	});

	it('overlays form fields onto the PHP sample map', async () => {
		const postSpy = await reachInfoScreen();
		postSpy.mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					html: '<p>{{site_name}} — {{course_name}}</p>',
					fields: [{ name: 'course_name', label: 'Curso de Exemplo' }],
					previewSamples: { site_name: 'Sample Site' },
				},
			} }));

		window.$('.ffc-btn-cert-preview').trigger('click');
		await flush();

		const iframe = document.querySelector('#ffc-preview-iframe');
		const body = iframe.contentDocument.body.innerHTML;
		expect(body).toContain('Sample Site');
		expect(body).toContain('Curso de Exemplo');
		expect(body).not.toContain('{{course_name}}');
	});

	it('shows alert on preview failure', async () => {
		const postSpy = await reachInfoScreen();
		postSpy.mockImplementation(() => postChain({ done: { success: false, data: { message: 'No template' } } }));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-btn-cert-preview').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('No template');
		// Modal not opened.
		expect(window.$('#ffc-preview-modal').length).toBe(0);
	});

	it('shows alert on preview network error', async () => {
		const postSpy = await reachInfoScreen();
		postSpy.mockImplementation(() => postChain({ fail: true }));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-btn-cert-preview').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('Connection error.');
	});

	it('closes the modal on the close button click', async () => {
		const postSpy = await reachInfoScreen();
		postSpy.mockImplementation(() => postChain({ done: { success: true, data: { html: '<p>hi</p>', fields: [] } } }));

		window.$('.ffc-btn-cert-preview').trigger('click');
		await flush();
		expect(window.$('#ffc-preview-modal').length).toBe(1);

		window.$('.ffc-preview-close').trigger('click');
		await flush();
		// Animation-out finishes synchronously since fx.off=true; the
		// setTimeout(200) still runs but the visible-class drop is enough
		// for our assertion.
		expect(window.$('#ffc-preview-modal').hasClass('ffc-preview-visible')).toBe(false);
	});

	it('closes the modal when Escape is pressed', async () => {
		const postSpy = await reachInfoScreen();
		postSpy.mockImplementation(() => postChain({ done: { success: true, data: { html: '<p>hi</p>', fields: [] } } }));

		window.$('.ffc-btn-cert-preview').trigger('click');
		await flush();
		expect(window.$('#ffc-preview-modal').length).toBe(1);

		const ev = window.$.Event('keydown', { key: 'Escape' });
		window.$(document).trigger(ev);
		await flush();

		expect(window.$('#ffc-preview-modal').hasClass('ffc-preview-visible')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// goBack — back button triggers location reload
// ----------------------------------------------------------------------

describe('csv-download — back button', () => {
	it('calls location.reload() when clicked', async () => {
		mountContainer();
		await loadAndReady();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: defaultInfo() } }));
		window.$('.ffc-public-csv-download form').trigger('submit');
		await flush();

		// Replace location with a stub before clicking.
		const original = window.location;
		const stub = { reload: vi.fn(), href: '/', pathname: '/', hostname: '' };
		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: stub,
		});

		window.$('.ffc-info-back').trigger('click');
		await flush();

		expect(stub.reload).toHaveBeenCalled();

		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: original,
		});
	});
});

// ----------------------------------------------------------------------
// CPF mask auto-binding via FFC.Frontend.Masks.applyCpfRf
// ----------------------------------------------------------------------

describe('csv-download — CPF mask helper integration', () => {
	it('calls FFC.Frontend.Masks.applyCpfRf on init when a cpf input is present', async () => {
		mountContainer({ cpfField: true });
		// Stub the global the IIFE checks for.
		window.FFC = window.FFC || {};
		window.FFC.Frontend = window.FFC.Frontend || {};
		window.FFC.Frontend.Masks = { applyCpfRf: vi.fn() };

		await loadAndReady();

		expect(window.FFC.Frontend.Masks.applyCpfRf).toHaveBeenCalled();
		// Argument is the jQuery wrapper of the cpf input(s).
		const arg = window.FFC.Frontend.Masks.applyCpfRf.mock.calls[0][0];
		expect(arg.length).toBe(1);
		expect(arg.attr('name')).toBe('cpf');
	});
});

// ----------------------------------------------------------------------
// Schedule exception modal (#366 Sprint 4)
// ----------------------------------------------------------------------

describe('csv-download — schedule exception modal', () => {
	function infoWithException(overrides = {}) {
		return defaultInfo({
			status: {
				has_end_date: true,
				can_preview_cert: true,
				can_download: true,
				can_schedule_exception: true,
				schedule_baseline_start: '08:00',
				schedule_baseline_end:   '18:00',
				schedule_window_start:   '08:00',
				schedule_window_end:     '18:00',
				schedule_default_mode:   'manual',
				...overrides,
			},
		});
	}

	async function renderInfoWith(info) {
		mountContainer();
		await loadAndReady();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: info } }));
		window.$('.ffc-public-csv-download form').trigger('submit');
		await flush();
	}

	it('renders the schedule-exception button only when can_schedule_exception=true', async () => {
		await renderInfoWith(defaultInfo());
		expect(window.$('.ffc-btn-schedule-exception').length).toBe(0);

		document.body.innerHTML = '';
		await renderInfoWith(infoWithException());
		expect(window.$('.ffc-btn-schedule-exception').length).toBe(1);
	});

	it('opens the modal on button click with baseline values pre-filled', async () => {
		await renderInfoWith(infoWithException());

		window.$('.ffc-btn-schedule-exception').trigger('click');

		expect(window.$('.ffc-schedule-exception-modal').length).toBe(1);
		expect(window.$('.ffc-sched-exc-start').val()).toBe('08:00');
		expect(window.$('.ffc-sched-exc-end').val()).toBe('18:00');
	});

	it('blocks submit and shows error when override matches the baseline', async () => {
		await renderInfoWith(infoWithException());
		window.$('.ffc-btn-schedule-exception').trigger('click');
		// Leave the inputs at their baseline values, click confirm.
		const postSpy = vi.spyOn(window.$, 'post');
		postSpy.mockClear();
		window.$('.ffc-sched-exc-confirm').trigger('click');

		// No AJAX call fired.
		expect(postSpy).not.toHaveBeenCalled();
		// Error visible.
		const $err = window.$('.ffc-sched-exc-error');
		expect($err.attr('hidden')).toBeFalsy();
		expect($err.text().length).toBeGreaterThan(0);
	});

	it('blocks submit when the range is inverted', async () => {
		await renderInfoWith(infoWithException());
		window.$('.ffc-btn-schedule-exception').trigger('click');
		window.$('.ffc-sched-exc-start').val('17:00');
		window.$('.ffc-sched-exc-end').val('09:00');

		const postSpy = vi.spyOn(window.$, 'post');
		postSpy.mockClear();
		window.$('.ffc-sched-exc-confirm').trigger('click');

		expect(postSpy).not.toHaveBeenCalled();
		const $err = window.$('.ffc-sched-exc-error');
		expect($err.attr('hidden')).toBeFalsy();
	});

	it('on AJAX success, swaps modal body to the "Open participant form" CTA pointing at form_url', async () => {
		await renderInfoWith(infoWithException());
		window.$('.ffc-btn-schedule-exception').trigger('click');

		// Type a valid override different from baseline.
		window.$('.ffc-sched-exc-start').val('09:00');
		window.$('.ffc-sched-exc-end').val('17:00');

		// Mock the AJAX call to succeed with a form_url.
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({
			done: { success: true, data: { token: 'abc.def', form_url: 'https://example.test/landing' } },
		}));

		window.$('.ffc-sched-exc-confirm').trigger('click');
		await flush();

		const $cta = window.$('.ffc-sched-exc-open');
		expect($cta.length).toBe(1);
		expect($cta.attr('href')).toBe('https://example.test/landing');
		expect($cta.attr('target')).toBe('_blank');
		expect($cta.attr('rel')).toBe('noopener');
	});

	it('on AJAX failure, restores the confirm button and surfaces the error', async () => {
		await renderInfoWith(infoWithException());
		window.$('.ffc-btn-schedule-exception').trigger('click');
		window.$('.ffc-sched-exc-start').val('09:00');
		window.$('.ffc-sched-exc-end').val('17:00');

		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({
			fail: true,
		}));

		window.$('.ffc-sched-exc-confirm').trigger('click');
		await flush();

		// Modal still present, confirm button re-enabled.
		expect(window.$('.ffc-schedule-exception-modal').length).toBe(1);
		expect(window.$('.ffc-sched-exc-confirm').prop('disabled')).toBe(false);
		const $err = window.$('.ffc-sched-exc-error');
		expect($err.attr('hidden')).toBeFalsy();
	});

	it('closes the modal when Escape is pressed', async () => {
		await renderInfoWith(infoWithException());
		window.$('.ffc-btn-schedule-exception').trigger('click');
		expect(window.$('.ffc-schedule-exception-modal').length).toBe(1);

		const ev = new window.$.Event('keydown', { key: 'Escape' });
		window.$(document).trigger(ev);

		expect(window.$('.ffc-schedule-exception-modal').length).toBe(0);
	});
});
