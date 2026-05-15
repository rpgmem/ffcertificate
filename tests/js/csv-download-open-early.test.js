// Sprint 8 — coverage for the "Start Form Now" (early-open) flow in
// `assets/js/ffc-csv-download.js`.
//
// Walks: button render gating → confirmation modal opens → Cancel /
// Esc / backdrop close paths → confirm → AJAX success (alert + reload)
// → AJAX failure (alert + button restore).
//
// Mirrors the fixture style of `csv-download-deep.test.js`.

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function mountContainer() {
	document.body.innerHTML = `
		<div class="ffc-public-csv-download">
			<div class="ffc-verification-header">header</div>
			<form>
				<input type="text" name="form_id" value="42" />
				<input type="text" name="hash" value="abc" />
				<input type="hidden" name="_ffc_pcd_nonce" value="n" />
				<button type="submit" class="ffc-submit-btn">Info</button>
			</form>
		</div>
	`;
}

function infoWithEarlyOpen(overrides = {}) {
	return {
		form_title: 'My Form',
		submission_count: 0,
		restrictions: {},
		datetime: { has_dates: false, has_times: false },
		geolocation: { enabled: false },
		quiz: null,
		csv: { count: 0, limit: 100 },
		status: {
			has_end_date: true,
			can_preview_cert: true,
			can_download: false,
			can_open_early: true,
			start_date_formatted: '2026-12-31 23:00',
			...(overrides.status || {}),
		},
		...overrides,
	};
}

async function loadAndReady() {
	loadScript('assets/js/ffc-csv-download.js');
	await new Promise((r) => setTimeout(r, 0));
}

async function reachInfoScreen(infoOverride) {
	mountContainer();
	await loadAndReady();
	const ajaxSpy = vi.spyOn(window.$, 'ajax');
	ajaxSpy.mockImplementationOnce((opts) => {
		opts.success({ success: true, data: infoOverride || infoWithEarlyOpen() });
	});
	window.$('.ffc-public-csv-download form').trigger('submit');
	return ajaxSpy;
}

beforeEach(() => {
	window.ffc_csv_download = {
		ajax_url: '/wp-admin/admin-ajax.php',
		min_display_ms: 1,
		strings: {
			validating: 'Validating…',
			generating: 'Generating CSV — %d records…',
			exporting: 'Exporting %1$d / %2$d…',
			complete: 'Done!',
			downloading: 'Downloading…',
			connError: 'Connection error.',
			error: 'Error',
			loadingPreview: 'Loading…',
			previewCertificate: 'Preview Certificate',
			downloadCsv: 'Download CSV',
			backToForm: 'Back',
			formDetails: 'Form Details',
			accessRestrictions: 'Access Restrictions',
			availability: 'Availability',
			passwordRequired: 'Password',
			approvedUsersOnly: 'Approved',
			accessCodeRequired: 'Code',
			noEndDateAlert: 'No end date',
			startFormNow: 'Start Form Now',
			openEarlyTooltip: 'Override schedule.',
			openEarlyTitle: 'Start form now?',
			openEarlyBody1: 'Override warning body.',
			openEarlyOrigLabel: 'Scheduled start:',
			openEarlyNewLabel: 'New start will be:',
			openEarlyNewNow: 'now',
			openEarlyIrreversible: 'Cannot undo.',
			openEarlyCacheWarn: 'Cache warning.',
			cancel: 'Cancel',
			openEarlyConfirm: 'Confirm and start form',
			starting: 'Starting…',
			openEarlySuccess: 'Form is now open.',
		},
	};
	window.$.fx.off = true;
});

afterEach(() => {
	vi.restoreAllMocks();
	document.body.innerHTML = '';
	// Strip any leftover modal nodes.
	document.querySelectorAll('.ffc-open-early-modal').forEach((n) => n.remove());
});

// ----------------------------------------------------------------------
// Button render gating
// ----------------------------------------------------------------------

describe('csv-download — Start Form Now button render', () => {
	it('renders the button when status.can_open_early is true', async () => {
		await reachInfoScreen();
		expect(window.$('.ffc-btn-open-early').length).toBe(1);
		expect(window.$('.ffc-btn-open-early').text()).toContain('Start Form Now');
		expect(window.$('.ffc-btn-open-early').attr('title')).toContain('Override schedule.');
	});

	it('omits the button when status.can_open_early is false', async () => {
		await reachInfoScreen(
			infoWithEarlyOpen({ status: { can_open_early: false } })
		);
		expect(window.$('.ffc-btn-open-early').length).toBe(0);
	});
});

// ----------------------------------------------------------------------
// Modal — open + close paths
// ----------------------------------------------------------------------

