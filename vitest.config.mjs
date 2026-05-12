// Vitest config — jsdom environment so the IIFE-wrapped jQuery scripts in
// `assets/js/` can be loaded into a browser-like context for unit testing
// their pure helpers (#161 S2).
import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: {
		environment: 'jsdom',
		include: ['tests/js/**/*.test.js'],
		setupFiles: ['tests/js/setup.js'],
	},
});
