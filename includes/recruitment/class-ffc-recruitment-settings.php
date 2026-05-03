<?php
/**
 * Recruitment Settings
 *
 * Typed accessors over the single `ffc_recruitment_settings` WP option,
 * registered via WordPress Settings API on `admin_init`. Per §15 of the
 * implementation plan, all module-level configuration lives in one
 * serialized option (matches plugin convention — cf. `ffc_settings`,
 * `ffc_geolocation_settings`, `ffc_rate_limit_settings`).
 *
 * Sub-keys:
 *
 *   - email_subject              Subject template (placeholder-supporting).
 *   - email_from_address         Override; falls back to `wp_mail` default.
 *   - email_from_name            Override; falls back to site name.
 *   - email_body_html            Body template (free-form HTML).
 *   - public_cache_seconds       TTL for the public shortcode transient
 *                                cache (default 60).
 *   - public_rate_limit_per_minute
 *                                Per-IP rate cap for shortcode HTTP
 *                                requests (default 30).
 *   - public_default_page_size   Default page size for both shortcode
 *                                sections (default 50).
 *
 * Sprint 10 reads the email_* keys to render the convocation email;
 * sprint 11 reads the public_* keys when registering the public
 * shortcode. Sprint 12 surfaces the editor UI for these in the admin
 * Settings tab.
 *
 * Uninstall (sprint 1.1's `uninstall.php`) drops the entire option in
 * one `delete_option('ffc_recruitment_settings')` call.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for the recruitment module's configuration.
 *
 * @phpstan-type SettingsArray array{
 *   email_subject:               string,
 *   email_from_address:          string,
 *   email_from_name:             string,
 *   email_body_html:             string,
 *   public_cache_seconds:        int,
 *   public_rate_limit_per_minute: int,
 *   public_default_page_size:    int,
 *   status_color_empty:          string,
 *   status_color_called:         string,
 *   status_color_hired:          string,
 *   status_color_not_shown:      string,
 *   preview_color_empty:           string,
 *   preview_color_denied:          string,
 *   preview_color_granted:         string,
 *   preview_color_appeal_denied:   string,
 *   preview_color_appeal_granted:  string,
 *   preview_reason_required_denied:         bool,
 *   preview_reason_required_granted:        bool,
 *   preview_reason_required_appeal_denied:  bool,
 *   preview_reason_required_appeal_granted: bool,
 *   subscription_color_pcd:                 string,
 *   subscription_color_geral:               string,
 * }
 */
final class RecruitmentSettings {

	/** WP option key holding the serialized settings array. */
	public const OPTION_NAME = 'ffc_recruitment_settings';

	/** Settings group used by the Settings API + admin Settings tab (sprint 12). */
	public const OPTION_GROUP = 'ffc_recruitment_settings_group';

