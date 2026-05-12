// Test helper — load an IIFE-wrapped jQuery script from `assets/js/` into
// the current jsdom window so its globals (`window.FFCGeofence`, etc.)
// become available for the test to call.
//
// Strategy: read the file as text, evaluate it via `new Function` with
// `jQuery` and `$` bound as parameters. This preserves global
// semantics (`window.X = ...` inside the script lands on jsdom's window)
// without paying the cost of injecting a `<script>` tag and waiting for
// jsdom's resource loader.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

export function loadScript(relativePath) {
	const code = readFileSync(resolve(process.cwd(), relativePath), 'utf8');
	// eslint-disable-next-line no-new-func
	new Function('jQuery', '$', code)(globalThis.jQuery, globalThis.$);
}
