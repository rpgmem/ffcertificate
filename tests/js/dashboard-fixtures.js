// Fixtures + helpers shared by the dashboard-panel tests (S4 of #163).
//
// Each test that exercises a panel needs (a) a window.ffcDashboard.strings
// payload (the panels treat absent keys as undefined, which produces
// "undefined" literals in the HTML — not what we want to assert on), and
// (b) the tab container the renderer writes into (`#tab-certificates`,
// `#tab-appointments`, etc.). Centralising both keeps each test file
// focused on its assertions.

import { loadScript } from './helpers.js';

const STRINGS = {
	loading: 'Loading…',
	error: 'Error',
	noCertificates: 'No certificates',
	noAppointments: 'No appointments',
	noBookings: 'No bookings',
	eventName: 'Event',
	date: 'Date',
	consent: 'Consent',
	email: 'Email',
	code: 'Code',
	actions: 'Actions',
	yes: 'Yes',
	no: 'No',
	downloadPdf: 'Download PDF',
	filterFrom: 'From:',
	filterTo: 'To:',
	filterSearch: 'Search…',
	filterApply: 'Filter',
	filterClear: 'Clear',
	perPage: 'Per page:',
	previous: 'Prev',
	next: 'Next',
	pageOf: 'Page {current} of {total}',
	location: 'Location',
	when: 'When',
	status: 'Status',
	cancel: 'Cancel',
	confirmed: 'Confirmed',
	cancelled: 'Cancelled',
	pending: 'Pending',
	completed: 'Completed',
};

export function installDashboardFixtures() {
	window.ffcDashboard = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		strings: STRINGS,
	};

	// Build the tab containers the panel renderers target.
	document.body.innerHTML = `
		<div id="ffc-dashboard">
			<div class="ffc-tabs">
				<a class="ffc-tab active" data-tab="certificates">Certs</a>
				<a class="ffc-tab" data-tab="appointments">Appts</a>
				<a class="ffc-tab" data-tab="audience">Audience</a>
			</div>
			<div id="tab-certificates" class="ffc-tab-content active"></div>
			<div id="tab-appointments" class="ffc-tab-content"></div>
			<div id="tab-audience" class="ffc-tab-content"></div>
		</div>
	`;
}

export function loadDashboardCore() {
	// FFC.rest / FFC.request live on window.FFC, which initialises
	// itself from window.ffc_ajax at load time. Mirror the dashboard's
	// localised admin-ajax URL + nonce + strings so the helpers pick up
	// the test's overrides instead of jQuery's `/wp-admin/admin-ajax.php`
	// default.
	if (! window.ffc_ajax && window.ffcDashboard) {
		window.ffc_ajax = {
			ajax_url: window.ffcDashboard.ajaxUrl,
			nonce: window.ffcDashboard.nonce,
			strings: window.ffcDashboard.strings || {},
		};
	}
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-user-dashboard-core.js');
}

// FFC.rest / FFC.request wrap their underlying $.ajax in a Promise.
// Tests that mock $.ajax fire opts.success / opts.error synchronously
// inside the spy, but the .then / .catch reactions queue on the
// microtask queue. Await this helper after the trigger so the panel
// handlers have a chance to mutate state / DOM before the assertion.
export function flushPromises() {
	return Promise.resolve().then(() => Promise.resolve());
}

export function loadPanel(name) {
	loadScript(`assets/js/ffc-user-dashboard-${name}.js`);
}