describe('csv-download — open-early modal', () => {
	it('opens the confirmation modal with the original start time', async () => {
		await reachInfoScreen();

		window.$('.ffc-btn-open-early').trigger('click');

		expect(window.$('.ffc-open-early-modal').length).toBe(1);
		expect(window.$('.ffc-open-early-modal').text()).toContain('Start form now?');
		expect(window.$('.ffc-open-early-modal').text()).toContain('2026-12-31 23:00');
		expect(window.$('.ffc-open-early-cancel').length).toBe(1);
		expect(window.$('.ffc-open-early-confirm').length).toBe(1);
	});

	it('closes the modal when Cancel is clicked', async () => {
		await reachInfoScreen();
		window.$('.ffc-btn-open-early').trigger('click');
		expect(window.$('.ffc-open-early-modal').length).toBe(1);

		window.$('.ffc-open-early-cancel').trigger('click');

		expect(window.$('.ffc-open-early-modal').length).toBe(0);
	});

	it('closes the modal when the backdrop is clicked', async () => {
		await reachInfoScreen();
		window.$('.ffc-btn-open-early').trigger('click');

		window.$('.ffc-open-early-backdrop').trigger('click');

		expect(window.$('.ffc-open-early-modal').length).toBe(0);
	});

	it('closes the modal when the header close (×) button is clicked', async () => {
		await reachInfoScreen();
		window.$('.ffc-btn-open-early').trigger('click');
		expect(window.$('.ffc-open-early-modal').length).toBe(1);
		expect(window.$('.ffc-open-early-close').length).toBe(1);

		window.$('.ffc-open-early-close').trigger('click');

		expect(window.$('.ffc-open-early-modal').length).toBe(0);
	});

	it('closes the modal on Escape key', async () => {
		await reachInfoScreen();
		window.$('.ffc-btn-open-early').trigger('click');
		expect(window.$('.ffc-open-early-modal').length).toBe(1);

		const ev = window.$.Event('keydown', { key: 'Escape' });
		window.$(document).trigger(ev);

		expect(window.$('.ffc-open-early-modal').length).toBe(0);
	});
});

// ----------------------------------------------------------------------
// Confirm → submit POSTs to ffc_public_open_early
// ----------------------------------------------------------------------

describe('csv-download — open-early submit', () => {
	it('POSTs ffc_public_open_early on confirm and reloads on success', async () => {
		const ajaxSpy = await reachInfoScreen();
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		// Replace location with a stub before triggering reload.
		const original = window.location;
		const stub = { reload: vi.fn(), href: '/', pathname: '/', hostname: '' };
		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: stub,
		});

		ajaxSpy.mockImplementation((opts) => {
			opts.success({
				success: true,
				data: { message: 'Form is now open.' },
			});
		});

		window.$('.ffc-btn-open-early').trigger('click');
		window.$('.ffc-open-early-confirm').trigger('click');

		const lastCall = ajaxSpy.mock.calls[ajaxSpy.mock.calls.length - 1][0];
		expect(lastCall.type).toBe('POST');
		expect(lastCall.data).toContain('action=ffc_public_open_early');
		expect(lastCall.data).toContain('form_id=42');
		expect(lastCall.data).toContain('hash=abc');
		// Old `action=ffc_public_csv_info` should not survive the rewrite.
		expect(lastCall.data).not.toContain('action=ffc_public_csv_info');

		expect(alertSpy).toHaveBeenCalledWith('Form is now open.');
		expect(stub.reload).toHaveBeenCalled();

		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: original,
		});
	});

	it('alerts the server message and re-enables the button on failure', async () => {
		const ajaxSpy = await reachInfoScreen();
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		ajaxSpy.mockImplementation((opts) => {
			opts.success({
				success: false,
				data: { message: 'Already started.' },
			});
		});

		window.$('.ffc-btn-open-early').trigger('click');
		window.$('.ffc-open-early-confirm').trigger('click');

		expect(alertSpy).toHaveBeenCalledWith('Already started.');
		expect(window.$('.ffc-btn-open-early').prop('disabled')).toBe(false);
		expect(window.$('.ffc-btn-open-early').text()).toContain('Start Form Now');
	});

	it('alerts and re-enables the button on AJAX network error', async () => {
		const ajaxSpy = await reachInfoScreen();
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		ajaxSpy.mockImplementation((opts) => {
			opts.error();
		});

		window.$('.ffc-btn-open-early').trigger('click');
		window.$('.ffc-open-early-confirm').trigger('click');

		expect(alertSpy).toHaveBeenCalledWith('Error');
		expect(window.$('.ffc-btn-open-early').prop('disabled')).toBe(false);
	});
});
