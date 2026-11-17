// The passwords, keys and secrets the JS tests use (#2637).
//
// The same values as tests/Support/FakeCredentials.php, which explains why
// every one starts with `fake-`; FakeCredentialStandardTest holds the two
// files to each other.
export const FakeCredentials = Object.freeze({
	PASSWORD: 'fake-password',
	PASSWORD_NEW: 'fake-password-new',
	PASSWORD_WRONG: 'fake-password-wrong',
	SMTP_PASSWORD: 'fake-smtp-password',
	SMTP_PASSWORD_NEW: 'fake-smtp-password-new',
	API_KEY: 'fake-api-key',
	SECRET: 'fake-secret',
	WIFI_PASSWORD: 'fake-wifi-password',
	FORM_PASSWORD: 'fake-form-password',
	DB_PASSWORD: 'fake-db-password',
	ADMIN_PASSWORD: 'fake-admin-password',
	DEMO_PASSWORD: 'fake-demo-participant',
	AUTH_KEY: 'fake-auth-key-for-unit-tests',
	SECURE_AUTH_KEY: 'fake-secure-auth-key-for-unit-tests-only-32ch',
	LOGGED_IN_KEY: 'fake-logged-in-key-for-unit-tests-only-32char',
	NONCE_KEY: 'fake-nonce-key-for-unit-tests',
	GENERATED_PASSWORD: 'fake-generated-password',
	FORM_PASSWORD_NEW: 'fake-form-password-new',
	FORM_PASSWORD_WRONG: 'fake-form-password-wrong',
	CSV_ACCESS_HASH: 'fake-csv-access-hash',
	CSV_ACCESS_HASH_WRONG: 'fake-csv-access-hash-wrong',
	WP_SALT: 'fake-wp-salt',
	WP_SALT_ROTATED: 'fake-wp-salt-rotated',
});
