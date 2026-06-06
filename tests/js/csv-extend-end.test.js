// Coverage for assets/js/ffc-csv-extend-end.js — the public CSV download
// "Postpone close" (extend-end) flow: time-picker modal with client-side
// validation, then ffc_public_extend_end via FFC.request.
//
// Mirrors the fixture style of csv-download-open-early.test.js — reaches
// the info screen with status.can_extend_end, then drives the modal.

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// FFC.request wraps jQuery.post() in a Promise. Mock $.post and return a
// chain whose .done / .fail callback the FFC.request internals invoke.
function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(); return chain; };
	return chain;
}

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

function infoWithExtendEnd(overrides = {}) {
	const { status: statusOverride, ...rest } = overrides;
	return {
		form_title: 'My Form',
		submission_count: 0,
		restrictions: {},
		datetime: { has_dates: false, has_times: false },
		geolocation: { enabled: false },
		quiz: null,
		csv: { count: 0, limit: 100 },
		...rest,
		// status merged LAST so a partial status override doesn't clobber
		// the defaults (e.g. can_extend_end must survive).
		status: {
			has_end_date: true,
			can_preview_cert: true,
			can_download: false,
			can_extend_end: true,
			current_date_end_formatted: '2026-12-31',
			current_time_end: '18:00',
			end_date_formatted: '2026-12-31 18:00',
			...(statusOverride || {}),
		},
	};
}

async function loadAndReady() {
	if (! window.FFC) {
		window.ffc_ajax = window.ffc_ajax || {
			ajax_url: window.ffc_csv_download && window.ffc_csv_download.ajax_url,
			nonce: '',
			strings: (window.ffc_csv_download && window.ffc_csv_download.strings) || {},
		};
		loadScript('assets/js/ffc-core.js');
	}
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
	postSpy.mockImplementationOnce(() => postChain({ done: { success: true, data: infoOverride || infoWithExtendEnd() } }));
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
			// extend-end specifics
			postponeClose: 'Postpone close',
			postponeCloseTooltip: 'Move the close time later.',
			postponeCloseTitle: 'Postpone form close?',
			postponeCloseBody: 'Pick a new close time within the same day.',
			postponeCurrentLabel: 'Current scheduled close:',
			postponeNewLabel: 'New close time:',
			postponeIrreversible: 'This action can only be performed once per form.',
			openEarlyCacheWarn: 'Cache warning.',
			cancel: 'Cancel',
			postponeConfirm: 'Confirm postponement',
			postponing: 'Postponing…',
			postponeSuccess: 'Close time postponed.',
			postponeInvalidFormat: 'Please enter a valid time (HH:MM).',
			postponeBeforeCurrent: 'Time must be later than the current close (%s).',
			postponeBeforeNow: 'Time must be in the future.',
			extendEndDisabledTip: 'Postpone Close disabled',
		},
	};
	window.$.fx.off = true;
});

afterEach(() => {
	vi.restoreAllMocks();
	vi.useRealTimers();
	document.body.innerHTML = '';
	document.querySelectorAll('.ffc-extend-end-modal, .ffc-open-early-modal').forEach((n) => n.remove());
});

// ----------------------------------------------------------------------
// Button render gating
// ----------------------------------------------------------------------

describe('csv extend-end — Postpone close button render', () => {
	it('renders the button when status.can_extend_end is true', async () => {
		await reachInfoScreen();
		const $btn = window.$('.ffc-btn-extend-end');
		expect($btn.length).toBe(1);
		expect($btn.text()).toContain('Postpone close');
		expect($btn.prop('disabled')).toBe(false);
	});

	it('renders a disabled button when extend_end_disabled_by_admin', async () => {
		await reachInfoScreen(infoWithExtendEnd({
			status: { can_extend_end: false, extend_end_disabled_by_admin: true },
		}));
		const $btn = window.$('.ffc-btn-extend-end');
		expect($btn.length).toBe(1);
		expect($btn.prop('disabled')).toBe(true);
	});

	it('omits the button when neither flag is set', async () => {
		await reachInfoScreen(infoWithExtendEnd({
			status: { can_extend_end: false },
		}));
		expect(window.$('.ffc-btn-extend-end').length).toBe(0);
	});
});