	/**
	 * Register the option with the WordPress Settings API.
	 *
	 * Hooked from {@see RecruitmentLoader} on `admin_init` (priority 10:
	 * canonical hook for `register_setting()` calls; default priority
	 * because no ordering constraint vs other plugins).
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'description'       => 'Recruitment module settings (email templates + public shortcode tuning).',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Default values per sub-key.
	 *
	 * Subject and body defaults are translatable via `__()`. The body
	 * default is a minimal HTML skeleton — sprint 12's CodeMirror editor
	 * lets admins replace it; sprint 10's renderer treats the body as
	 * trusted HTML (admin-only edit surface, gated by
	 * `ffc_manage_recruitment`) so the only sanitization on display is
	 * `wp_kses_post` on the rendered output.
	 *
	 * @return SettingsArray
	 */
	public static function defaults(): array {
		return array(
			'email_subject'                          => __( 'Call - {{notice_code}} - {{adjutancy}}', 'ffcertificate' ),
			'email_from_address'                     => '',
			'email_from_name'                        => '',
			'email_body_html'                        => self::default_body_template(),
			// 12h default: the public listing only changes when admins
			// edit a notice/classification/candidate/adjutancy/call, and
			// every such write hits the cache invalidator on the public
			// shortcode — so a long TTL is safe. The Settings tab still
			// exposes the value for per-deploy overrides.
			'public_cache_seconds'                   => 12 * HOUR_IN_SECONDS,
			'public_rate_limit_per_minute'           => 30,
			'public_default_page_size'               => 50,
			// Status-badge colors on the public shortcode. Each value is
			// an HTML color (#RRGGBB or named); validated on save so a
			// hostile string can't bleed into the stored option.
			'status_color_empty'                     => '#fff3cd',
			'status_color_called'                    => '#e9d8fd',
			'status_color_hired'                     => '#d4edda',
			'status_color_not_shown'                 => '#f8d7da',
			// Preview-list (`list_type='preview'`) badge colors. These
			// are visual-only: they never feed into the §5.2 state machine
			// on the definitive list. The default palette is intentionally
			// neutral — operators are expected to pick semantic colors per
			// deployment (e.g. green for granted, red for denied).
			'preview_color_empty'                    => '#e9ecef',
			'preview_color_denied'                   => '#f8d7da',
			'preview_color_granted'                  => '#d4edda',
			'preview_color_appeal_denied'            => '#f5c6cb',
			'preview_color_appeal_granted'           => '#c3e6cb',
			// Per-status flags controlling whether `preview_reason_id`
			// must be supplied when an admin sets that preview status.
			// `empty` is intentionally NOT a configurable required-flag —
			// it represents "no decision yet" and never carries a reason.
			'preview_reason_required_denied'         => false,
			'preview_reason_required_granted'        => false,
			'preview_reason_required_appeal_denied'  => false,
			'preview_reason_required_appeal_granted' => false,
			// Subscription-type badge colors. The PCD column on the
			// CSV importer is a boolean (PCD vs GERAL) — these two
			// knobs control the badge background per side.
			'subscription_color_pcd'                 => '#fde2e2',
			'subscription_color_geral'               => '#e9ecef',
		);
	}

	/**
	 * Get the full settings array, merged with defaults.
	 *
	 * Used by sprint 10 (email dispatch) and sprint 11 (public shortcode)
	 * to read configuration. The returned array always has every sub-key
	 * present, even on a fresh install where the option doesn't yet exist.
	 *
	 * @return SettingsArray
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$merged = array_merge( self::defaults(), $stored );

		return self::shape( $merged );
	}

	/**
	 * Get a single sub-key, falling back to its default if missing or
	 * stored with the wrong type.
	 *
	 * @param string $key One of the documented sub-keys.
	 * @return mixed Typed value (string for text settings, int for numeric ones).
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Sanitize a settings payload before storage.
	 *
	 * Email body HTML is preserved as-is (admin-trusted edit surface; no
	 * `wp_kses_post` here so admins can use any tags they need). Subject
	 * and From fields go through `sanitize_text_field`. Numeric sub-keys
	 * are coerced to non-negative integers with reasonable upper bounds
	 * to prevent accidental misconfiguration.
	 *
	 * @param mixed $value Incoming option value.
	 * @return SettingsArray Sanitized settings.
	 */
	public static function sanitize( $value ): array {
		if ( ! is_array( $value ) ) {
			return self::defaults();
		}

		$defaults = self::defaults();
		$out      = array();

		$out['email_subject']      = isset( $value['email_subject'] ) && is_string( $value['email_subject'] )
			? sanitize_text_field( $value['email_subject'] )
			: $defaults['email_subject'];
		$out['email_from_address'] = isset( $value['email_from_address'] ) && is_string( $value['email_from_address'] )
			? sanitize_text_field( $value['email_from_address'] )
			: $defaults['email_from_address'];
		$out['email_from_name']    = isset( $value['email_from_name'] ) && is_string( $value['email_from_name'] )
			? sanitize_text_field( $value['email_from_name'] )
			: $defaults['email_from_name'];

		// Body HTML: admin-trusted; preserve all tags admins typed. The
		// rendering path (`wp_mail`) accepts arbitrary HTML.
		$out['email_body_html'] = isset( $value['email_body_html'] ) && is_string( $value['email_body_html'] )
			? $value['email_body_html']
			: $defaults['email_body_html'];

		$out['public_cache_seconds']         = self::clamp_int( $value['public_cache_seconds'] ?? null, 0, 86400, $defaults['public_cache_seconds'] );
		$out['public_rate_limit_per_minute'] = self::clamp_int( $value['public_rate_limit_per_minute'] ?? null, 0, 6000, $defaults['public_rate_limit_per_minute'] );
		$out['public_default_page_size']     = self::clamp_int( $value['public_default_page_size'] ?? null, 1, 1000, $defaults['public_default_page_size'] );

		$out['status_color_empty']     = self::sanitize_color( $value['status_color_empty'] ?? null, $defaults['status_color_empty'] );
		$out['status_color_called']    = self::sanitize_color( $value['status_color_called'] ?? null, $defaults['status_color_called'] );
		$out['status_color_hired']     = self::sanitize_color( $value['status_color_hired'] ?? null, $defaults['status_color_hired'] );
		$out['status_color_not_shown'] = self::sanitize_color( $value['status_color_not_shown'] ?? null, $defaults['status_color_not_shown'] );

		$out['preview_color_empty']          = self::sanitize_color( $value['preview_color_empty'] ?? null, $defaults['preview_color_empty'] );
		$out['preview_color_denied']         = self::sanitize_color( $value['preview_color_denied'] ?? null, $defaults['preview_color_denied'] );
		$out['preview_color_granted']        = self::sanitize_color( $value['preview_color_granted'] ?? null, $defaults['preview_color_granted'] );
		$out['preview_color_appeal_denied']  = self::sanitize_color( $value['preview_color_appeal_denied'] ?? null, $defaults['preview_color_appeal_denied'] );
		$out['preview_color_appeal_granted'] = self::sanitize_color( $value['preview_color_appeal_granted'] ?? null, $defaults['preview_color_appeal_granted'] );

		$out['subscription_color_pcd']   = self::sanitize_color( $value['subscription_color_pcd'] ?? null, $defaults['subscription_color_pcd'] );
		$out['subscription_color_geral'] = self::sanitize_color( $value['subscription_color_geral'] ?? null, $defaults['subscription_color_geral'] );

		// Checkboxes don't post when unchecked, so a missing key means
		// "off" — coerce truthiness from any submitted value.
		$out['preview_reason_required_denied']         = ! empty( $value['preview_reason_required_denied'] );
		$out['preview_reason_required_granted']        = ! empty( $value['preview_reason_required_granted'] );
		$out['preview_reason_required_appeal_denied']  = ! empty( $value['preview_reason_required_appeal_denied'] );
		$out['preview_reason_required_appeal_granted'] = ! empty( $value['preview_reason_required_appeal_granted'] );

		return $out;
	}

