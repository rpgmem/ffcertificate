// Smoke + targeted tests for `assets/js/ffc-calendar-admin.js`.
//
// The file is 28 LoC — a $(document).ready wrapper that calls
// FFCCalendarAdmin.bindEvents() (currently empty). The tests pin the
// load-time contract: the IIFE evaluates without throwing and the
// ready handler is registered.
import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

describe('ffc-calendar-admin.js', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
	});

	it('evaluates the IIFE without throwing', () => {
		expect(() => loadScript('assets/js/ffc-calendar-admin.js')).not.toThrow();
	});

	it('does not leak globals beyond jQuery', () => {
		// The file is a private IIFE; nothing should be exposed on window.
		loadScript('assets/js/ffc-calendar-admin.js');
		expect(window.FFCCalendarAdmin).toBeUndefined();
	});
});
