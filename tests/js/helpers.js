// Test helper — load an IIFE-wrapped jQuery script from `assets/js/` into
// the current jsdom window so its globals (`window.FFCGeofence`, etc.)
// become available for the test to call.
//
// Strategy: read the file as text and evaluate it via `vm.runInThisContext`
// with the absolute file path as `filename`. Two reasons over the previous
// `new Function(code)(...)` form:
//
//   1. Coverage attribution — V8's coverage profiler (used by
//      `@vitest/coverage-v8` in S1 of #163) attributes executed lines by
//      source filename; `new Function` strips the file identity so every
//      assets/js/ file reported 0% even when its IIFE ran. `runInThisContext`
//      preserves the path.
//   2. Same-context globals — `runInThisContext` evaluates in the current
//      V8 context (jsdom env's globalThis), so the IIFE sees `window`,
//      `document`, `jQuery`, and `$` as proper globals just like a browser
//      `<script>` would.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { runInThisContext } from 'node:vm';

export function loadScript(relativePath) {
	const abs = resolve(process.cwd(), relativePath);
	const code = readFileSync(abs, 'utf8');
	runInThisContext(code, { filename: abs });
}
