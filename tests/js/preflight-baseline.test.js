// Baseline of the visitor pre-flight as it runs today (#2030).
//
// Drives FFCGeofence.processForm and FFCGeofence.processContent through the
// combinations that decide what a visitor sees, and compares every outcome
// with tests/fixtures/preflight-baseline.json. The pre-flight is about to
// move into a queue of its own; this file is what proves the move changed
// nothing, and what lists, line by line, the changes that were meant.
//
// Regenerate only on the code being frozen:
//   FFC_UPDATE_PREFLIGHT_BASELINE=1 npx vitest run tests/js/preflight-baseline.test.js
import { describe, it, expect } from 'vitest';
import { DATETIMES, PRESETS, OUTCOMES, PERMISSIONS, UAS, useHarness, baseline } from './preflight-baseline-support.js';

const { run, key, reset } = useHarness();

/**
 * Every combination recorded. The full GPS matrix runs with the date
 * window off or open; the other dimensions are crossed with a few GPS
 * cases, since a closed window or a refused cookie stops the queue before
 * the GPS step anyway.
 *
 * @returns {object[]}
 */
function scenarios() {
	const list = [];
	const gpsMatrix = (base) => {
		PERMISSIONS.forEach((permission) => OUTCOMES.forEach((outcome) => Object.keys(PRESETS).forEach((preset) =>
			Object.keys(UAS).forEach((ua) => list.push(Object.assign({}, base, { geo: 'gps', permission, outcome, preset, ua, cached: false })))
		)));
	};

	['off', 'open'].forEach((datetime) => gpsMatrix({ target: 'form', datetime, cookies: true, bypass: false }));
	gpsMatrix({ target: 'content', datetime: 'off', cookies: true, bypass: false });

	const fewGps = [
		{ geo: 'off', ua: 'android' },
		{ geo: 'ip', ua: 'android' },
		{ geo: 'gps', permission: 'granted', outcome: 'inside', preset: 'hybrid', ua: 'android', cached: false },
		{ geo: 'gps', permission: 'none', outcome: 'denied', preset: 'hybrid', ua: 'iphone', cached: false },
	];
	Object.keys(DATETIMES).forEach((datetime) => [true, false].forEach((cookies) => [false, true].forEach((bypass) =>
		fewGps.forEach((g) => list.push(Object.assign({ target: 'form', datetime, cookies, bypass }, g)))
	)));
	Object.keys(DATETIMES).forEach((datetime) => [false, true].forEach((bypass) =>
		fewGps.forEach((g) => list.push(Object.assign({ target: 'content', datetime, cookies: true, bypass }, g)))
	));

	['form', 'content'].forEach((target) => ['none', 'granted'].forEach((permission) => Object.keys(UAS).forEach((ua) =>
		list.push({ target, datetime: 'off', cookies: true, bypass: false, geo: 'gps', permission, outcome: 'outside', preset: 'strict', ua, cached: true })
	)));

	// The cross product repeats a few GPS-matrix cases; each runs once.
	const seen = new Set();
	return list.filter((s) => !seen.has(key(s)) && seen.add(key(s)));
}

describe('visitor pre-flight baseline (#2030)', () => {
	it('reproduces every recorded outcome', async () => {
		const records = {};
		for (const s of scenarios()) {
			records[key(s)] = await run(s);
			reset();
		}

		const { expected, actual } = baseline('preflight-baseline.json', records);

		// The scan must have read something, and the same set of things.
		expect(Object.keys(actual).length).toBeGreaterThan(0);
		expect(Object.keys(actual).sort()).toEqual(Object.keys(expected).sort());

		const differing = Object.keys(expected).filter((k) => JSON.stringify(expected[k]) !== JSON.stringify(actual[k]));
		expect(differing.slice(0, 10), differing.length + ' scenario(s) changed').toEqual([]);
	}, 120000);
});
