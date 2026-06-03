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

				// Architecturally-excluded from coverage (#165 / Sprint L).
				// Each entry has a structural reason for not being tested
				// with the current Vitest + jsdom setup. Revisit if the
				// underlying dependency stops being the blocker.
				'assets/js/ffc-admin-migrations.js',     // DB-mutating; manual testing only.
				'assets/js/ffc-admin-code-editor.js',    // Thin CodeMirror wrapper.
				'assets/js/ffc-admin-pdf.js',            // Coupled to html2canvas + jsPDF.
				'assets/js/ffc-pdf-generator.js',        // Coupled to html2canvas + jsPDF.
				'assets/js/ffc-calendar-frontend.js',    // Built on FullCalendar plugin API.
			],
		},
	},
});
