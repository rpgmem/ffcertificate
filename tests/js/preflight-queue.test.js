// The visitor pre-flight queue in `assets/js/ffc-preflight.js` (#2030):
// ordered steps, a step that does not apply is skipped, the first step that
// does not call `next` stops the queue, and `next` carries on only once.
import { describe, it, expect, beforeAll } from 'vitest';
import { loadScript } from './helpers.js';

let Preflight;

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
	delete window.FFC.Preflight;
	loadScript('assets/js/ffc-preflight.js');
	Preflight = window.FFC.Preflight;
});

/**
 * Register steps under unique names and return them with a trace.
 *
 * @param {string} prefix Name prefix, unique per test.
 * @param {object} defs   name → { applies?, run(trace, next) }.
 */
function steps(prefix, defs) {
	const trace = [];
	const names = Object.keys(defs).map((name) => {
		const def = defs[name];
		Preflight.register(prefix + name, {
			applies: def.applies,
			run: (ctx, next) => {
				trace.push(name);
				def.run(next);
			},
		});
		return prefix + name;
	});
	return { trace, names };
}

describe('FFC.Preflight queue (#2030)', () => {
	it('runs the steps in the order given', () => {
		const { trace, names } = steps('order-', {
			a: { run: (next) => next() },
			b: { run: (next) => next() },
			c: { run: (next) => next() },
		});
		Preflight.run([names[2], names[0], names[1]], {});
		expect(trace).toEqual(['c', 'a', 'b']);
	});

	it('skips a step that does not apply', () => {
		const { trace, names } = steps('skip-', {
			a: { run: (next) => next() },
			b: { applies: () => false, run: (next) => next() },
			c: { run: (next) => next() },
		});
		Preflight.run(names, {});
		expect(trace).toEqual(['a', 'c']);
	});

	it('stops at the first step that does not call next', () => {
		const { trace, names } = steps('stop-', {
			a: { run: (next) => next() },
			b: { run: () => {} },
			c: { run: (next) => next() },
		});
		Preflight.run(names, {});
		expect(trace).toEqual(['a', 'b']);
	});

	it('carries on later, from the step after, when a stopped step calls next ("Try anyway")', () => {
		let resume;
		const { trace, names } = steps('later-', {
			a: { run: (next) => { resume = next; } },
			b: { run: (next) => next() },
		});
		Preflight.run(names, {});
		expect(trace).toEqual(['a']);
		resume();
		resume();
		expect(trace).toEqual(['a', 'b']);
	});

	it('ignores a name nobody registered', () => {
		const { trace, names } = steps('unknown-', { a: { run: (next) => next() } });
		Preflight.run(['nobody-registered-this', names[0]], {});
		expect(trace).toEqual(['a']);
	});

	it('passes the same context to every step', () => {
		const seen = [];
		Preflight.register('ctx-a', { run: (ctx, next) => { seen.push(ctx); next(); } });
		Preflight.register('ctx-b', { run: (ctx) => { seen.push(ctx); } });
		const ctx = { config: {} };
		Preflight.run(['ctx-a', 'ctx-b'], ctx);
		expect(seen).toEqual([ctx, ctx]);
	});

	it('skips the cookie step for an administrator under bypass', () => {
		Object.defineProperty(navigator, 'cookieEnabled', { configurable: true, value: false });
		const after = [];
		Preflight.register('after-cookies', { run: () => after.push('ran') });
		Preflight.run(['cookies', 'after-cookies'], { config: { adminBypass: true } });
		expect(after).toEqual(['ran']);
		Object.defineProperty(navigator, 'cookieEnabled', { configurable: true, value: true });
	});
});
