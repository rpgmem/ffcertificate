// [ffc_geofence] client half (#1668): `assets/js/ffc-geofence-content.js`.
//
// The placeholder runs the form checks through the shared FFCGeofence
// object; where a form would be revealed, the content is fetched instead.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-geofence-frontend.js');
	loadScript('assets/js/ffc-geofence-datetime.js');
	loadScript('assets/js/ffc-geofence-gps.js');
	loadScript('assets/js/ffc-geofence-preflight.js');
	loadScript('assets/js/ffc-geofence-content.js');
});

let request;

beforeEach(() => {
	document.body.innerHTML = '';
	window.$.fx.off = true;
	window.ffcGeofenceConfig = undefined;
	window.ffcGeofenceContent = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		action: 'ffc_geofence_content',
		strings: { unavailable: 'This content is not available.' },
	};
	request = vi.fn(() => Promise.resolve({ html: '<p>Answer key</p>' }));
	window.FFC = { request };
});

afterEach(() => {
	vi.restoreAllMocks();
});

function mount(config, handle = '7.0.abcdef0123456789.' + '0'.repeat(32)) {
	const json = JSON.stringify(config).replace(/"/g, '&quot;');
	document.body.insertAdjacentHTML(
		'beforeend',
		`<div id="ffc-geofence-content-1" class="ffc-shortcode ffc-geofence-content"
			data-ffc-geofence-handle="${handle}" data-ffc-geofence-config="${json}">
			<div class="ffc-geofence-loading-msg"><div class="ffc-spinner"></div><p>Checking access…</p></div>
		</div>`,
	);
	return window.$('#ffc-geofence-content-1');
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('protected content', () => {
	it('fetches the content when there is no location to check', async () => {
		const $w = mount({ datetime: { enabled: false }, geo: { enabled: false } });

		window.FFCGeofence.processContent($w);
		await flush();

		expect(request).toHaveBeenCalledTimes(1);
		expect(request).toHaveBeenCalledWith(
			'ffc_geofence_content',
			{ handle: $w.attr('data-ffc-geofence-handle') },
			{ ajaxUrl: '/wp-admin/admin-ajax.php' },
		);
		expect($w.html()).toBe('<p>Answer key</p>');
		expect($w.hasClass('ffc-validated')).toBe(true);
	});

	it('blocks without asking the server when the date window has passed', () => {
		const $w = mount({
			datetime: { enabled: true, dateStart: '2020-01-01', dateEnd: '2020-01-02', timeMode: 'daily', message: 'Closed.' },
			geo: { enabled: false },
		});

		window.FFCGeofence.processContent($w);

		expect(request).not.toHaveBeenCalled();
		expect($w.find('.ffc-geofence-blocked').text()).toContain('Closed.');
		expect($w.find('.ffc-geofence-loading-msg')).toHaveLength(0);
	});

	it('hides the placeholder when the hide mode says so', () => {
		const $w = mount({
			datetime: { enabled: true, dateEnd: '2020-01-02', timeMode: 'daily', hideModeAfter: 'hide' },
			geo: { enabled: false },
		});

		window.FFCGeofence.processContent($w);

		expect($w.css('display')).toBe('none');
		expect(request).not.toHaveBeenCalled();
	});

	it('leaves IP location to the server', async () => {
		const $w = mount({
			datetime: { enabled: false },
			geo: { enabled: true, gpsEnabled: false, ipEnabled: true },
		});

		window.FFCGeofence.processContent($w);
		await flush();

		expect(request).toHaveBeenCalledTimes(1);
	});

	it('shows the server\'s refusal', async () => {
		request.mockImplementation(() => {
			const err = new Error('Connection error');
			err.data = { message: 'This content is not available in your location.' };
			return Promise.reject(err);
		});
		const $w = mount({ datetime: { enabled: false }, geo: { enabled: false } });

		window.FFCGeofence.processContent($w);
		await flush();

		expect($w.find('.ffc-geofence-blocked').text()).toContain('This content is not available in your location.');
		expect($w.find('.ffc-geofence-loading-msg')).toHaveLength(0);
	});

	it('falls back to its own message when the request fails without one', async () => {
		request.mockImplementation(() => Promise.reject(new Error('Connection error')));
		const $w = mount({ datetime: { enabled: false }, geo: { enabled: false } });

		window.FFCGeofence.processContent($w);
		await flush();

		expect($w.find('.ffc-geofence-blocked').text()).toContain('This content is not available.');
	});

	it('asks for the content once however often it is revealed', async () => {
		const $w = mount({ datetime: { enabled: false }, geo: { enabled: false } });

		window.FFCGeofence.showForm($w);
		window.FFCGeofence.showForm($w);
		await flush();

		expect(request).toHaveBeenCalledTimes(1);
	});

	it('treats an unreadable config as nothing to check', async () => {
		document.body.innerHTML =
			'<div id="x" data-ffc-geofence-handle="h" data-ffc-geofence-config="{oops"></div>';

		window.FFCGeofence.initContent();
		await flush();

		expect(request).toHaveBeenCalledTimes(1);
	});

	it('shows the admin bypass notice', async () => {
		const $w = mount({
			adminBypass: true,
			bypassInfo: { hasDatetime: true, hasGeo: false },
			datetime: { enabled: false },
			geo: { enabled: false },
		});

		window.FFCGeofence.processContent($w);

		expect($w.find('.ffc-geofence-admin-bypass')).toHaveLength(1);
	});
});

describe('forms keep their own behaviour', () => {
	it('reveals a form wrapper without fetching anything', () => {
		document.body.innerHTML =
			'<div id="ffc-form-3"><form class="ffc-submission-form">body</form></div>';
		const $f = window.$('#ffc-form-3');

		window.FFCGeofence.showForm($f);
		window.FFCGeofence.handleBlocked($f, 'message', 'Nope');

		expect(request).not.toHaveBeenCalled();
		expect($f.hasClass('ffc-validated')).toBe(true);
		expect($f.find('.ffc-geofence-blocked').text()).toContain('Nope');
	});
});
