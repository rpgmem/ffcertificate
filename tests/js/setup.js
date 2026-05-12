// Vitest setup — minimal jQuery stub installed before any script loads so
// the IIFE wrappers in our assets/js/ files don't crash on parse.
//
// Two patterns appear in the codebase:
//   1. `(function($) { ... })(jQuery)` — IIFE runs immediately; the stub
//      just needs to exist when the file evaluates.
//   2. `jQuery(document).ready(function($) { ... })` — the callback is
//      passed to `.ready()` and only runs once jsdom's DOMContentLoaded
//      fires. Our stub special-cases `.ready` to call the callback
//      synchronously with the stub itself as `$`.
//
// Tests then exercise the pure helpers via `window.FFC*` globals — they
// don't go through jQuery.
import { vi } from 'vitest';

function makeChain() {
	const chain = {
		ready(cb) {
			if (typeof cb === 'function') {
				cb($stub);
			}
			return chain;
		},
		on() { return chain; },
		off() { return chain; },
		find() { return chain; },
		closest() { return chain; },
		val() { return ''; },
		text() { return chain; },
		hide() { return chain; },
		show() { return chain; },
		is() { return false; },
		prop() { return chain; },
		toggleClass() { return chain; },
		addClass() { return chain; },
		removeClass() { return chain; },
		css() { return chain; },
		attr() { return chain; },
		data() { return chain; },
		each() { return chain; },
		slideDown() { return chain; },
		slideUp() { return chain; },
		focus() { return chain; },
	};

	return new Proxy(chain, {
		get(target, prop) {
			if (prop in target) return target[prop];
			if (prop === 'length') return 0;
			return () => chain;
		},
	});
}

const $stub = function () { return makeChain(); };
$stub.fn = {};
$stub.extend = Object.assign;
$stub.ready = (cb) => { if (typeof cb === 'function') cb($stub); };

globalThis.jQuery = $stub;
globalThis.$ = $stub;

// Stub for `alert()` used by ffc-geofence-admin — jsdom doesn't implement it.
globalThis.alert = vi.fn();
