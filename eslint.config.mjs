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
				wpApiSettings: 'readonly',

				// Plugin-specific globals provided via wp_localize_script.
				ffcAdmin: 'readonly',
				ffc_admin: 'readonly',
				ffc_ajax: 'readonly',
				ffc_csv_download: 'readonly',
				ffc_geofence_admin: 'readonly',
				ffc_submission_edit: 'readonly',
				ffcGeofenceConfig: 'readonly',
				ffcFrontend: 'readonly',
				ffc_frontend: 'readonly',
				ffcDashboard: 'readonly',
				ffcDashboardConfig: 'readonly',
				ffcRecruitment: 'readonly',
				ffcRecruitmentConfig: 'readonly',
				ffcAudience: 'readonly',
				ffcAudienceAdmin: 'readonly',
				ffcAudienceConfig: 'readonly',
				ffcAppointments: 'readonly',
				ffcAppointmentsConfig: 'readonly',
				ffcCalendarFrontend: 'readonly',
				ffcCertificates: 'readonly',
				ffcCertificatesConfig: 'readonly',
				ffcCalendar: 'readonly',
				ffcCalendarConfig: 'readonly',
				ffcCsvDownload: 'readonly',
				ffcCsvDownloadConfig: 'readonly',
				ffcDynamic: 'readonly',
				ffcMigrations: 'readonly',
				ffcReceiptData: 'readonly',
				ffcRegistration: 'readonly',
				ffcReregistration: 'readonly',
				ffcReregistrationAdmin: 'readonly',
				ffcReregistrationConfig: 'readonly',
				ffcSelfSchedulingEditor: 'readonly',
				ffcPdfGenerator: 'readonly',
				ffcSmtpSettings: 'readonly',
				ffcDarkMode: 'readonly',
				ffcDeviceSignals: 'readonly',
				ffcDynamicFragments: 'readonly',
				ffcAlreadySubmittedNotice: 'readonly',
				ffcUserCapabilities: 'readonly',
				ffcFrontendHelpers: 'readonly',

				// Globals the IIFEs assign back to `window`.
				FFCGeofence: 'writable',
				FFCDashboard: 'writable',
				FFCAudience: 'writable',
				FFCCalendar: 'writable',
				FFCCalendarCore: 'writable',
				FFC: 'writable',

				// Third-party.
				html2canvas: 'readonly',
				jsPDF: 'readonly',
				QRCode: 'readonly',
				moment: 'readonly',
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

			// Style rules: deliberately off — too noisy on legacy code.
			'no-var': 'off',
			'prefer-const': 'off',
			'no-console': 'off',
		},
	},
];
