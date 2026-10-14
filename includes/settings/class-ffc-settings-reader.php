<?php
/**
 * Settings Reader.
 *
 * Centralized accessor for `ffc_settings` option reads.
 *
 * @package FreeFormCertificate\Settings
 * @since   6.6.1
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings;

use FreeFormCertificate\Core\ArrayValue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-side facade over the `ffc_settings` WP option.
 *
 * WordPress already caches the option via the autoloaded-options cache
 * (`wp_load_alloptions()`) — so this class adds no perf layer of its
 * own. Its job is:
 *
 *   1. Single source of truth for the option key.
 *   2. Typed accessors with explicit casts + defaults for the
 *      high-value boolean / integer keys, replacing scattered
 *      `$settings['key'] ?? default` patterns.
 *   3. Generic `get()` for the long tail of keys that don't warrant
 *      a dedicated typed accessor yet.
 *
 * Debug-area toggles are NOT exposed here — they're already typed via
 * {@see \FreeFormCertificate\Core\Debug::is_enabled()}, which is the
 * canonical reader for that subset.
 *
 * INVARIANT — every key here belongs to `ffc_settings`. Three sibling
 * facades read their own option (`GeolocationSettingsReader`,
 * `RateLimitSettingsReader`, `IpDiagnosticsSettingsReader`), and
 * `AccessControl` owns `ffc_user_access_settings`. An accessor added here
 * for one of *their* keys compiles, passes review and always returns its
 * hardcoded default, because nothing ever writes that key into this option
 * — seven of them had accumulated that way and were removed in #1123, four
 * of which duplicated a correct accessor on `GeolocationSettingsReader`.
 * Nothing had called any of them, which is the only reason it never showed:
 * the first caller would have inherited a setting frozen at its default.
 * `SettingsDefaultsTest` now watches the key set from the outside.
 */
final class SettingsReader {

	/**
	 * Tags a certificate layout must carry when nothing was saved (#1741).
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_CERTIFICATE_TAGS = array( '{{auth_code}}', '{{name}}', '{{cpf_rf}}', '{{schedule}}' );

	/**
	 * Types a required form field may take: the ones that hold one typed
	 * answer a certificate can print.
	 *
	 * @var array<int, string>
	 */
	public const REQUIRED_FIELD_TYPES = array( 'text', 'textarea', 'email', 'number', 'date' );

	/**
	 * Tags the plugin fills in itself, never from a form field.
	 *
	 * @var array<int, string>
	 */
	public const GENERATED_TAGS = array(
		'auth_code',
		'validation_code',
		'magic_token',
		'submission_date',
		'print_date',
		'form_title',
		'schedule',
		'score',
		'max_score',
		'score_percent',
		'correct_count',
		'question_count',
	);

	/**
	 * WP option key that backs every read in this class.
	 */
	public const OPTION_KEY = 'ffc_settings';

