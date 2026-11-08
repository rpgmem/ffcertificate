// Vitest config — jsdom environment so the IIFE-wrapped jQuery scripts in
// `assets/js/` can be loaded into a browser-like context for unit testing
// their pure helpers (#161 S2).
//
// Coverage was added in S1 of #163 so a regression in JS coverage gates
// like its PHP counterpart in ci.yml.
import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: {
		environment: 'jsdom',
		include: ['tests/js/**/*.test.js'],
		setupFiles: ['tests/js/setup.js'],
		coverage: {
			provider: 'v8',
			reporter: ['text', 'clover', 'html'],
			reportsDirectory: './coverage-js',
			include: ['assets/js/**/*.js'],
			exclude: [
				'assets/js/*.min.js',
				'assets/js/*.min.js.map',
			],
		},
	},
});
