// The public notice component (#1842): the `FFC.notice()` builder in
// `assets/js/ffc-core.js`, and every certificate-form emitter that now goes
// through it. Each emitter is driven through its real entry point; the
// assertions name the tone, the registry icon, the role, and that nothing
// carries an inline style or turns a string into markup.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const XSS = '<img src=x onerror="window.__pwned=1">';

beforeAll(async () => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'n',
		strings: { error: 'Error occurred', connectionError: 'Connection error', fillRequired: 'Please fill all required fields' },
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-field-behaviours.js');
	loadScript('assets/js/ffc-frontend-helpers.js');
	loadScript('assets/js/ffc-frontend.js');
	loadScript('assets/js/ffc-preflight.js');
	loadScript('assets/js/ffc-geofence-frontend.js');
	loadScript('assets/js/ffc-geofence-permission.js');
	loadScript('assets/js/ffc-csv-download.js');
	await new Promise((r) => setTimeout(r, 0));
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.$.fx.off = true;
	window.__pwned = undefined;
});

afterEach(() => {
	vi.restoreAllMocks();
	vi.useRealTimers();
});

function flush() { return new Promise((r) => setTimeout(r, 0)); }

/** Shape every notice shares, whatever built it. */
function expectNotice(el, tone, icon, role) {
	expect(el).not.toBeNull();
	expect(el.classList.contains('ffc-notice')).toBe(true);
	expect(el.classList.contains('ffc-notice-' + tone)).toBe(true);
	expect(el.getAttribute('role')).toBe(role);
	const mark = el.querySelector(':scope > .ffc-notice-icon');
	expect(mark.classList.contains('ffc-icon-' + icon)).toBe(true);
	expect(mark.getAttribute('aria-hidden')).toBe('true');
	expect(el.querySelector(':scope > .ffc-notice-body')).not.toBeNull();
	expect(el.hasAttribute('style')).toBe(false);
	expect(el.querySelector('[style]')).toBeNull();
}

function certForm(id) {
	document.body.innerHTML = `
		<div class="ffc-shortcode ffc-form-wrapper" id="ffc-form-${id}">
			<h2 class="ffc-form-title">Form</h2>
			<form class="ffc-submission-form" id="ffc-form-element-${id}">
				<input type="hidden" name="form_id" value="${id}">
				<div class="ffc-form-field"><input name="name" value="Ana"></div>
				<button type="submit">Submit</button>
			</form>
		</div>`;
	return window.$('#ffc-form-' + id);
}

describe('FFC.notice()', () => {
	it('builds the same markup Core\\Notice::render() prints', () => {
		const el = window.FFC.notice('warning', 'clock', 'Wait.', 'Too many');
		expect(el.outerHTML).toBe(
			'<div class="ffc-notice ffc-notice-warning" role="status">'
			+ '<span class="ffc-notice-icon ffc-icon-clock" aria-hidden="true"></span>'
			+ '<div class="ffc-notice-body"><strong class="ffc-notice-title">Too many</strong><p class="ffc-notice-text">Wait.</p></div>'
			+ '</div>'
		);
	});

	it.each(['info', 'warning', 'danger', 'success', 'neutral'])('takes the %s tone', (tone) => {
		expect(window.FFC.notice(tone, 'info', 'x').className).toBe('ffc-notice ffc-notice-' + tone);
	});

	it('falls back to info for an unknown tone and to the tone\'s icon for a malformed one', () => {
		const el = window.FFC.notice('evil" onclick="x', 'a b', 'x');
		expect(el.className).toBe('ffc-notice ffc-notice-info');
		expect(el.querySelector('.ffc-notice-icon').className).toBe('ffc-notice-icon ffc-icon-info');
		expect(window.FFC.notice('danger', '', 'x').querySelector('.ffc-notice-icon').className).toBe('ffc-notice-icon ffc-icon-error');
	});

	it('writes title and text through textContent, never as markup', () => {
		const el = window.FFC.notice('danger', 'error', XSS, XSS);
		document.body.appendChild(el);
		expect(el.querySelector('img')).toBeNull();
		expect(el.querySelector('.ffc-notice-title').textContent).toBe(XSS);
		expect(el.querySelector('.ffc-notice-text').textContent).toBe(XSS);
		expect(window.__pwned).toBeUndefined();
	});

	it('prints a paragraph per entry, skips empty ones, and appends children', () => {
		const btn = document.createElement('button');
		const el = window.FFC.notice('warning', 'warning', ['a', '', null, 'b'], '', { children: [btn] });
		expect(el.querySelectorAll('.ffc-notice-text')).toHaveLength(2);
		expect(el.querySelector('.ffc-notice-body').lastChild).toBe(btn);
		expect(el.querySelector('.ffc-notice-title')).toBeNull();
	});

	it('takes a known role, a live region and a hook', () => {
		const el = window.FFC.notice('danger', 'error', 'x', '', { role: 'alert', live: 'assertive', hook: 'form-error' });
		expect(el.getAttribute('role')).toBe('alert');
		expect(el.getAttribute('aria-live')).toBe('assertive');
		expect(el.getAttribute('data-ffc-notice')).toBe('form-error');
		const odd = window.FFC.notice('info', 'info', 'x', '', { role: 'banner', live: 'rude' });
		expect(odd.getAttribute('role')).toBe('status');
		expect(odd.hasAttribute('aria-live')).toBe(false);
	});
});

