// Unit tests for the order-of-dates validator at
// `window.FFCGeofenceValidation.analyzeDateTimeOrder` in
// `assets/js/ffc-geofence-validation.js` — the pure helper extracted
// out of ffc-geofence-admin.js in S2 of #163.
//
// Mirrors the PHP unit tests in tests/Unit/GeofenceTest.php — the JS-side
// helper is a client-side mirror of `Geofence::analyze_datetime_order()`
// so the live red-border feedback in the metabox matches the server-side
// validation at save time.
//
// Originally added in #161 S2; rebased onto the extracted module in #163 S2.
import { describe, it, expect, beforeAll } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-geofence-validation.js');
});

const analyze = (...args) => window.FFCGeofenceValidation.analyzeDateTimeOrder(...args);

describe('FFCGeofenceAdmin.analyzeDateTimeOrder', () => {
	it('is empty for a valid daily window', () => {
		const errors = analyze({
			date_start: '2026-06-01',
			date_end: '2026-06-30',
			time_start: '08:00',
			time_end: '18:00',
			time_mode: 'daily',
		});
		expect(errors).toEqual({});
	});

	it('is empty for a valid span window', () => {
		const errors = analyze({
			date_start: '2026-06-01',
			date_end: '2026-06-02',
			time_start: '22:00',
			time_end: '06:00',
			time_mode: 'span',
		});
		expect(errors).toEqual({});
	});

	it('flags both date inputs when end < start', () => {
		const errors = analyze({
			date_start: '2026-06-30',
			date_end: '2026-06-01',
			time_mode: 'daily',
		});
		expect(errors.date_start).toBeTruthy();
		expect(errors.date_end).toBeTruthy();
		expect(errors.date_start).toBe(errors.date_end);
	});

	it('short-circuits on date inversion (no daily/span errors stacked)', () => {
		const errors = analyze({
			date_start: '2026-06-30',
			date_end: '2026-06-01',
			time_start: '18:00',
			time_end: '08:00',
			time_mode: 'daily',
		});
		expect(Object.keys(errors).sort()).toEqual(['date_end', 'date_start']);
		expect(errors.time_start).toBeUndefined();
	});

	it('flags time inputs in span mode when composed datetime is inverted', () => {
		const errors = analyze({
			date_start: '2026-06-01',
			date_end: '2026-06-01',
			time_start: '18:00',
			time_end: '08:00',
			time_mode: 'span',
		});
		expect(errors.time_start).toBeTruthy();
		expect(errors.time_end).toBeTruthy();
		expect(errors.date_start).toBeUndefined();
	});

	it('flags time inputs in daily mode when end <= start', () => {
		const errors = analyze({
			time_start: '18:00',
			time_end: '08:00',
			time_mode: 'daily',
		});
		expect(errors.time_start).toBeTruthy();
		expect(errors.time_end).toBeTruthy();
	});

	it('flags time inputs when end equals start in daily mode', () => {
		// Equal start/end is a zero-length window — runtime would treat the
		// form as always-blocked. The validator returns an error so the
		// operator sees the issue before saving.
		const errors = analyze({
			time_start: '12:00',
			time_end: '12:00',
			time_mode: 'daily',
		});
		expect(errors.time_start).toBeTruthy();
		expect(errors.time_end).toBeTruthy();
	});

	it('skips partial configs (only date_start)', () => {
		expect(analyze({ date_start: '2026-06-01' })).toEqual({});
	});

	it('skips partial span configs (times without dates)', () => {
		expect(analyze({
			time_start: '10:00',
			time_end: '20:00',
			time_mode: 'span',
		})).toEqual({});
	});

	it('defaults to time_mode=daily when omitted', () => {
		const errors = analyze({
			time_start: '18:00',
			time_end: '08:00',
		});
		expect(errors.time_start).toBeTruthy();
	});

	it('flags Event Schedule (class_time_*) when end <= start', () => {
		const errors = analyze({
			class_time_start: '14:00',
			class_time_end: '12:00',
		});
		expect(errors.class_time_start).toBeTruthy();
		expect(errors.class_time_end).toBeTruthy();
		expect(errors.class_time_start).toBe(errors.class_time_end);
	});

	it('does not flag Event Schedule when only one input is filled', () => {
		expect(analyze({ class_time_start: '09:00' })).toEqual({});
		expect(analyze({ class_time_end: '17:30' })).toEqual({});
	});
});