	// ──────────────────────────────────────────────────────────────.
	// Generic accessors.
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Read a value from `ffc_settings`.
	 *
	 * @param string $key     Settings key.
	 * @param mixed  $default Value returned when the key is absent.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$settings = self::all();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Return the raw `ffc_settings` array. Prefer the typed accessors
	 * or `get()` when reading 1-2 keys; reach for `all()` only when a
	 * caller reads 5+ keys from the same array and array-style access
	 * stays clearer than repeated method calls (e.g. SMTP config block).
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$settings = get_option( self::OPTION_KEY, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Bool-typed read.
	 *
	 * Refuses a non-scalar rather than casting it — see the family rule on
	 * {@see self::get_string()}. For every scalar the result is the plain
	 * `(bool)` cast this accessor has always applied.
	 *
	 * @param string $key     Settings key.
	 * @param bool   $default Returned when the key is absent or not scalar.
	 * @return bool
	 */
	public static function get_bool( string $key, bool $default = false ): bool {
		$value = self::get( $key, $default );
		return is_scalar( $value ) ? (bool) $value : $default;
	}

	/**
	 * Int-typed read.
	 *
	 * Refuses a non-scalar rather than casting it — see the family rule on
	 * {@see self::get_string()}. For every scalar the result is the plain
	 * `(int)` cast this accessor has always applied, so a non-numeric string
	 * still reads as 0 here (unlike {@see ArrayValue::int()}, which returns
	 * the default). That difference is deliberate: `ArrayValue` reads foreign
	 * data, while these three read an option the plugin itself sanitises on
	 * save, and narrowing the scalar path would change what every existing
	 * caller gets back.
	 *
	 * @param string $key     Settings key.
	 * @param int    $default Returned when the key is absent or not scalar.
	 * @return int
	 */
	public static function get_int( string $key, int $default = 0 ): int {
		$value = self::get( $key, $default );
		return is_scalar( $value ) ? (int) $value : $default;
	}

	/**
	 * String-typed read.
	 *
	 * **The family rule, which all three typed accessors follow:** a scalar is
	 * cast, anything else returns the default. A setting that reads as the word
	 * `Array` is worse than one that reads as its default, and the int and bool
	 * casts are worse still because they are *silent* — `(int) array( '45' )`
	 * is `1` and `(bool) array( 0 )` is `true`, with no notice to find later.
	 * That is the #1060 defect class, twice over.
	 *
	 * The three sat on two semantics until #1084 closed the epic: this one
	 * refused a non-scalar while its siblings cast blind. Nothing the plugin
	 * writes into `ffc_settings` is non-scalar, so aligning them changed no
	 * stored value — it only decided what happens when something else puts an
	 * array there.
	 *
	 * @param string $key     Settings key.
	 * @param string $default Returned when the key is absent or not scalar.
	 * @return string
	 */
	public static function get_string( string $key, string $default = '' ): string {
		return ArrayValue::string( self::all(), $key, $default );
	}

	// ──────────────────────────────────────────────────────────────.
	// Typed bool accessors (high-value keys).
	// ──────────────────────────────────────────────────────────────.

	/** Whether the "disable all outgoing emails" master toggle is on. */
	public static function emails_disabled(): bool {
		return self::get_bool( 'disable_all_emails' );
	}

	/** Whether the activity-log subsystem is enabled. */
	public static function activity_log_enabled(): bool {
		return self::get_bool( 'enable_activity_log' );
	}

	/**
	 * Minimum level the activity log records (debug < info < warning < error).
	 * Defaults to `debug` (log everything).
	 *
	 * @return string One of 'debug' | 'info' | 'warning' | 'error'.
	 */
	public static function activity_log_min_level(): string {
		$level = (string) self::get( 'activity_log_min_level', 'debug' );
		return in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ? $level : 'debug';
	}

	/**
	 * Whether a given activity-log category is enabled. Defaults to true.
	 *
	 * @param string $category Category key (see ActivityLog::CATEGORIES).
	 * @return bool
	 */
	public static function activity_log_category_enabled( string $category ): bool {
		return self::get_bool( 'activity_log_cat_' . $category, true );
	}

	/**
	 * Certificate-layout tags that every form's PDF layout must contain.
	 *
	 * Every row of the "Required data" table on Settings → Certificates
	 * whose HTML switch is on (#1759). Until that table is built, the
	 * legacy tag list is read instead ({@see self::legacy_certificate_tags()}).
	 * `{{auth_code}}` is always required (certificate verification breaks
	 * without it) and is force-injected even if removed.
	 *
	 * @return array<int, string> Unique `{{tag}}` tokens.
	 */
	public static function required_certificate_tags(): array {
		$table = RequiredData::stored();
		if ( null === $table ) {
			return self::legacy_certificate_tags();
		}
		$tags = array( '{{' . RequiredData::LOCKED_TAG . '}}' );
		foreach ( $table as $row ) {
			if ( $row['html'] ) {
				$tags[] = '{{' . $row['name'] . '}}';
			}
		}
		return array_values( array_unique( $tags ) );
	}

	/**
	 * The tag list as the pre-#1759 `required_certificate_tags` setting
	 * gives it: a newline/comma list of `{{tag}}` tokens, the historical
	 * defaults when nothing is saved, `{{auth_code}}` force-injected first.
	 *
	 * Read only until the "Required data" table is built, and by the
	 * migration that builds it ({@see RequiredData::from_legacy()}).
	 *
	 * @return array<int, string> Unique `{{tag}}` tokens.
	 */
	public static function legacy_certificate_tags(): array {
		$raw = self::get( 'required_certificate_tags', '' );
		if ( is_array( $raw ) ) {
			$lines = $raw;
		} else {
			// A tag never contains a space, so whitespace separates too:
			// the Save button stored the list on one line until #1736.
			$lines = preg_split( '/[\s,]+/', (string) $raw );
			if ( false === $lines ) {
				$lines = array();
			}
		}
		$tags = array();
		foreach ( $lines as $line ) {
			$tag = trim( (string) $line );
			if ( '' !== $tag ) {
				$tags[] = $tag;
			}
		}
		if ( empty( $tags ) ) {
			$tags = self::DEFAULT_CERTIFICATE_TAGS;
		} elseif ( ! is_array( self::get( 'required_form_fields' ) ) && ! in_array( '{{schedule}}', $tags, true ) ) {
			// A list saved before the two settings split (#1741) never named
			// {{schedule}}: the layout check added it on its own whenever the
			// form had an Event Schedule. Keep that until the tab is saved again.
			$tags[] = '{{schedule}}';
		}
		if ( ! in_array( '{{auth_code}}', $tags, true ) ) {
			array_unshift( $tags, '{{auth_code}}' );
		}
		return array_values( array_unique( $tags ) );
	}

	/**
	 * Fields every certificate form must have (#1741): variable name,
	 * default label and type.
	 *
	 * Every row of the "Required data" table on Settings → Certificates
	 * whose form switch is on (#1759). Until that table is built, the
	 * legacy field table is read instead ({@see self::legacy_form_fields()}).
	 * `email` is always required: the submission needs it and the
	 * certificate is sent to it, so it is force-injected, as an Email
	 * field, even if removed.
	 *
	 * @return array<int, array{name: string, label: string, type: string}>
	 */
	public static function required_form_fields(): array {
		$table = RequiredData::stored();
		if ( null === $table ) {
			return self::legacy_form_fields();
		}
		$rows = array();
		foreach ( $table as $row ) {
			if ( $row['form'] ) {
				$rows[ $row['name'] ] = array(
					'name'  => $row['name'],
					'label' => $row['label'],
					'type'  => $row['type'],
				);
			}
		}
		return self::with_email( $rows );
	}

	/**
	 * The field table as the pre-#1759 `required_form_fields` setting gives
	 * it. An install that never saved that table keeps the fields its old
	 * tag list required, as Text fields.
	 *
	 * Read only until the "Required data" table is built, and by the
	 * migration that builds it ({@see RequiredData::from_legacy()}).
	 *
	 * @return array<int, array{name: string, label: string, type: string}>
	 */
	public static function legacy_form_fields(): array {
		$saved = self::get( 'required_form_fields' );
		$rows  = array();

		if ( is_array( $saved ) ) {
			foreach ( $saved as $row ) {
				$row = self::form_field_row( $row );
				if ( null !== $row ) {
					$rows[ $row['name'] ] = $row;
				}
			}
		} else {
			foreach ( self::default_form_fields() as $row ) {
				$rows[ $row['name'] ] = $row;
			}
			foreach ( self::legacy_field_names() as $name ) {
				if ( ! isset( $rows[ $name ] ) ) {
					$rows[ $name ] = array(
						'name'  => $name,
						'label' => $name,
						'type'  => 'text',
					);
				}
			}
		}

		return self::with_email( $rows );
	}

	/**
	 * Force the Email field into a field table, keeping its label and place
	 * when the table has it and appending it otherwise.
	 *
	 * @param array<string, array{name: string, label: string, type: string}> $rows Rows keyed by name.
	 * @return array<int, array{name: string, label: string, type: string}>
	 */
	private static function with_email( array $rows ): array {
		$email         = $rows['email'] ?? array( 'label' => __( 'Email', 'ffcertificate' ) );
		$rows['email'] = array(
			'name'  => 'email',
			'label' => (string) $email['label'],
			'type'  => 'email',
		);

		return array_values( $rows );
	}

	/**
	 * The table a fresh install starts with.
	 *
	 * @return array<int, array{name: string, label: string, type: string}>
	 */
	public static function default_form_fields(): array {
		return array(
			array(
				'name'  => 'name',
				'label' => __( 'Full Name', 'ffcertificate' ),
				'type'  => 'text',
			),
			array(
				'name'  => 'cpf_rf',
				'label' => __( 'CPF / RF', 'ffcertificate' ),
				'type'  => 'text',
			),
			array(
				'name'  => 'email',
				'label' => __( 'Email', 'ffcertificate' ),
				'type'  => 'email',
			),
		);
	}

	/**
	 * One stored table row, or null when it names no usable field.
	 *
	 * @param mixed $row Stored row.
	 * @return array{name: string, label: string, type: string}|null
	 */
	private static function form_field_row( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$name = strtolower( ArrayValue::string( $row, 'name' ) );
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $name ) ) {
			return null;
		}
		$type = ArrayValue::string( $row, 'type', 'text' );
		return array(
			'name'  => $name,
			'label' => '' !== ArrayValue::string( $row, 'label' ) ? ArrayValue::string( $row, 'label' ) : $name,
			'type'  => in_array( $type, self::REQUIRED_FIELD_TYPES, true ) ? $type : 'text',
		);
	}

	/**
	 * Field names the single pre-#1741 list required: each `{{tag}}` the
	 * plugin does not generate itself.
	 *
	 * @return array<int, string>
	 */
	private static function legacy_field_names(): array {
		$raw = self::get( 'required_certificate_tags', '' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		$names = array();
		foreach ( (array) preg_split( '/[\s,]+/', $raw ) as $tag ) {
			if ( 1 === preg_match( '/^\{\{([a-z0-9_]+)\}\}$/i', trim( (string) $tag ), $m ) ) {
				$name = strtolower( $m[1] );
				if ( ! in_array( $name, self::GENERATED_TAGS, true ) && 0 !== strpos( $name, 'qr_code' ) && 'nome' !== $name ) {
					$names[] = $name;
				}
			}
		}
		return $names;
	}

	/**
	 * Whether deleting the plugin (uninstall.php) should drop tables /
	 * options / CPT posts / roles / caps / user meta.
	 *
	 * Defaults to FALSE to match the WooCommerce / EDD / Yoast convention
	 * — a plain "Delete plugin" click preserves data unless the admin
	 * opted in via Settings → Advanced → Danger Zone. uninstall.php reads
	 * the raw option directly (no autoloader available there); this
	 * accessor exists for code paths that run inside a normal request.
	 */
	public static function delete_data_on_uninstall(): bool {
		return self::get_bool( 'delete_data_on_uninstall' );
	}

	/** Whether the QR-code cache is enabled. */
	public static function qr_cache_enabled(): bool {
		return self::get_bool( 'qr_cache_enabled' );
	}

	/**
	 * Whether the URL-shortener feature is enabled. Defaults to true
	 * (the feature is on out-of-the-box).
	 */
	public static function url_shortener_enabled(): bool {
		return self::get_bool( 'url_shortener_enabled', true );
	}

	/**
	 * Whether auto-creation of short URLs is enabled. Defaults to true.
	 */
	public static function url_shortener_auto_create_enabled(): bool {
		return self::get_bool( 'url_shortener_auto_create', true );
	}

	// ──────────────────────────────────────────────────────────────.
	// Feature-module on/off toggles (the "Modules" settings tab).
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Canonical feature-module slugs the Modules settings tab can toggle,
	 * in display order — the order of the admin menu, so the tab lists the
	 * modules as the sidebar does (`MenuIconAgreementTest`, #1640). Each gates
	 * the matching bootstrap in
	 * {@see \FreeFormCertificate\Loader::init_plugin()}.
	 *
	 * @var array<int, string>
	 */
	public const MODULE_SLUGS = array(
		'certificates',
		'self_scheduling',
		'audiences',
		'reregistration',
		'recruitment',
		'url_shortener',
		'date_messages',
	);

	/**
	 * The `ffc_settings` key backing a module's on/off toggle.
	 *
	 * URL Shortener keeps its historical `url_shortener_enabled` slot (already
	 * read by {@see UrlShortenerService::is_enabled()} and the URL Shortener
	 * settings tab); every other module uses `module_<slug>_enabled`. Central
	 * mapping so the reader, the tab UI, and the autosave allowlist agree.
	 *
	 * @param string $module Module slug (see MODULE_SLUGS).
	 * @return string Option key inside `ffc_settings`.
	 */
	public static function module_option_key( string $module ): string {
		return 'url_shortener' === $module
			? 'url_shortener_enabled'
			: 'module_' . $module . '_enabled';
	}

	/**
	 * Whether a feature module is enabled. Every module defaults to ON, so a
	 * fresh install (and any pre-toggle install) behaves exactly as before.
	 *
	 * @param string $module Module slug (see MODULE_SLUGS).
	 * @return bool
	 */
	public static function module_enabled( string $module ): bool {
		return self::get_bool( self::module_option_key( $module ), true );
	}

	/** Whether admins are notified when a capability is granted. */
	public static function notify_capability_grant_enabled(): bool {
		return self::get_bool( 'notify_capability_grant' );
	}

	// ──────────────────────────────────────────────────────────────.
	// Typed int accessors (TTLs / limits).
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Retention window for `ffc_activity_log` rows, in days. Rows
	 * older than this are eligible for the daily cleanup cron.
	 */
	public static function activity_log_retention_days(): int {
		return self::get_int( 'activity_log_retention_days', 90 );
	}

	/**
	 * Window of the reregistration invite's password-set link, in hours.
	 *
	 * The bound lives in `Core\PasswordInvite` (1..720) and is applied on read
	 * as well as on save, as `CLAUDE.md` requires: a value written before a
	 * bound moved still has to land somewhere the code can use. This accessor
	 * returns the stored value; `PasswordInvite` is what clamps it, so the
	 * bound lives in one place only (#1212).
	 */
	public static function invite_password_link_hours(): int {
		return self::get_int( 'invite_password_link_hours', 48 );
	}

	/** TTL for the WP object cache entries the plugin manages. */
	public static function cache_expiration_seconds(): int {
		return self::get_int( 'cache_expiration', 3600 );
	}

	/**
	 * Number of days after which an obsolete shortcode reference
	 * surfaces a warning in the admin notices stream.
	 */
	public static function obsolete_shortcode_days(): int {
		return self::get_int( 'obsolete_shortcode_days', 90 );
	}

	/** Default row count returned by the public CSV download endpoint. */
	public static function public_csv_default_limit(): int {
		return self::get_int( 'public_csv_default_limit', 1 );
	}

	/** Hard ceiling on rows the synchronous CSV export will emit. */
	public static function public_csv_sync_max_rows(): int {
		return self::get_int( 'public_csv_sync_max_rows', 5000 );
	}

	/**
	 * How long a quiz window may lose the focus before the Strict level
	 * counts it as leaving, in milliseconds (#1745). Clamped to
	 * {@see \FreeFormCertificate\Core\QuizLeaveLevel}'s bounds; the default is
	 * its GRACE_DEFAULT_MS, written as a literal so the defaults guard can read it.
	 */
	public static function quiz_focus_grace_ms(): int {
		return \FreeFormCertificate\Core\QuizLeaveLevel::clamp_grace( self::get_int( 'quiz_focus_grace_ms', 1000 ) );
	}

	/** Default pixel size for generated QR codes. */
	public static function qr_default_size(): int {
		return self::get_int( 'qr_default_size', 200 );
	}

	/** Length (characters) of generated short-URL slugs. */
	public static function url_shortener_code_length(): int {
		return self::get_int( 'url_shortener_code_length', 6 );
	}
}