// ----------------------------------------------------------------------
// Modal — open + default time + close paths
// ----------------------------------------------------------------------

describe('csv extend-end — modal', () => {
	it('opens with the current scheduled close and a +30min default time', async () => {
		await reachInfoScreen();
		window.$('.ffc-btn-extend-end').trigger('click');
		await flush();

		const $modal = window.$('.ffc-extend-end-modal');
		expect($modal.length).toBe(1);
		expect($modal.text()).toContain('Postpone form close?');
		// Composed "date + raw time" display, independent of time_format.
		expect($modal.text()).toContain('2026-12-31 18:00');
		// 18:00 + 30min → 18:30 default.
		expect($modal.find('.ffc-extend-end-input').val()).toBe('18:30');
	});

	it('falls back to end_date_formatted when date-only is missing', async () => {
		// dateOnly empty → the composed "date + time" path is skipped and
		// the modal shows the pre-formatted end_date_formatted instead.
		await reachInfoScreen(infoWithExtendEnd({
			status: { current_date_end_formatted: '', current_time_end: '18:00', end_date_formatted: '2027-01-02 09:00' },
		}));
		window.$('.ffc-btn-extend-end').trigger('click');
		await flush();
		expect(window.$('.ffc-extend-end-modal').text()).toContain('2027-01-02 09:00');
	});

	it('closes on Cancel, backdrop, × and Escape', async () => {
		const closers = [
			'.ffc-extend-end-cancel',
			'.ffc-open-early-backdrop',
			'.ffc-extend-end-close',
		];
		for (const sel of closers) {
			await reachInfoScreen();
			window.$('.ffc-btn-extend-end').trigger('click');
			await flush();
			expect(window.$('.ffc-extend-end-modal').length).toBe(1);
			window.$(sel).trigger('click');
			await flush();
			expect(window.$('.ffc-extend-end-modal').length).toBe(0);
			document.body.innerHTML = '';
		}

		// Escape key path.
		await reachInfoScreen();
		window.$('.ffc-btn-extend-end').trigger('click');
		await flush();
		window.$(document).trigger(window.$.Event('keydown', { key: 'Escape' }));
		await flush();
		expect(window.$('.ffc-extend-end-modal').length).toBe(0);
	});
});

// ----------------------------------------------------------------------
// Client-side validation
// ----------------------------------------------------------------------

describe('csv extend-end — validation', () => {
	async function openModal(infoOverride) {
		await reachInfoScreen(infoOverride);
		window.$('.ffc-btn-extend-end').trigger('click');
		await flush();
	}

	it('rejects a malformed time and shows the inline error', async () => {
		await openModal();
		window.$('.ffc-extend-end-input').val('25:99');
		window.$('.ffc-extend-end-confirm').trigger('click');
		await flush();
		expect(window.$('.ffc-extend-end-modal').length).toBe(1); // not closed
		const $err = window.$('.ffc-extend-end-error');
		expect($err.attr('hidden')).toBeUndefined();
		expect($err.text()).toContain('valid time');
	});

	it('rejects a time at or before the current close', async () => {
		await openModal();
		window.$('.ffc-extend-end-input').val('17:00'); // <= current 18:00
		window.$('.ffc-extend-end-confirm').trigger('click');
		await flush();
		expect(window.$('.ffc-extend-end-error').text()).toContain('later than the current close');
	});

	it('rejects a time later than the current close but earlier than "now"', async () => {
		// Open under real timers (loadScript uses setTimeout), THEN freeze
		// the clock — validateInput's now-check reads new Date(). Value
		// 10:00 is > current close 08:00 (skips that branch) but <= 12:00
		// "now", so the future-guard fires.
		await openModal(infoWithExtendEnd({
			status: { current_time_end: '08:00', current_date_end_formatted: '2026-06-06' },
		}));
		vi.useFakeTimers();
		vi.setSystemTime(new Date('2026-06-06T12:00:00'));
		window.$('.ffc-extend-end-input').val('10:00');
		window.$('.ffc-extend-end-confirm').trigger('click');
		vi.useRealTimers();
		expect(window.$('.ffc-extend-end-error').text()).toContain('future');
	});

	it('clears the inline error on the next keystroke', async () => {
		await openModal();
		window.$('.ffc-extend-end-input').val('17:00');
		window.$('.ffc-extend-end-confirm').trigger('click');
		await flush();
		expect(window.$('.ffc-extend-end-error').attr('hidden')).toBeUndefined();

		window.$('.ffc-extend-end-input').val('19:00').trigger('input');
		expect(window.$('.ffc-extend-end-error').attr('hidden')).toBe('hidden');
	});
});

