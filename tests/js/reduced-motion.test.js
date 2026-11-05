// ffc-core honours "reduce motion" (#2344): `jQuery.fx.off` follows the
// reader's setting, both ways, and is only switched back on when ffc-core
// switched it off.
import { describe, it, expect, afterEach } from 'vitest';
import { loadScript } from './helpers.js';

function fakeQuery(matches) {
	const listeners = [];
	return {
		matches,
		addEventListener: (type, fn) => listeners.push(fn),
		change(next) {
			this.matches = next;
			listeners.forEach((fn) => fn());
		},
	};
}

const original = window.matchMedia;

afterEach(() => {
	window.matchMedia = original;
	window.jQuery.fx.off = false;
});

describe('ffc-core reduced motion', () => {
	it('turns jQuery animations off while the reader asks for less motion, and back on after', () => {
		const query = fakeQuery(true);
		window.matchMedia = (q) => {
			expect(q).toBe('(prefers-reduced-motion: reduce)');
			return query;
		};
		window.jQuery.fx.off = false;

		loadScript('assets/js/ffc-core.js');
		expect(window.jQuery.fx.off).toBe(true);

		query.change(false);
		expect(window.jQuery.fx.off).toBe(false);

		query.change(true);
		expect(window.jQuery.fx.off).toBe(true);
	});

	it('leaves animations alone when the reader has no preference', () => {
		window.matchMedia = () => fakeQuery(false);
		window.jQuery.fx.off = false;

		loadScript('assets/js/ffc-core.js');
		expect(window.jQuery.fx.off).toBe(false);
	});

	it('never switches back on what another script switched off', () => {
		const query = fakeQuery(true);
		window.matchMedia = () => query;
		window.jQuery.fx.off = true;

		loadScript('assets/js/ffc-core.js');
		query.change(false);
		expect(window.jQuery.fx.off).toBe(true);
	});
});
