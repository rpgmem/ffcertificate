// ESLint 9 flat config — lightweight bug-catching for our jQuery-based,
// IIFE-wrapped JS. Permissive on style (28 source files with mixed `var`/
// `const`/single-vs-double-quote conventions, not worth normalising right
// now); strict on actual bugs (`no-undef`, dupes, unreachable, eqeqeq).
import globals from 'globals';

export default [
	{
		ignores: [
			'assets/js/*.min.js',
			'assets/js/*.min.js.map',
			'node_modules/**',
			'vendor/**',
			'build/**',
		],
	},
	{
		files: ['assets/js/**/*.js'],
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: 'script',
			globals: {
				...globals.browser,
				...globals.jquery,

				// WordPress core globals.
				wp: 'readonly',
				ajaxurl: 'readonly',

				// Plugin globals read bare, provided via wp_localize_script.
				// The list is what the code reads, and nothing else (#2344):
				// a name declared here that no script reads hides a typo in
				// one that does. Re-measure by linting without this block and
				// keeping the names \`no-undef\` reports.
				ffc_ajax: 'readonly',
				ffc_csv_download: 'readonly',
				ffc_geofence_admin: 'readonly',
				ffc_submission_edit: 'readonly',
				ffcAudience: 'readonly',
				ffcAudienceAdmin: 'readonly',
				ffcCalendar: 'readonly',
				ffcCalendarFrontend: 'readonly',
				ffcDarkMode: 'readonly',
				ffcDashboard: 'readonly',
				ffcDynamic: 'readonly',
				ffcGeofenceConfig: 'readonly',
				ffcReceiptData: 'readonly',
				ffcReregistration: 'readonly',
				ffcReregistrationAdmin: 'readonly',
				ffcSelfSchedulingEditor: 'readonly',

				// Globals the IIFEs assign back to \`window\`.
				FFCDashboard: 'writable',
				FFCCalendarCore: 'writable',
				FFC: 'writable',

				// Third-party.
				html2canvas: 'readonly',
			},
		},
		rules: {
			// Real-bug rules (strict).
			'no-undef': 'error',
			'no-unused-vars': ['warn', {
				argsIgnorePattern: '^_',
				varsIgnorePattern: '^_',
				caughtErrors: 'none',
			}],
			'no-unreachable': 'error',
			'no-dupe-keys': 'error',
			'no-dupe-args': 'error',
			'no-dupe-else-if': 'error',
			'no-duplicate-case': 'error',
			'no-redeclare': ['error', { builtinGlobals: false }],
			'no-fallthrough': 'error',
			'no-cond-assign': ['error', 'except-parens'],
			'valid-typeof': 'error',
			'use-isnan': 'error',
			'no-irregular-whitespace': 'error',
			'no-self-assign': 'error',
			'no-self-compare': 'error',
			'no-unsafe-negation': 'error',
			'no-unsafe-finally': 'error',
			'no-unused-expressions': ['error', { allowShortCircuit: true, allowTernary: true }],
			'eqeqeq': ['error', 'smart'],
			// A top-level declaration in a classic script becomes a property
			// of \`window\`, shared with every other script on the page (#2344).
			'no-implicit-globals': 'error',

			// Style rules: deliberately off — too noisy on legacy code.
			'no-var': 'off',
			'prefer-const': 'off',
			'no-console': 'off',
		},
	},
];
