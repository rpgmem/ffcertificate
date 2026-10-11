<?php
/**
 * GeofenceContent
 *
 * The `[ffc_geofence]…[/ffc_geofence]` shortcode: content shown only to a
 * visitor who passes the geofence checks (#1668).
 *
 * The protected content never reaches the page HTML. The page carries a
 * placeholder naming the shortcode by a signed handle; once the browser's
 * checks pass, the content is fetched from an endpoint that re-reads the
 * shortcode from the post itself and re-runs the date/time and IP checks.
 * GPS is asserted by the browser and cannot be re-checked, so this is a
 * visibility control, not an authorization boundary.
 *
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Security\Geofence;
use FreeFormCertificate\Security\GeofenceLocationRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Geofence-protected content shortcode and its content endpoint.
 */
class GeofenceContent {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	public const SHORTCODE = 'ffc_geofence';

	/**
	 * AJAX action serving the protected content.
	 *
	 * @var string
	 */
	public const AJAX_ACTION = 'ffc_geofence_content';

	/**
	 * Script handle of the client half.
	 *
	 * @var string
	 */
	public const SCRIPT_HANDLE = 'ffc-geofence-content';

	/**
	 * Attribute the content filter writes into each shortcode tag, so the
	 * rendered shortcode knows its position in the post content.
	 *
	 * The position cannot be counted at render time: the content filter can
	 * run more than once per request, and a counter would drift. Nor can the
	 * enclosed text identify the shortcode, because wpautop() and
	 * wptexturize() rewrite it before the shortcode runs, so it no longer
	 * matches the stored post content the endpoint reads.
	 *
	 * @var string
	 */
	public const INDEX_ATTRIBUTE = 'ffc_index';

	/**
	 * Wrapper ids handed out on this request, so two identical shortcodes on
	 * one page never share an id.
	 *
	 * @var int
	 */
	private static int $rendered = 0;

