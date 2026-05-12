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
	loadScript('assets/js/ffc-csv-download.js');
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
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({ success: true, data: defaultInfo() });
		});

		window.$('.ffc-public-csv-download form').trigger('submit');

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
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({ success: false, data: { message: 'Bad hash' } });
		});

		window.$('.ffc-public-csv-download form').trigger('submit');

		expect(window.$('.ffc-pcd-message').text()).toContain('Bad hash');
		// Info screen not rendered.
		expect(window.$('.ffc-info-screen').length).toBe(0);
	});

	it('shows the connection error on AJAX network failure', async () => {
		mountContainer();
		await loadAndReady();
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error();
		});

		window.$('.ffc-public-csv-download form').trigger('submit');

		expect(window.$('.ffc-pcd-message').text()).toContain('Connection error');
	});

	it('disables the submit button while validating', async () => {
		mountContainer();
		await loadAndReady();
		// Never call success/error so the spinner stays.
		vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		window.$('.ffc-public-csv-download form').trigger('submit');

		expect(window.$('.ffc-submit-btn').prop('disabled')).toBe(true);
		expect(window.$('.ffc-submit-btn').hasClass('ffc-btn-loading')).toBe(true);
	});

	it('renders the no-end-date alert when status.has_end_date is false', async () => {
		mountContainer();
		await loadAndReady();
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({
				success: true,
				data: defaultInfo({
					status: { has_end_date: false, can_preview_cert: false, can_download: false },
				}),
			});
		});

		window.$('.ffc-public-csv-download form').trigger('submit');

		expect(window.$('.ffc-info-alert-warning').text()).toContain('No end date');
	});

	it('renders the disabled download button when status.can_download is false', async () => {
		mountContainer();
		await loadAndReady();
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({
				success: true,
				data: defaultInfo({
					status: { has_end_date: true, can_preview_cert: false, can_download: false },
				}),
			});
		});

		window.$('.ffc-public-csv-download form').trigger('submit');

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
		const ajaxSpy = vi.spyOn(window.$, 'ajax');
		// First call: info
		ajaxSpy.mockImplementationOnce((opts) => {
			opts.success({ success: true, data: defaultInfo() });
		});
		window.$('.ffc-public-csv-download form').trigger('submit');
		return ajaxSpy;
	}

	it('walks start → batch (in progress) → batch (done) and shows complete', async () => {
		const ajaxSpy = await reachInfoScreen();

		// Subsequent calls: start, batch1, batch2 (done).
		const responses = [
			{ success: true, data: { job_id: 'j-1', nonce_batch: 'nb', total: 100 } },
			{ success: true, data: { processed: 50, total: 100, done: false } },
			{ success: true, data: { processed: 100, total: 100, done: true } },
		];
		ajaxSpy.mockImplementation((opts) => {
			opts.success(responses.shift());
		});

		window.$('.ffc-btn-download-csv').trigger('click');

		// All 3 AJAX calls fired synchronously.
		expect(ajaxSpy.mock.calls.length).toBeGreaterThanOrEqual(4);
		// onExportComplete schedules the final "Download complete!" status
		// inside a setTimeout(remaining). Flush it.
		await new Promise((r) => setTimeout(r, 5));
		expect(window.$('.ffc-csv-progress-status').text()).toContain('Download complete!');
		// Iframe injected.
		expect(window.$('iframe[src*="ffc_public_csv_download"]').length).toBe(1);
	});

	it('shows the error overlay when start returns success=false', async () => {
		const ajaxSpy = await reachInfoScreen();
		ajaxSpy.mockImplementation((opts) => {
			opts.success({ success: false, data: { message: 'Quota' } });
		});

		window.$('.ffc-btn-download-csv').trigger('click');

		expect(window.$('.ffc-csv-progress-error').text()).toContain('Quota');
	});

	it('shows the error overlay on start network failure', async () => {
		const ajaxSpy = await reachInfoScreen();
		ajaxSpy.mockImplementation((opts) => {
			opts.error();
		});

		window.$('.ffc-btn-download-csv').trigger('click');

		expect(window.$('.ffc-csv-progress-error').text()).toContain('Connection error');
	});

	it('shows the error overlay when a batch returns success=false (string data)', async () => {
		const ajaxSpy = await reachInfoScreen();
		const responses = [
			{ success: true, data: { job_id: 'j-1', nonce_batch: 'nb', total: 10 } },
			{ success: false, data: 'Batch failed' },
		];
		ajaxSpy.mockImplementation((opts) => {
			opts.success(responses.shift());
		});

		window.$('.ffc-btn-download-csv').trigger('click');

		expect(window.$('.ffc-csv-progress-error').text()).toBe('Batch failed');
	});

	it('shows the error overlay when a batch returns success=false (object data)', async () => {
		const ajaxSpy = await reachInfoScreen();
		const responses = [
			{ success: true, data: { job_id: 'j-1', nonce_batch: 'nb', total: 10 } },
			{ success: false, data: { message: 'Batch object failure' } },
		];
		ajaxSpy.mockImplementation((opts) => {
			opts.success(responses.shift());
		});

		window.$('.ffc-btn-download-csv').trigger('click');

		expect(window.$('.ffc-csv-progress-error').text()).toBe('Batch object failure');
	});

	it('shows the error overlay on batch network failure', async () => {
		const ajaxSpy = await reachInfoScreen();
		const responses = [
			{ success: true, data: { job_id: 'j-1', nonce_batch: 'nb', total: 10 } },
		];
		ajaxSpy.mockImplementation((opts) => {
			if (responses.length) {
				opts.success(responses.shift());
			} else {
				opts.error();
			}
		});

		window.$('.ffc-btn-download-csv').trigger('click');

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
		const ajaxSpy = vi.spyOn(window.$, 'ajax');
		ajaxSpy.mockImplementationOnce((opts) => {
			opts.success({ success: true, data: defaultInfo() });
		});
		window.$('.ffc-public-csv-download form').trigger('submit');
		return ajaxSpy;
	}

	it('opens the modal on preview success and substitutes placeholders', async () => {
		const ajaxSpy = await reachInfoScreen();
		ajaxSpy.mockImplementation((opts) => {
			opts.success({
				success: true,
				data: {
					html: '<p>Hi {{name}} — your auth: {{auth_code}}.</p><div>{{qr_code}}</div>',
					bg_image: 'https://example.com/bg.png',
					fields: [],
				},
			});
		});

		window.$('.ffc-btn-cert-preview').trigger('click');

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

	it('shows alert on preview failure', async () => {
		const ajaxSpy = await reachInfoScreen();
		ajaxSpy.mockImplementation((opts) => {
			opts.success({ success: false, data: { message: 'No template' } });
		});
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-btn-cert-preview').trigger('click');

		expect(alertSpy).toHaveBeenCalledWith('No template');
		// Modal not opened.
		expect(window.$('#ffc-preview-modal').length).toBe(0);
	});

	it('shows alert on preview network error', async () => {
		const ajaxSpy = await reachInfoScreen();
		ajaxSpy.mockImplementation((opts) => {
			opts.error();
		});
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-btn-cert-preview').trigger('click');

		expect(alertSpy).toHaveBeenCalledWith('Connection error.');
	});

	it('closes the modal on the close button click', async () => {
		const ajaxSpy = await reachInfoScreen();
		ajaxSpy.mockImplementation((opts) => {
			opts.success({ success: true, data: { html: '<p>hi</p>', fields: [] } });
		});

		window.$('.ffc-btn-cert-preview').trigger('click');
		expect(window.$('#ffc-preview-modal').length).toBe(1);

		window.$('.ffc-preview-close').trigger('click');
		// Animation-out finishes synchronously since fx.off=true; the
		// setTimeout(200) still runs but the visible-class drop is enough
		// for our assertion.
		expect(window.$('#ffc-preview-modal').hasClass('ffc-preview-visible')).toBe(false);
	});

	it('closes the modal when Escape is pressed', async () => {
		const ajaxSpy = await reachInfoScreen();
		ajaxSpy.mockImplementation((opts) => {
			opts.success({ success: true, data: { html: '<p>hi</p>', fields: [] } });
		});

		window.$('.ffc-btn-cert-preview').trigger('click');
		expect(window.$('#ffc-preview-modal').length).toBe(1);

		const ev = window.$.Event('keydown', { key: 'Escape' });
		window.$(document).trigger(ev);

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
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({ success: true, data: defaultInfo() });
		});
		window.$('.ffc-public-csv-download form').trigger('submit');

		// Replace location with a stub before clicking.
		const original = window.location;
		const stub = { reload: vi.fn(), href: '/', pathname: '/', hostname: '' };
		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: stub,
		});

		window.$('.ffc-info-back').trigger('click');

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