describe('certificate-form emitters', () => {
	it('admin bypass: an info notice per restriction, unlock icon, ahead of the title', () => {
		const $w = certForm(1);
		window.FFCGeofence.showAdminBypassMessages($w, { hasDatetime: true, hasGeo: true });
		const notices = $w[0].querySelectorAll(':scope > [data-ffc-notice="geofence-bypass"]');
		expect(notices).toHaveLength(2);
		notices.forEach((n) => expectNotice(n, 'info', 'unlock', 'note'));
		expect(notices[0].textContent).toContain('Date/Time');
		expect(notices[1].textContent).toContain('Geolocation');
		expect(notices[1].nextElementSibling.classList.contains('ffc-form-title')).toBe(true);
	});

	it('geofence blocked: a warning notice with map-pin, text escaped, reload button kept', () => {
		const $w = certForm(2);
		const reload = vi.fn();
		vi.stubGlobal('location', { reload });
		window.FFCGeofence.handleBlocked($w, 'title_message', XSS, true);
		const n = $w[0].querySelector('[data-ffc-notice="geofence-blocked"]');
		expectNotice(n, 'warning', 'map-pin', 'status');
		expect(n.querySelector('img')).toBeNull();
		expect(n.querySelector('.ffc-notice-text').textContent).toBe(XSS);
		const btn = n.querySelector('.ffc-notice-body > button.ffc-geofence-reload-btn');
		expect(btn).not.toBeNull();
		btn.click();
		expect(reload).toHaveBeenCalled();
		vi.unstubAllGlobals();
	});

	it('preflight: a warning notice with a title, the how-to and the CTA', () => {
		const $w = certForm(3);
		const onCta = vi.fn();
		window.FFC.Preflight.banner($w, 'preflight-gps', 'We need your location', 'Body.', 'How.', 'Continue', onCta);
		const n = $w[0].querySelector('[data-ffc-notice="preflight-gps"]');
		expectNotice(n, 'warning', 'warning', 'alert');
		expect(n.getAttribute('aria-live')).toBe('assertive');
		expect(n.querySelector('.ffc-notice-title').textContent).toBe('We need your location');
		expect([...n.querySelectorAll('.ffc-notice-text')].map((p) => p.textContent)).toEqual(['Body.', 'How.']);
		n.querySelector('.ffc-preflight-try-anyway').click();
		expect(onCta).toHaveBeenCalled();
		expect($w[0].querySelector('[data-ffc-notice="preflight-gps"]')).toBeNull();
	});

	it('rate limit: a warning notice with clock, the message as text and a live countdown', () => {
		certForm(4);
		vi.useFakeTimers();
		window.FFC.Frontend.RateLimit.show(XSS, 75, document.getElementById('ffc-form-element-4'));
		const n = document.querySelector('[data-ffc-notice="rate-limit"]');
		expectNotice(n, 'warning', 'clock', 'status');
		expect(n.getAttribute('aria-live')).toBe('polite');
		expect(n.querySelector('img')).toBeNull();
		expect(n.querySelector('.ffc-notice-text').textContent).toBe(XSS + ' 1:15');
		vi.advanceTimersByTime(1000);
		expect(n.querySelector('.ffc-notice-text strong').textContent).toBe('1:14');
		window.FFC.Frontend.RateLimit.enable();
		expect(document.querySelector('[data-ffc-notice="rate-limit"]')).toBeNull();
	});

	it('submit error: a danger alert with the error icon, no "Error:" prefix built from markup', () => {
		const $w = certForm(5);
		vi.spyOn(window.$.fn, 'animate').mockReturnThis();
		window.FFC.Frontend.UI.showFormError($w.find('form'), XSS);
		const n = $w[0].querySelector('[data-ffc-notice="form-error"]');
		expectNotice(n, 'danger', 'error', 'alert');
		expect(n.querySelector('img')).toBeNull();
		expect(n.querySelector('.ffc-notice-text').textContent).toBe(XSS);
	});

	it('generic success: a success notice with the success icon and a title', () => {
		const $w = certForm(6);
		window.FFC.Frontend.UI.showFormSuccess($w.find('form'), '');
		expectNotice($w[0].querySelector('[data-ffc-notice="form-success"]'), 'success', 'success', 'status');
	});

	it('accessible alert: a danger alert above the form', async () => {
		const $w = certForm(7);
		$w.find('input[name="name"]').val('').attr('required', true);
		$w.find('form').trigger('submit');
		const n = document.querySelector('[data-ffc-notice="accessible-alert"]');
		expectNotice(n, 'danger', 'error', 'alert');
		expect(n.nextElementSibling.tagName).toBe('FORM');
	});

	it('awaiting grading: replaces the form and sits above the title; the quiz notice goes', async () => {
		const $w = certForm(8);
		$w.prepend('<div class="ffc-notice ffc-notice-info" data-ffc-notice="quiz-leave"></div>');
		const html = '<div class="ffc-notice ffc-notice-info" role="status" data-ffc-notice="awaiting-grading"><span class="ffc-notice-icon ffc-icon-clock" aria-hidden="true"></span><div class="ffc-notice-body"><p class="ffc-notice-text">Received.</p></div></div>';
		vi.spyOn(window.FFC, 'request').mockResolvedValue({ html, pdf_data: null, quiz: { pending: true } });
		$w.find('form').trigger('submit');
		await flush();
		const n = $w[0].querySelector('[data-ffc-notice="awaiting-grading"]');
		expectNotice(n, 'info', 'clock', 'status');
		expect(n.nextElementSibling.classList.contains('ffc-form-title')).toBe(true);
		expect($w[0].querySelector('form')).toBeNull();
		expect($w[0].querySelector('[data-ffc-notice="quiz-leave"]')).toBeNull();
	});

	it('a certificate response still replaces the form contents, and drops the quiz notice', async () => {
		const $w = certForm(9);
		$w.prepend('<div data-ffc-notice="quiz-leave"></div>');
		vi.spyOn(window.FFC, 'request').mockResolvedValue({ html: '<p class="done">Done</p>', pdf_data: null });
		$w.find('form').trigger('submit');
		await flush();
		expect($w.find('form .done')).toHaveLength(1);
		expect($w[0].querySelector('[data-ffc-notice="quiz-leave"]')).toBeNull();
	});

	it('verification error: a danger alert before the first field, replaced not stacked', async () => {
		document.body.innerHTML = `
			<div class="ffc-shortcode ffc-verification-container">
				<form class="ffc-verification-form">
					<div class="ffc-form-field"><input name="ffc_auth_code" value="CODE"></div>
					<button type="submit">Verify</button>
				</form>
			</div>`;
		const request = vi.spyOn(window.FFC, 'request').mockRejectedValue({ fromServer: true, message: XSS });
		window.$('.ffc-verification-form').trigger('submit');
		await flush();
		window.$('.ffc-verification-form').trigger('submit');
		await flush();
		expect(request).toHaveBeenCalledTimes(2);
		const notices = document.querySelectorAll('[data-ffc-notice="verify-error"]');
		expect(notices).toHaveLength(1);
		expectNotice(notices[0], 'danger', 'error', 'alert');
		expect(notices[0].nextElementSibling.classList.contains('ffc-form-field')).toBe(true);
		expect(notices[0].querySelector('img')).toBeNull();
	});

	it('public CSV flash: danger for an error, success otherwise, inside the result wrapper', () => {
		document.body.innerHTML = '<div class="ffc-public-csv-download"><div class="ffc-verification-header"></div></div>';
		window.FFCCsv.$container = window.$('.ffc-public-csv-download');
		window.FFCCsv.showFlash(XSS, 'error');
		const error = document.querySelector('.ffc-pcd-message > .ffc-notice');
		expectNotice(error, 'danger', 'error', 'alert');
		expect(error.querySelector('img')).toBeNull();
		window.FFCCsv.showFlash('Ready.', 'success');
		expect(document.querySelectorAll('.ffc-pcd-message')).toHaveLength(1);
		expectNotice(document.querySelector('.ffc-pcd-message > .ffc-notice'), 'success', 'success', 'status');
	});
});