	/**
	 * Register the shortcode, the content filter and the endpoint.
	 */
	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );

		// Priority 8: before wptexturize (10), wpautop (10) and do_blocks (9),
		// so the shortcode tags are numbered as the post stores them, which
		// is what the endpoint reads back.
		add_filter( 'the_content', array( self::class, 'number_shortcodes' ), 8 );

		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_request' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * Write each shortcode's position into its opening tag.
	 *
	 * Escaped shortcodes (`[[ffc_geofence]]`) are left alone and not
	 * counted, exactly as find_shortcode() does not count them.
	 *
	 * @param mixed $content Post content.
	 * @return mixed The content with each tag numbered.
	 */
	public static function number_shortcodes( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '[' . self::SHORTCODE ) ) {
			return $content;
		}

		$index = 0;

		return preg_replace_callback(
			'/' . get_shortcode_regex( array( self::SHORTCODE ) ) . '/',
			static function ( array $m ) use ( &$index ): string {
				if ( '[' === $m[1] && ']' === $m[6] ) {
					return $m[0];
				}

				$opening = '[' . $m[2] . rtrim( $m[3] ) . ' ' . self::INDEX_ATTRIBUTE . '="' . $index . '"' . ( '/' === $m[4] ? ' /' : '' ) . ']';
				++$index;

				$closing = '[/' . $m[2] . ']';
				if ( false !== strpos( $m[0], $closing ) ) {
					return $opening . $m[5] . $closing;
				}

				return $opening;
			},
			$content
		);
	}

	/**
	 * Render the placeholder that stands for the protected content.
	 *
	 * @param mixed       $atts    Shortcode attributes.
	 * @param string|null $content Enclosed content.
	 * @return string
	 */
	public function render( $atts, $content = null ): string {
		$atts    = is_array( $atts ) ? $atts : array();
		$content = (string) $content;
		$config  = self::config_from_atts( $atts );

		// Nothing to check, so nothing to protect.
		if ( empty( $config['datetime_enabled'] ) && empty( $config['geo_enabled'] ) ) {
			return do_shortcode( $content );
		}

		$post_id = (int) get_the_ID();
		$index   = isset( $atts[ self::INDEX_ATTRIBUTE ] ) && is_numeric( $atts[ self::INDEX_ATTRIBUTE ] ) ? (int) $atts[ self::INDEX_ATTRIBUTE ] : -1;

		// Outside a post's own content (a widget, a template) there is no
		// stored copy for the endpoint to read, so the content is never shown.
		if ( $post_id <= 0 || $index < 0 ) {
			return '<div class="ffc-shortcode ffc-geofence-content"><div class="ffc-geofence-blocked"><p>' . esc_html( self::unavailable_message() ) . '</p></div></div>';
		}

		$handle = self::handle( $post_id, $index, self::fingerprint( $atts ) );
		$id     = 'ffc-geofence-content-' . ( ++self::$rendered );

		return sprintf(
			'<div id="%1$s" class="ffc-shortcode ffc-geofence-content" data-ffc-geofence-handle="%2$s" data-ffc-geofence-config="%3$s"><div class="ffc-geofence-loading-msg"><div class="ffc-spinner"></div><p>%4$s</p></div><noscript><div class="ffc-geofence-blocked"><p>%5$s</p></div></noscript></div>',
			esc_attr( $id ),
			esc_attr( $handle ),
			esc_attr( (string) wp_json_encode( Geofence::build_frontend_config( $config, array( 'contentId' => $id ) ) ) ),
			esc_html__( 'Checking access…', 'ffcertificate' ),
			esc_html__( 'This content needs JavaScript to check your access.', 'ffcertificate' )
		);
	}

	/**
	 * Serve the protected content once the server-side checks pass.
	 *
	 * Deliberately nonce-free, like DynamicFragments: the page that carries
	 * the handle is typically served from a page cache, where any nonce baked
	 * into it is stale, and a nonce for a logged-out visitor is the same for
	 * everyone, so it would protect nothing. What protects the content is
	 * that the handle is signed, that the shortcode is re-read from the post
	 * rather than taken from the request, and that the checks run again here.
	 */
	public function handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Intentionally nonce-free; see the method docblock.
		$raw    = isset( $_POST['handle'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['handle'] ) ) : '';
		$parsed = self::parse_handle( $raw );

		if ( null === $parsed ) {
			wp_send_json_error( array( 'message' => self::unavailable_message() ), 400 );
		}

		$shortcode = self::find_published_shortcode( $parsed['post_id'], $parsed['index'], $parsed['fingerprint'] );
		if ( null === $shortcode ) {
			wp_send_json_error( array( 'message' => self::unavailable_message() ), 404 );
		}

		$check = self::check( self::config_from_atts( $shortcode['atts'] ), $parsed['post_id'], $parsed['index'] );
		if ( ! $check['allowed'] ) {
			wp_send_json_error(
				array(
					'message' => ( isset( $check['message'] ) && '' !== $check['message'] ) ? $check['message'] : self::unavailable_message(),
					'reason'  => $check['reason'] ?? '',
				),
				403
			);
		}

		wp_send_json_success( array( 'html' => self::render_content( $shortcode['content'] ) ) );
	}

	/**
	 * Run the checks the server can make: date/time, and IP location when
	 * the shortcode asks for it. GPS is the browser's and is not repeated.
	 *
	 * @param array<string, mixed> $config  Normalized geofence config.
	 * @param int                  $post_id Post holding the shortcode.
	 * @param int                  $index   Position of the shortcode in it.
	 * @return array{allowed: bool, reason?: string, message?: string}
	 */
	public static function check( array $config, int $post_id, int $index ): array {
		return Geofence::check_access(
			$config,
			array(
				'check_datetime' => true,
				'check_geo'      => ! empty( $config['geo_enabled'] ) && ! empty( $config['geo_ip_enabled'] ),
			),
			array(
				'post_id'        => $post_id,
				'geofence_index' => $index,
			)
		);
	}

	/**
	 * Build a geofence config from the shortcode's attributes.
	 *
	 * Attributes:
	 * - `locations`, `ip_locations`: comma-separated ids from the location
	 *   registry. With neither `locations` nor `areas`, the registry's
	 *   default GPS location applies (and its default IP location for IP).
	 * - `areas`, `ip_areas`: one-off "lat, lng, radius" entries separated by
	 *   `;` (radius in metres).
	 * - `gps` (default yes), `ip` (default no), `logic` (`or`/`and`).
	 * - `date_start`, `date_end` (YYYY-MM-DD), `time_start`, `time_end`
	 *   (HH:MM), `time_mode` (`daily`/`span`).
	 * - `hide_mode` (`message`/`hide`) and `message`.
	 *
	 * @param array<int|string, mixed> $atts Raw shortcode attributes.
	 * @return array<string, mixed> Normalized geofence config.
	 */
	public static function config_from_atts( array $atts ): array {
		$a = shortcode_atts(
			array(
				'locations'    => '',
				'areas'        => '',
				'ip_locations' => '',
				'ip_areas'     => '',
				'gps'          => 'yes',
				'ip'           => 'no',
				'logic'        => 'or',
				'date_start'   => '',
				'date_end'     => '',
				'time_start'   => '',
				'time_end'     => '',
				'time_mode'    => 'daily',
				'hide_mode'    => 'message',
				'message'      => '',
			),
			$atts,
			self::SHORTCODE
		);

		$config = array();

		// Date and time: a malformed value is dropped rather than guessed at.
		foreach ( array( 'date_start', 'date_end' ) as $key ) {
			$value = trim( (string) $a[ $key ] );
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$config[ $key ] = $value;
			}
		}
		foreach ( array( 'time_start', 'time_end' ) as $key ) {
			$value = trim( (string) $a[ $key ] );
			if ( 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
				$config[ $key ] = $value;
			}
		}
		$config['time_mode']        = 'span' === $a['time_mode'] ? 'span' : 'daily';
		$config['datetime_enabled'] = isset( $config['date_start'] ) || isset( $config['date_end'] ) || isset( $config['time_start'] ) || isset( $config['time_end'] );

		// GPS areas: registry ids, raw areas, or the registry default.
		$location_ids = self::ids( (string) $a['locations'] );
		$raw_areas    = self::areas_text( (string) $a['areas'] );
		if ( ! empty( $location_ids ) ) {
			$config['geo_area_source']       = 'locations';
			$config['geo_area_location_ids'] = $location_ids;
		} elseif ( '' !== $raw_areas ) {
			$config['geo_area_source'] = 'custom';
			$config['geo_areas']       = $raw_areas;
		} else {
			$default = GeofenceLocationRegistry::get_default_gps();
			if ( null !== $default && ! empty( $default['id'] ) ) {
				$config['geo_area_source']       = 'locations';
				$config['geo_area_location_ids'] = array( (string) $default['id'] );
			}
		}

		// IP areas: only when they differ from the GPS ones.
		$ip_location_ids = self::ids( (string) $a['ip_locations'] );
		$raw_ip_areas    = self::areas_text( (string) $a['ip_areas'] );
		if ( ! empty( $ip_location_ids ) ) {
			$config['geo_ip_area_source']       = 'locations';
			$config['geo_ip_area_location_ids'] = $ip_location_ids;
			$config['geo_ip_areas_permissive']  = true;
		} elseif ( '' !== $raw_ip_areas ) {
			$config['geo_ip_area_source']      = 'custom';
			$config['geo_ip_areas']            = $raw_ip_areas;
			$config['geo_ip_areas_permissive'] = true;
		} elseif ( empty( $location_ids ) && '' === $raw_areas ) {
			$default_ip = GeofenceLocationRegistry::get_default_ip();
			if ( null !== $default_ip && ! empty( $default_ip['id'] ) ) {
				$config['geo_ip_area_source']       = 'locations';
				$config['geo_ip_area_location_ids'] = array( (string) $default_ip['id'] );
				$config['geo_ip_areas_permissive']  = true;
			}
		}

		$config['geo_gps_enabled']  = self::yes( $a['gps'] );
		$config['geo_ip_enabled']   = self::yes( $a['ip'] );
		$config['geo_gps_ip_logic'] = 'and' === $a['logic'] ? 'and' : 'or';

		// Location only restricts anything when there is an area to be in and
		// a method to check it with.
		$has_areas             = ( isset( $config['geo_area_location_ids'] ) && '' !== GeofenceLocationRegistry::resolve_to_areas_text( $config['geo_area_location_ids'] ) )
			|| isset( $config['geo_areas'] );
		$config['geo_enabled'] = $has_areas && ( $config['geo_gps_enabled'] || $config['geo_ip_enabled'] );

		$hide_mode                    = 'hide' === $a['hide_mode'] ? 'hide' : 'message';
		$config['datetime_hide_mode'] = $hide_mode;
		$config['geo_hide_mode']      = $hide_mode;

		$message                   = sanitize_text_field( (string) $a['message'] );
		$config['msg_datetime']    = '' !== $message ? $message : __( 'This content is not available at this time.', 'ffcertificate' );
		$config['msg_geo_blocked'] = '' !== $message ? $message : __( 'This content is not available in your location.', 'ffcertificate' );

		return Geofence::normalize_config( $config );
	}

	/**
	 * Sign a handle for one shortcode of one post.
	 *
	 * @param int    $post_id     Post holding the shortcode.
	 * @param int    $index       Position of the shortcode in the post content.
	 * @param string $fingerprint Fingerprint of its attributes.
	 * @return string `post.index.fingerprint.signature`.
	 */
	public static function handle( int $post_id, int $index, string $fingerprint ): string {
		$payload = $post_id . '.' . $index . '.' . $fingerprint;

		return $payload . '.' . self::sign( $payload );
	}

	/**
	 * Read a handle back, refusing any whose signature does not match.
	 *
	 * @param string $handle Handle from the request.
	 * @return array{post_id: int, index: int, fingerprint: string}|null
	 */
	public static function parse_handle( string $handle ): ?array {
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)\.([a-f0-9]{16})\.([a-f0-9]{32})$/', $handle, $m ) ) {
			return null;
		}

		if ( ! hash_equals( self::sign( $m[1] . '.' . $m[2] . '.' . $m[3] ), $m[4] ) ) {
			return null;
		}

		$post_id = (int) $m[1];

		return $post_id > 0
			? array(
				'post_id'     => $post_id,
				'index'       => (int) $m[2],
				'fingerprint' => $m[3],
			)
			: null;
	}

	/**
	 * Fingerprint the attributes a shortcode was written with, so a handle
	 * stops matching when the post is edited and the shortcode at that
	 * position changes.
	 *
	 * @param array<int|string, mixed> $atts Raw attributes.
	 * @return string 16 hex characters.
	 */
	public static function fingerprint( array $atts ): string {
		unset( $atts[ self::INDEX_ATTRIBUTE ] );
		ksort( $atts );

		return substr( hash( 'sha256', (string) wp_json_encode( $atts ) ), 0, 16 );
	}

	/**
	 * Find the shortcode a handle names in a post the visitor may read.
	 *
	 * @param int    $post_id     Post id.
	 * @param int    $index       Position among the post's unescaped shortcodes.
	 * @param string $fingerprint Expected attribute fingerprint.
	 * @return array{atts: array<int|string, mixed>, content: string}|null
	 */
	public static function find_published_shortcode( int $post_id, int $index, string $fingerprint ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::is_readable( $post ) ) {
			return null;
		}

		$shortcode = self::find_shortcode( (string) $post->post_content, $index );
		if ( null === $shortcode || ! hash_equals( self::fingerprint( $shortcode['atts'] ), $fingerprint ) ) {
			return null;
		}

		return $shortcode;
	}

	/**
	 * The shortcode at a position in raw post content.
	 *
	 * @param string $content Raw post content.
	 * @param int    $index   Position among the unescaped shortcodes.
	 * @return array{atts: array<int|string, mixed>, content: string}|null
	 */
	public static function find_shortcode( string $content, int $index ): ?array {
		if ( ! preg_match_all( '/' . get_shortcode_regex( array( self::SHORTCODE ) ) . '/', $content, $matches, PREG_SET_ORDER ) ) {
			return null;
		}

		$position = 0;
		foreach ( $matches as $m ) {
			if ( '[' === $m[1] && ']' === $m[6] ) {
				continue;
			}
			if ( $position === $index ) {
				// WordPress hands a shortcode without attributes the empty
				// string rather than an array; render() reads it the same way.
				return array(
					'atts'    => '' === trim( $m[3] ) ? array() : shortcode_parse_atts( $m[3] ),
					'content' => (string) $m[5],
				);
			}
			++$position;
		}

		return null;
	}

	/**
	 * Whether the current visitor may read the post the content lives in.
	 * The endpoint must never serve what the post's own page would not.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function is_readable( \WP_Post $post ): bool {
		if ( post_password_required( $post ) ) {
			return false;
		}

		if ( 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
			return true;
		}

		return current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Render the enclosed content the way the post's own page would.
	 *
	 * @param string $content Raw enclosed content.
	 * @return string
	 */
	private static function render_content( string $content ): string {
		return do_shortcode( shortcode_unautop( wpautop( wptexturize( $content ) ) ) );
	}

	/**
	 * HMAC over a handle payload, keyed by a site salt and this action, so a
	 * signature from another feature never validates here.
	 *
	 * @param string $payload Payload.
	 * @return string 32 hex characters.
	 */
	private static function sign( string $payload ): string {
		return substr( hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) . '|' . self::AJAX_ACTION ), 0, 32 );
	}

	/**
	 * Comma-separated registry ids.
	 *
	 * @param string $value Attribute value.
	 * @return array<int, string>
	 */
	private static function ids( string $value ): array {
		return array_values( array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $value ) ) ) ) );
	}

	/**
	 * `;`-separated areas as the one-per-line text Geofence::parse_areas() reads.
	 *
	 * @param string $value Attribute value.
	 * @return string Only the entries that parse.
	 */
	private static function areas_text( string $value ): string {
		$lines = array();
		foreach ( Geofence::parse_areas( str_replace( ';', "\n", $value ) ) as $area ) {
			$lines[] = $area['lat'] . ', ' . $area['lng'] . ', ' . $area['radius'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Read a yes/no attribute.
	 *
	 * @param mixed $value Attribute value.
	 * @return bool
	 */
	private static function yes( $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * The message shown when the content cannot be served at all.
	 *
	 * @return string
	 */
	private static function unavailable_message(): string {
		return __( 'This content is not available.', 'ffcertificate' );
	}
}
