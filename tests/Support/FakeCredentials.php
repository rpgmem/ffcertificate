<?php
/**
 * The passwords, keys and secrets every test fixture uses.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * One home for the credentials tests pretend to hold (#2637).
 *
 * WHY EVERY VALUE STARTS WITH `fake-`
 *
 * A secret sweep of the repository found no real credential, but it had to
 * read every hit to say so: `s3cret`, `open-sesame`, `admin`/`admin` and
 * `wordpress` look the same as a password pasted by mistake. The prefix is what
 * lets a reviewer, or a secret scanner, tell a fixture from a credential at a
 * glance, and `FakeCredentialStandardTest` holds every credential literal in
 * the tests, the seed scripts and the workflows to it.
 *
 * WHAT IS NOT HERE
 *
 * A value that is the test's SUBJECT rather than a credential: a password too
 * short to accept, a value with the characters an encoder must escape. Those
 * keep their shape, prefixed where they are long enough to read as a
 * credential. And a record's own link tokens (`magic_token`,
 * `confirmation_token`), which the plugin generates per record and nobody holds
 * as a login.
 *
 * The JS tests read the same values from `tests/js/fake-credentials.js`; the
 * guard holds the two to each other.
 */
final class FakeCredentials {

	/**
	 * An account password.
	 */
	public const PASSWORD = 'fake-password';

	/**
	 * A second account password, for a change from one to the other.
	 */
	public const PASSWORD_NEW = 'fake-password-new';

	/**
	 * A password that does not match the account's.
	 */
	public const PASSWORD_WRONG = 'fake-password-wrong';

	/**
	 * A stored SMTP password.
	 */
	public const SMTP_PASSWORD = 'fake-smtp-password';

	/**
	 * A second SMTP password, for a change from one to the other.
	 */
	public const SMTP_PASSWORD_NEW = 'fake-smtp-password-new';

	/**
	 * A third-party API key.
	 */
	public const API_KEY = 'fake-api-key';

	/**
	 * A value that must never leave the server.
	 */
	public const SECRET = 'fake-secret';

	/**
	 * A Wi-Fi network password in a QR payload.
	 */
	public const WIFI_PASSWORD = 'fake-wifi-password';

	/**
	 * A form's access password.
	 */
	public const FORM_PASSWORD = 'fake-form-password';

	/**
	 * The CI database's root password (`.github/workflows/ci.yml`).
	 */
	public const DB_PASSWORD = 'fake-db-password';

	/**
	 * The CI WordPress administrator's password (`.github/workflows/ci.yml`).
	 */
	public const ADMIN_PASSWORD = 'fake-admin-password';

	/**
	 * The screenshot site's demo participant (`.github/scripts/screenshots/seed.php`).
	 */
	public const DEMO_PASSWORD = 'fake-demo-participant';

	/**
	 * The WordPress keys `tests/bootstrap.php` defines, so `Encryption` finds a
	 * configured key. Each is long enough for the derivation it feeds.
	 */
	public const AUTH_KEY = 'fake-auth-key-for-unit-tests';

	/**
	 * See {@see self::AUTH_KEY}.
	 */
	public const SECURE_AUTH_KEY = 'fake-secure-auth-key-for-unit-tests-only-32ch';

	/**
	 * See {@see self::AUTH_KEY}.
	 */
	public const LOGGED_IN_KEY = 'fake-logged-in-key-for-unit-tests-only-32char';

	/**
	 * See {@see self::AUTH_KEY}.
	 */
	public const NONCE_KEY = 'fake-nonce-key-for-unit-tests';

	/**
	 * A password `wp_generate_password()` returns for a new account.
	 */
	public const GENERATED_PASSWORD = 'fake-generated-password';

	/**
	 * A form's access password after a change.
	 */
	public const FORM_PASSWORD_NEW = 'fake-form-password-new';

	/**
	 * A password that does not match the form's.
	 */
	public const FORM_PASSWORD_WRONG = 'fake-form-password-wrong';

	/**
	 * A form's public CSV access hash.
	 */
	public const CSV_ACCESS_HASH = 'fake-csv-access-hash';

	/**
	 * A CSV access hash that is not the form's.
	 */
	public const CSV_ACCESS_HASH_WRONG = 'fake-csv-access-hash-wrong';

	/**
	 * What a `wp_salt()` stub returns.
	 */
	public const WP_SALT = 'fake-wp-salt';

	/**
	 * What a `wp_salt()` stub returns after the site's salts change.
	 */
	public const WP_SALT_ROTATED = 'fake-wp-salt-rotated';

	/**
	 * Every value, by name.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return ( new \ReflectionClass( self::class ) )->getConstants();
	}
}
