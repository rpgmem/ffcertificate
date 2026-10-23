// @vitest-environment-options {"url": "http://example.org/certificate/"}
//
// Baseline of the visitor pre-flight on a site served over plain HTTP
// (#2030). The GPS step refuses an insecure page before it asks anything,
// and that check is about to move into a step of its own; this file pins
// what a visitor sees today, so the move can be proven to change nothing.
// The ALTCHA half of the same condition is pinned by captcha.test.js.
//
// Regenerate only on the code being frozen:
//   FFC_UPDATE_PREFLIGHT_BASELINE=1 npx vitest run tests/js/preflight-baseline-http.test.js
import { describe, it, expect } from 'vitest';
import { PRESETS, UAS, useHarness, baseline } from './preflight-baseline-support.js';

const { run, key, reset } = useHarness();

describe('visitor pre-flight baseline on an insecure page (#2030)', () => {
	it('is not a secure context', () => {
		expect(window.location.protocol).toBe('http:');
		expect(window.location.hostname).toBe('example.org');
	});

	it('reproduces every recorded outcome', async () => {
		const list = [];
		['form', 'content'].forEach((target) => {
			Object.keys(UAS).forEach((ua) => list.push({ target, datetime: 'off', cookies: true, bypass: false, geo: 'off', ua }));
			['none', 'granted'].forEach((permission) => Object.keys(PRESETS).forEach((preset) => Object.keys(UAS).forEach((ua) =>
				list.push({ target, datetime: 'off', cookies: true, bypass: false, geo: 'gps', permission, outcome: 'inside', preset, ua, cached: false })
			)));
		});

		const records = {};
		for (const s of list) {
			records[key(s)] = await run(s);
			reset();
		}

		const { expected, actual } = baseline('preflight-baseline-http.json', records);

		expect(Object.keys(actual).length).toBeGreaterThan(0);
		expect(Object.keys(actual).sort()).toEqual(Object.keys(expected).sort());
		const differing = Object.keys(expected).filter((k) => JSON.stringify(expected[k]) !== JSON.stringify(actual[k]));
		expect(differing.slice(0, 10), differing.length + ' scenario(s) changed').toEqual([]);
	}, 60000);
});
