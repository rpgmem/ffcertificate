// Covers the localized-config bootstrap fallbacks at the top of
// assets/js/ffc-audience.js: when WordPress fails to localize the
// `ffcAudience` global (or omits `strings`), the IIFE synthesises a
// default config + empty strings bag so the rest of the module can run.
//
// This must load the audience IIFE with `ffcAudience` *undefined*, so it
// lives in its own file (a fresh jsdom) — the other audience suites set
// the global in beforeAll before the script ever evaluates.
import { describe, it, expect, beforeAll } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	// Core helper must exist (the audience module uses FFC.rest/request).
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: '', strings: {} };
	loadScript('assets/js/ffc-core.js');
	// Deliberately leave window.ffcAudience undefined so the bootstrap
	// fallback branches run.
	delete window.ffcAudience;
	loadScript('assets/js/ffc-audience.js');
});

describe('ffc-audience — config bootstrap fallbacks', () => {
	it('synthesises a default ffcAudience config when none is localized', () => {
		expect(window.ffcAudience).toBeDefined();
		expect(window.ffcAudience.ajaxUrl).toBe('/wp-admin/admin-ajax.php');
		expect(window.ffcAudience.restUrl).toBe('/wp-json/ffc/v1/audience/');
		expect(window.ffcAudience.nonce).toBe('');
	});

	it('fills the default strings catalogue (months etc.)', () => {
		expect(window.ffcAudience.strings).toBeDefined();
		expect(window.ffcAudience.strings.months).toHaveLength(12);
		expect(window.ffcAudience.strings.holiday).toBe('Holiday');
	});

	it('registers the window.FFCAudience singleton with its helpers', () => {
		expect(window.FFCAudience).toBeDefined();
		expect(typeof window.FFCAudience.formatDate).toBe('function');
	});
});
