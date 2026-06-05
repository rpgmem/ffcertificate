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
	// ffc-csv-download.js was migrated to FFC.request — load ffc-core.js
	// so window.FFC is defined when the subject IIFE evaluates.
	if (! window.FFC) {
		window.ffc_ajax = window.ffc_ajax || {
			ajax_url: window.ffc_csv_download && window.ffc_csv_download.ajax_url,
			nonce: '',
			strings: (window.ffc_csv_download && window.ffc_csv_download.strings) || {},
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

async function reachInfoScreen(infoOverride) {
	mountContainer();
	await loadAndReady();
	const postSpy = vi.spyOn(window.$, 'post');
	postSpy.mockImplementationOnce(() => postChain({ done: { success: true, data: infoOverride || infoWithEarlyOpen() } }));
	window.$('.ffc-public-csv-download form').trigger('submit');
	await flush();
	return postSpy;
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
		await flush();

		expect(window.$('.ffc-open-early-modal').length).toBe(1);
		expect(window.$('.ffc-open-early-modal').text()).toContain('Start form now?');
		expect(window.$('.ffc-open-early-modal').text()).toContain('2026-12-31 23:00');
		expect(window.$('.ffc-open-early-cancel').length).toBe(1);
		expect(window.$('.ffc-open-early-confirm').length).toBe(1);
	});

	it('closes the modal when Cancel is clicked', async () => {
		await reachInfoScreen();
		window.$('.ffc-btn-open-early').trigger('click');
		await flush();
		expect(window.$('.ffc-open-early-modal').length).toBe(1);

		window.$('.ffc-open-early-cancel').trigger('click');
		await flush();

		expect(window.$('.ffc-open-early-modal').length).toBe(0);
	});

	it('closes the modal when the backdrop is clicked', async () => {
		await reachInfoScreen();
		window.$('.ffc-btn-open-early').trigger('click');
		await flush();

		window.$('.ffc-open-early-backdrop').trigger('click');
		await flush();

		expect(window.$('.ffc-open-early-modal').length).toBe(0);
	});

	it('closes the modal when the header close (×) button is clicked', async () => {
		await reachInfoScreen();
		window.$('.ffc-btn-open-early').trigger('click');
		await flush();
		expect(window.$('.ffc-open-early-modal').length).toBe(1);
		expect(window.$('.ffc-open-early-close').length).toBe(1);

		window.$('.ffc-open-early-close').trigger('click');
		await flush();

		expect(window.$('.ffc-open-early-modal').length).toBe(0);
	});

	it('closes the modal on Escape key', async () => {
		await reachInfoScreen();
		window.$('.ffc-btn-open-early').trigger('click');
		await flush();
		expect(window.$('.ffc-open-early-modal').length).toBe(1);

		const ev = window.$.Event('keydown', { key: 'Escape' });
		window.$(document).trigger(ev);
		await flush();

		expect(window.$('.ffc-open-early-modal').length).toBe(0);
	});
});

// ----------------------------------------------------------------------
// Confirm → submit POSTs to ffc_public_open_early
// ----------------------------------------------------------------------

describe('csv-download — open-early submit', () => {
	it('POSTs ffc_public_open_early on confirm and reloads on success', async () => {
		const postSpy = await reachInfoScreen();
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		// Replace location with a stub before triggering reload.
		const original = window.location;
		const stub = { reload: vi.fn(), href: '/', pathname: '/', hostname: '' };
		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: stub,
		});

		postSpy.mockImplementation(() => postChain({ done: {
				success: true,
				data: { message: 'Form is now open.' },
			} }));

		window.$('.ffc-btn-open-early').trigger('click');
		await flush();
		window.$('.ffc-open-early-confirm').trigger('click');
		await flush();

		// FFC.request → jQuery.post(url, payload); payload is the second
		// positional arg.
		const lastPayload = postSpy.mock.calls[postSpy.mock.calls.length - 1][1];
		expect(lastPayload).toContain('action=ffc_public_open_early');
		expect(lastPayload).toContain('form_id=42');
		expect(lastPayload).toContain('hash=abc');
		// Old `action=ffc_public_csv_info` should not survive the rewrite.
		expect(lastPayload).not.toContain('action=ffc_public_csv_info');

		expect(alertSpy).toHaveBeenCalledWith('Form is now open.');
		expect(stub.reload).toHaveBeenCalled();

		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: original,
		});
	});

	it('alerts the server message and re-enables the button on failure', async () => {
		const postSpy = await reachInfoScreen();
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		postSpy.mockImplementation(() => postChain({ done: {
				success: false,
				data: { message: 'Already started.' },
			} }));

		window.$('.ffc-btn-open-early').trigger('click');
		await flush();
		window.$('.ffc-open-early-confirm').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('Already started.');
		expect(window.$('.ffc-btn-open-early').prop('disabled')).toBe(false);
		expect(window.$('.ffc-btn-open-early').text()).toContain('Start Form Now');
	});

	it('alerts and re-enables the button on AJAX network error', async () => {
		const postSpy = await reachInfoScreen();
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		postSpy.mockImplementation(() => postChain({ fail: true }));

		window.$('.ffc-btn-open-early').trigger('click');
		await flush();
		window.$('.ffc-open-early-confirm').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('Error');
		expect(window.$('.ffc-btn-open-early').prop('disabled')).toBe(false);
	});
});
