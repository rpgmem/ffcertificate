// Vitest setup — installs real jQuery on the jsdom window before any
// script-under-test loads.
//
// Until S3 of #163, this file shipped a Proxy stub that returned chainable
// no-ops. That worked for pure helpers (`validateDateTime`, `pickHideMode`,
// `analyzeDateTimeOrder`) which never go through jQuery, but lied for any
// code that actually depends on jQuery semantics (`.val()` returning the
// input's value, `.is(':checked')` reading the actual DOM, etc.). With
// dashboard-panel tests landing in S4, the stub would have become a
// half-baked jQuery clone — real jQuery is cheaper to install and tells
// the truth.
//
// `jquery` is loaded as a factory (`require('jquery')(window)`) and bound
// to `window.jQuery` and `window.$`. Vitest's jsdom env exposes that
// window on `globalThis`, so unqualified `jQuery` / `$` references inside
// the IIFE scripts resolve to the real implementation.
// jQuery 4 separates the bound-to-window default export (used by browsers
// and Node-with-jsdom-auto-detect) from the explicit factory (`jquery/factory`)
// that takes a window. The factory form lets us bind jQuery to the SAME
// jsdom window Vitest set up, so events / data caches the IIFE scripts
// install are visible to the tests' jQuery operations on the same DOM.
import { jQueryFactory } from 'jquery/factory';
import { vi } from 'vitest';

const jq = jQueryFactory( globalThis.window );
globalThis.window.jQuery = jq;
globalThis.window.$ = jq;
globalThis.jQuery = jq;
globalThis.$ = jq;

// `alert()` is not implemented in jsdom — it throws "Not implemented".
// ffc-geofence-admin.js calls it from `validateGeoMethods()`; spy on it
// so tests can assert and don't crash.
globalThis.alert = vi.fn();
globalThis.window.alert = globalThis.alert;