// ----------------------------------------------------------------------
// Confirm → submit ffc_public_extend_end
// ----------------------------------------------------------------------

describe('csv extend-end — submit', () => {
	// Freeze ONLY Date (not setTimeout — loadScript's ready tick must still
	// fire) to a fixed early time, so validateInput's "later than now" guard
	// is deterministic regardless of the wall-clock when CI runs. Without
	// this, a fixed new-time like 19:30 fails once real time passes it.
	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['Date'] });
		vi.setSystemTime(new Date('2026-06-10T08:00:00'));
	});

	it('POSTs ffc_public_extend_end on a valid confirm and reloads on success', async () => {
		const postSpy = await reachInfoScreen();
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		const original = window.location;
		const stub = { reload: vi.fn(), href: '/', pathname: '/', hostname: '' };
		Object.defineProperty(window, 'location', { configurable: true, writable: true, value: stub });

		postSpy.mockImplementation(() => postChain({ done: {
			success: true,
			data: { message: 'Close time postponed.' },
		} }));

		window.$('.ffc-btn-extend-end').trigger('click');
		await flush();
		window.$('.ffc-extend-end-input').val('19:30');
		window.$('.ffc-extend-end-confirm').trigger('click');
		await flush();

		const lastPayload = postSpy.mock.calls[postSpy.mock.calls.length - 1][1];
		expect(lastPayload).toContain('action=ffc_public_extend_end');
		expect(lastPayload).toContain('new_time_end=19%3A30');
		expect(lastPayload).not.toContain('action=ffc_public_csv_info');
		expect(alertSpy).toHaveBeenCalledWith('Close time postponed.');
		expect(stub.reload).toHaveBeenCalled();

		Object.defineProperty(window, 'location', { configurable: true, writable: true, value: original });
	});

	it('alerts the server message and re-enables the button on failure', async () => {
		const postSpy = await reachInfoScreen();
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		postSpy.mockImplementation(() => postChain({ done: {
			success: false,
			data: { message: 'Already postponed.' },
		} }));

		window.$('.ffc-btn-extend-end').trigger('click');
		await flush();
		window.$('.ffc-extend-end-input').val('19:30');
		window.$('.ffc-extend-end-confirm').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('Already postponed.');
		expect(window.$('.ffc-btn-extend-end').prop('disabled')).toBe(false);
	});

	it('alerts a generic error and re-enables the button on network failure', async () => {
		const postSpy = await reachInfoScreen();
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		postSpy.mockImplementation(() => postChain({ fail: true }));

		window.$('.ffc-btn-extend-end').trigger('click');
		await flush();
		window.$('.ffc-extend-end-input').val('19:30');
		window.$('.ffc-extend-end-confirm').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('Error');
		expect(window.$('.ffc-btn-extend-end').prop('disabled')).toBe(false);
	});
});