	/**
	 * Sanitize a color string. Accepts #RGB, #RRGGBB, or #RRGGBBAA;
	 * anything else falls back to the default. Sufficient for the
	 * status-badge palette use case (the public shortcode renders the
	 * value directly into a `style="background:..."` attribute).
	 *
	 * @param mixed  $value         Raw value.
	 * @param string $default Fallback when input is unusable.
	 * @return string
	 */
	private static function sanitize_color( $value, string $default ): string {
		if ( ! is_string( $value ) ) {
			return $default;
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return $default;
		}
		if ( 1 === preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
			return strtolower( $value );
		}
		return $default;
	}

	/**
	 * Coerce + bound an integer setting; fall back to default on bad input.
	 *
	 * @param mixed $value   Raw incoming value.
	 * @param int   $min     Minimum accepted (inclusive).
	 * @param int   $max     Maximum accepted (inclusive).
	 * @param int   $default Default when input is unusable.
	 * @return int
	 */
	private static function clamp_int( $value, int $min, int $max, int $default ): int {
		if ( ! is_numeric( $value ) ) {
			return $default;
		}
		$intval = (int) $value;
		if ( $intval < $min ) {
			return $default;
		}
		if ( $intval > $max ) {
			return $max;
		}
		return $intval;
	}

	/**
	 * Type-narrow a possibly-mixed array into the SettingsArray shape.
	 *
	 * Reused by `all()` after merging defaults+stored.
	 *
	 * @param array<string, mixed> $value Already merged-with-defaults array.
	 * @return SettingsArray
	 */
	private static function shape( array $value ): array {
		$defaults = self::defaults();
		return array(
			'email_subject'                          => is_string( $value['email_subject'] ?? null ) ? $value['email_subject'] : $defaults['email_subject'],
			'email_from_address'                     => is_string( $value['email_from_address'] ?? null ) ? $value['email_from_address'] : $defaults['email_from_address'],
			'email_from_name'                        => is_string( $value['email_from_name'] ?? null ) ? $value['email_from_name'] : $defaults['email_from_name'],
			'email_body_html'                        => is_string( $value['email_body_html'] ?? null ) ? $value['email_body_html'] : $defaults['email_body_html'],
			'public_cache_seconds'                   => is_int( $value['public_cache_seconds'] ?? null ) ? $value['public_cache_seconds'] : $defaults['public_cache_seconds'],
			'public_rate_limit_per_minute'           => is_int( $value['public_rate_limit_per_minute'] ?? null ) ? $value['public_rate_limit_per_minute'] : $defaults['public_rate_limit_per_minute'],
			'public_default_page_size'               => is_int( $value['public_default_page_size'] ?? null ) ? $value['public_default_page_size'] : $defaults['public_default_page_size'],
			'status_color_empty'                     => is_string( $value['status_color_empty'] ?? null ) ? $value['status_color_empty'] : $defaults['status_color_empty'],
			'status_color_called'                    => is_string( $value['status_color_called'] ?? null ) ? $value['status_color_called'] : $defaults['status_color_called'],
			'status_color_hired'                     => is_string( $value['status_color_hired'] ?? null ) ? $value['status_color_hired'] : $defaults['status_color_hired'],
			'status_color_not_shown'                 => is_string( $value['status_color_not_shown'] ?? null ) ? $value['status_color_not_shown'] : $defaults['status_color_not_shown'],
			'preview_color_empty'                    => is_string( $value['preview_color_empty'] ?? null ) ? $value['preview_color_empty'] : $defaults['preview_color_empty'],
			'preview_color_denied'                   => is_string( $value['preview_color_denied'] ?? null ) ? $value['preview_color_denied'] : $defaults['preview_color_denied'],
			'preview_color_granted'                  => is_string( $value['preview_color_granted'] ?? null ) ? $value['preview_color_granted'] : $defaults['preview_color_granted'],
			'preview_color_appeal_denied'            => is_string( $value['preview_color_appeal_denied'] ?? null ) ? $value['preview_color_appeal_denied'] : $defaults['preview_color_appeal_denied'],
			'preview_color_appeal_granted'           => is_string( $value['preview_color_appeal_granted'] ?? null ) ? $value['preview_color_appeal_granted'] : $defaults['preview_color_appeal_granted'],
			'preview_reason_required_denied'         => is_bool( $value['preview_reason_required_denied'] ?? null ) ? $value['preview_reason_required_denied'] : $defaults['preview_reason_required_denied'],
			'preview_reason_required_granted'        => is_bool( $value['preview_reason_required_granted'] ?? null ) ? $value['preview_reason_required_granted'] : $defaults['preview_reason_required_granted'],
			'preview_reason_required_appeal_denied'  => is_bool( $value['preview_reason_required_appeal_denied'] ?? null ) ? $value['preview_reason_required_appeal_denied'] : $defaults['preview_reason_required_appeal_denied'],
			'preview_reason_required_appeal_granted' => is_bool( $value['preview_reason_required_appeal_granted'] ?? null ) ? $value['preview_reason_required_appeal_granted'] : $defaults['preview_reason_required_appeal_granted'],
			'subscription_color_pcd'                 => is_string( $value['subscription_color_pcd'] ?? null ) ? $value['subscription_color_pcd'] : $defaults['subscription_color_pcd'],
			'subscription_color_geral'               => is_string( $value['subscription_color_geral'] ?? null ) ? $value['subscription_color_geral'] : $defaults['subscription_color_geral'],
		);
	}

	/**
	 * Default email body template — minimal, edit-friendly HTML.
	 *
	 * Translatable via `__()` so pt-BR `.po` files can ship a localized
	 * default. All `{{placeholder}}` markers documented in §11 of the plan
	 * resolve at send time.
	 *
	 * @return string
	 */
	private static function default_body_template(): string {
		return __( '<p>Hello {{name}},</p><p>You have been called for notice <strong>{{notice_code}} — {{notice_name}}</strong> in adjutancy <strong>{{adjutancy}}</strong>.</p><ul><li><strong>Rank:</strong> {{rank}}</li><li><strong>Score:</strong> {{score}}</li><li><strong>Date to assume:</strong> {{date_to_assume}}</li><li><strong>Time:</strong> {{time_to_assume}}</li></ul><p>{{notes}}</p><p>— {{site_name}}<br>{{site_url}}</p>', 'ffcertificate' );
	}
}
