// Unit tests for the pure helpers on `window.FFCGeofence` defined in
// `assets/js/ffc-geofence-frontend.js`:
//   - validateDateTime(config) → { valid, message, phase? }
//   - pickHideMode(datetimeConfig, phase) → 'hide' | 'message' | 'title_message'
//
// Added as part of #161 S2 to cover the phase-aware datetime logic shipped
// in #160. Loaded once via beforeAll — the IIFE registers the global.
import { describe, it, expect, beforeAll, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-geofence-frontend.js');
});

// 6.7.3 — Always restore the real clock after any test that opts into
// fake timers, so a subsequent test doesn't inherit a frozen `Date.now()`.
afterEach(() => {
	vi.useRealTimers();
});

// ----------------------------------------------------------------------
// pickHideMode — phase → hideMode<Phase> mapping
// ----------------------------------------------------------------------

describe('FFCGeofence.pickHideMode', () => {
	const config = {
		hideModeBefore: 'hide',
		hideModeDuring: 'message',
		hideModeAfter: 'title_message',
	};

	it('returns the "before" mode for phase=before', () => {
		expect(window.FFCGeofence.pickHideMode(config, 'before')).toBe('hide');
	});

	it('returns the "during" mode for phase=during', () => {
		expect(window.FFCGeofence.pickHideMode(config, 'during')).toBe('message');
	});

	it('returns the "after" mode for phase=after', () => {
		expect(window.FFCGeofence.pickHideMode(config, 'after')).toBe('title_message');
	});

	it('falls back to hideModeBefore when phase is unknown', () => {
		expect(window.FFCGeofence.pickHideMode(config, 'sideways')).toBe('hide');
		expect(window.FFCGeofence.pickHideMode(config, undefined)).toBe('hide');
	});

	it("defaults to 'message' when the requested phase mode is missing", () => {
		expect(window.FFCGeofence.pickHideMode({}, 'after')).toBe('message');
	});
});

// ----------------------------------------------------------------------
// validateDateTime — phase-tagged failure branches
// ----------------------------------------------------------------------

describe('FFCGeofence.validateDateTime', () => {
	it('returns valid when no restrictions are configured', () => {
		const result = window.FFCGeofence.validateDateTime({});
		expect(result.valid).toBe(true);
		expect(result.phase).toBeUndefined();
	});

	it('returns valid when the current moment falls inside the daily window', () => {
		// dateStart = epoch start, dateEnd = far future; time window covers all
		// reasonable execution time. Using a very wide range avoids flakiness
		// from the CI clock.
		const result = window.FFCGeofence.validateDateTime({
			dateStart: '2000-01-01',
			dateEnd: '2999-12-31',
			timeMode: 'daily',
		});
		expect(result.valid).toBe(true);
	});

	it('flags phase=before when the current date is before dateStart (daily)', () => {
		const result = window.FFCGeofence.validateDateTime({
			dateStart: '2999-01-01',
			timeMode: 'daily',
		});
		expect(result.valid).toBe(false);
		expect(result.phase).toBe('before');
	});

	it('flags phase=after when the current date is after dateEnd (daily)', () => {
		const result = window.FFCGeofence.validateDateTime({
			dateEnd: '2000-12-31',
			timeMode: 'daily',
		});
		expect(result.valid).toBe(false);
		expect(result.phase).toBe('after');
	});

	it('flags phase=during when inside dates but outside the daily slot', () => {
		// 6.7.3 — Lock the clock at midday UTC. Pre-6.7.3 the test depended
		// on the real wall-clock landing OUTSIDE the 00:00–00:01 window;
		// the <0.07%/run window where a CI runner started inside the first
		// minute of the day caused a real flake (#382 hit it on 2026-05-23
		// at 00:00:44 UTC). Fake timers eliminate the window entirely with
		// no production-code change.
		vi.useFakeTimers();
		vi.setSystemTime(new Date('2026-05-23T12:00:00Z'));

		const result = window.FFCGeofence.validateDateTime({
			dateStart: '2000-01-01',
			dateEnd: '2999-12-31',
			timeStart: '00:00',
			timeEnd: '00:01',
			timeMode: 'daily',
		});
		expect(result.valid).toBe(false);
		expect(result.phase).toBe('during');
	});

	it('flags phase=before for span mode when before the composed datetime', () => {
		const result = window.FFCGeofence.validateDateTime({
			dateStart: '2999-01-01',
			dateEnd: '2999-01-02',
			timeStart: '22:00',
			timeEnd: '06:00',
			timeMode: 'span',
		});
		expect(result.valid).toBe(false);
		expect(result.phase).toBe('before');
	});

	it('flags phase=after for span mode when past the composed datetime', () => {
		const result = window.FFCGeofence.validateDateTime({
			dateStart: '2000-01-01',
			dateEnd: '2000-01-02',
			timeStart: '22:00',
			timeEnd: '06:00',
			timeMode: 'span',
		});
		expect(result.valid).toBe(false);
		expect(result.phase).toBe('after');
	});

	it('uses the custom message when provided on a failed validation', () => {
		const result = window.FFCGeofence.validateDateTime({
			dateStart: '2999-01-01',
			message: 'Custom not-yet message',
			timeMode: 'daily',
		});
		expect(result.message).toBe('Custom not-yet message');
	});
});
