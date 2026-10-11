<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\GeofenceContent;

/**
 * What a wp_send_json_* stub throws, so a test can read the response.
 */
final class GeofenceContentJsonResponse extends \RuntimeException {

	/**
	 * Whether the response was a success.
	 *
	 * @var bool
	 */
	public $success;

	/**
	 * Response data.
	 *
	 * @var mixed
	 */
	public $data;

	/**
	 * HTTP status.
	 *
	 * @var int|null
	 */
	public $status;

	public function __construct( bool $success, $data, ?int $status ) {
		parent::__construct( $success ? 'success' : 'error' );
		$this->success = $success;
		$this->data    = $data;
		$this->status  = $status;
	}
}

/**
 * Tests for the [ffc_geofence] shortcode and its content endpoint (#1668).
 *
 * @covers \FreeFormCertificate\Frontend\GeofenceContent
 */
class GeofenceContentTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Today, as the stubbed wp_date() reports it.
	 */
	private const TODAY = '2026-02-08';

	/**
	 * Posts get_post() can return, by id.
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $posts = array();

	/**
	 * The stored geofence locations.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $locations = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$posts     = &$this->posts;
		$locations = &$this->locations;

		Functions\when( 'add_shortcode' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_attr' )->alias( 'htmlspecialchars' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_parse_args' )->alias( 'array_merge' );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'absint' )->alias(
			static function ( $v ) {
				return abs( (int) $v );
			}
		);
		Functions\when( 'wp_date' )->alias(
			static function ( $format ) {
				return 'Y-m-d' === $format ? self::TODAY : '12:00';
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$locations ) {
				return 'ffc_geofence_locations' === $key ? $locations : $default;
			}
		);
		Functions\when( 'get_shortcode_regex' )->alias( array( self::class, 'shortcode_regex' ) );
		Functions\when( 'shortcode_parse_atts' )->alias( array( self::class, 'parse_atts' ) );
		Functions\when( 'shortcode_atts' )->alias(
			static function ( $defaults, $atts ) {
				$out = array();
				foreach ( $defaults as $name => $default ) {
					$out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default;
				}
				return $out;
			}
		);
		Functions\when( 'do_shortcode' )->alias(
			static function ( $content ) {
				return '<rendered>' . $content . '</rendered>';
			}
		);
		Functions\when( 'wpautop' )->returnArg();
		Functions\when( 'wptexturize' )->returnArg();
		Functions\when( 'shortcode_unautop' )->returnArg();
		Functions\when( 'get_post' )->alias(
			static function ( $id ) use ( &$posts ) {
				return $posts[ (int) $id ] ?? null;
			}
		);
		Functions\when( 'post_password_required' )->alias(
			static function ( $post ) {
				return '' !== (string) $post->post_password;
			}
		);
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null, $status = null ) {
				throw new GeofenceContentJsonResponse( true, $data, $status );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ) {
				throw new GeofenceContentJsonResponse( false, $data, $status );
			}
		);
	}

	protected function tearDown(): void {
		unset( $_POST['handle'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * WordPress's own get_shortcode_regex(), so the tests read shortcodes
	 * exactly as WordPress does.
	 *
	 * @param array<int, string> $tagnames Tags.
	 * @return string
	 */
	public static function shortcode_regex( $tagnames ): string {
		$tagregexp = implode( '|', array_map( 'preg_quote', (array) $tagnames ) );

		return '\\[(\\[?)(' . $tagregexp . ')(?![\\w-])([^\\]\\/]*(?:\\/(?!\\])[^\\]\\/]*)*?)(?:(\\/)\\]|\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\\[]*+)*+)\\[\\/\\2\\])?)(\\]?)';
	}

	/**
	 * A simplified shortcode_parse_atts(): name="value", name='value' and
	 * name=value, with names lower-cased as WordPress does.
	 *
	 * @param string $text Attribute text.
	 * @return array<string, string>
	 */
	public static function parse_atts( $text ): array {
		$atts = array();
		if ( preg_match_all( '/([\w-]+)\s*=\s*"([^"]*)"|([\w-]+)\s*=\s*\'([^\']*)\'|([\w-]+)\s*=\s*([^\s\]]+)/', (string) $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$key                       = '' !== $row[1] ? $row[1] : ( '' !== $row[3] ? $row[3] : $row[5] );
				$atts[ strtolower( $key ) ] = '' !== $row[1] ? $row[2] : ( '' !== $row[3] ? $row[4] : $row[6] );
			}
		}
		return $atts;
	}

	/**
	 * Store a post get_post() returns.
	 */
	private function post( int $id, string $content, string $status = 'publish', string $password = '' ): void {
		$post                = new \WP_Post( (object) array() );
		$post->ID            = $id;
		$post->post_content  = $content;
		$post->post_status   = $status;
		$post->post_type     = 'page';
		$post->post_password = $password;
		$this->posts[ $id ]  = $post;
	}

	/**
	 * Call the endpoint with a handle and return its response.
	 */
	private function request( string $handle ): GeofenceContentJsonResponse {
		$_POST['handle'] = $handle;
		try {
			( new GeofenceContent() )->handle_request();
		} catch ( GeofenceContentJsonResponse $response ) {
			return $response;
		}
		$this->fail( 'The endpoint sent no response.' );
	}

	/**
	 * A valid handle for the shortcode at a position of a stored post.
	 */
	private function handle_for( int $post_id, int $index ): string {
		$shortcode = GeofenceContent::find_shortcode( $this->posts[ $post_id ]->post_content, $index );
		$this->assertNotNull( $shortcode );

		return GeofenceContent::handle( $post_id, $index, GeofenceContent::fingerprint( $shortcode['atts'] ) );
	}

	// ------------------------------------------------------------------
	// Numbering the shortcodes in the content.
	// ------------------------------------------------------------------

	public function test_number_shortcodes_writes_each_position_into_its_tag(): void {
		$content = 'a [ffc_geofence date_end="2026-12-31"]one[/ffc_geofence] b [ffc_geofence areas="1,2,3"]two[/ffc_geofence]';

		$this->assertSame(
			'a [ffc_geofence date_end="2026-12-31" ffc_index="0"]one[/ffc_geofence] b [ffc_geofence areas="1,2,3" ffc_index="1"]two[/ffc_geofence]',
			GeofenceContent::number_shortcodes( $content )
		);
	}

	public function test_number_shortcodes_skips_escaped_and_keeps_self_closing(): void {
		$content = '[[ffc_geofence]] [ffc_geofence date_end="2026-12-31" /]';

		$this->assertSame(
			'[[ffc_geofence]] [ffc_geofence date_end="2026-12-31" ffc_index="0" /]',
			GeofenceContent::number_shortcodes( $content )
		);
	}

	public function test_number_shortcodes_leaves_other_content_and_types_alone(): void {
		$this->assertSame( 'no shortcode here', GeofenceContent::number_shortcodes( 'no shortcode here' ) );
		$this->assertNull( GeofenceContent::number_shortcodes( null ) );
	}

	public function test_numbering_matches_what_find_shortcode_reads_from_the_post(): void {
		$raw      = 'A [ffc_geofence date_end="2026-12-31"]first[/ffc_geofence] B [ffc_geofence areas="1,2,3"]second[/ffc_geofence] C [[ffc_geofence]]';
		$numbered = GeofenceContent::number_shortcodes( $raw );

		preg_match_all( '/ffc_index="(\d+)"\]([a-z]+)\[/', $numbered, $m, PREG_SET_ORDER );
		$this->assertCount( 2, $m );
		foreach ( $m as $row ) {
			$found = GeofenceContent::find_shortcode( $raw, (int) $row[1] );
			$this->assertNotNull( $found );
			$this->assertSame( $row[2], $found['content'] );
		}
		$this->assertNull( GeofenceContent::find_shortcode( $raw, 2 ) );
	}

	// ------------------------------------------------------------------
	// Attributes to config.
	// ------------------------------------------------------------------

	public function test_config_reads_the_date_and_time_window(): void {
		$config = GeofenceContent::config_from_atts(
			array(
				'date_start' => '2026-01-01',
				'date_end'   => '2026-12-31',
				'time_start' => '08:00',
				'time_end'   => '17:30',
				'time_mode'  => 'span',
			)
		);

		$this->assertTrue( $config['datetime_enabled'] );
		$this->assertSame( '2026-01-01', $config['date_start'] );
		$this->assertSame( '17:30', $config['time_end'] );
		$this->assertSame( 'span', $config['time_mode'] );
		$this->assertFalse( $config['geo_enabled'], 'No area, so location restricts nothing.' );
	}

	public function test_config_drops_malformed_dates_and_times(): void {
		$config = GeofenceContent::config_from_atts(
			array(
				'date_start' => 'tomorrow',
				'time_end'   => '25:00',
				'time_mode'  => 'whenever',
			)
		);

		$this->assertArrayNotHasKey( 'date_start', $config );
		$this->assertArrayNotHasKey( 'time_end', $config );
		$this->assertFalse( $config['datetime_enabled'] );
		$this->assertSame( 'daily', $config['time_mode'] );
	}

	public function test_config_reads_raw_areas_and_keeps_only_valid_ones(): void {
		$config = GeofenceContent::config_from_atts( array( 'areas' => '-23.5, -46.6, 300; 999, 0, 10' ) );

		$this->assertSame( 'custom', $config['geo_area_source'] );
		$this->assertSame( '-23.5, -46.6, 300', $config['geo_areas'] );
		$this->assertTrue( $config['geo_enabled'] );
		$this->assertTrue( $config['geo_gps_enabled'], 'GPS is on by default.' );
		$this->assertFalse( $config['geo_ip_enabled'], 'IP is off by default.' );
	}

	public function test_config_reads_registry_locations(): void {
		$this->locations = array(
			array(
				'id'     => 'school',
				'name'   => 'School',
				'lat'    => -23.5,
				'lng'    => -46.6,
				'radius' => 200,
			),
		);

		$config = GeofenceContent::config_from_atts( array( 'locations' => 'school, missing' ) );

		$this->assertSame( 'locations', $config['geo_area_source'] );
		$this->assertSame( array( 'school', 'missing' ), $config['geo_area_location_ids'] );
		$this->assertTrue( $config['geo_enabled'] );
	}

	public function test_config_falls_back_to_the_registry_defaults(): void {
		$this->locations = array(
			array(
				'id'          => 'campus',
				'lat'         => -23.5,
				'lng'         => -46.6,
				'radius'      => 200,
				'default_gps' => true,
			),
			array(
				'id'         => 'city',
				'lat'        => -23.5,
				'lng'        => -46.6,
				'radius'     => 20000,
				'default_ip' => true,
			),
		);

		$config = GeofenceContent::config_from_atts( array( 'ip' => 'yes' ) );

		$this->assertSame( array( 'campus' ), $config['geo_area_location_ids'] );
		$this->assertSame( array( 'city' ), $config['geo_ip_area_location_ids'] );
		$this->assertTrue( $config['geo_ip_areas_permissive'] );
		$this->assertTrue( $config['geo_ip_enabled'] );
		$this->assertTrue( $config['geo_enabled'] );
	}

	public function test_config_without_any_area_restricts_no_location(): void {
		$config = GeofenceContent::config_from_atts( array( 'gps' => 'yes' ) );

		$this->assertFalse( $config['geo_enabled'] );
	}

	public function test_config_with_every_method_off_restricts_no_location(): void {
		$config = GeofenceContent::config_from_atts(
			array(
				'areas' => '-23.5, -46.6, 300',
				'gps'   => 'no',
			)
		);

		$this->assertFalse( $config['geo_enabled'] );
	}

	public function test_config_messages_and_hide_mode(): void {
		$default = GeofenceContent::config_from_atts( array( 'hide_mode' => 'sideways' ) );
		$this->assertSame( 'message', $default['datetime_hide_mode'] );
		$this->assertSame( 'This content is not available at this time.', $default['msg_datetime'] );
		$this->assertSame( 'This content is not available in your location.', $default['msg_geo_blocked'] );

		$custom = GeofenceContent::config_from_atts(
			array(
				'hide_mode' => 'hide',
				'message'   => 'Come back on Monday.',
				'logic'     => 'and',
			)
		);
		$this->assertSame( 'hide', $custom['geo_hide_mode'] );
		$this->assertSame( 'Come back on Monday.', $custom['msg_datetime'] );
		$this->assertSame( 'Come back on Monday.', $custom['msg_geo_blocked'] );
		$this->assertSame( 'and', $custom['geo_gps_ip_logic'] );
	}

	// ------------------------------------------------------------------
	// The handle.
	// ------------------------------------------------------------------

	public function test_a_handle_reads_back(): void {
		$handle = GeofenceContent::handle( 12, 3, str_repeat( 'a', 16 ) );

		$this->assertSame(
			array(
				'post_id'     => 12,
				'index'       => 3,
				'fingerprint' => str_repeat( 'a', 16 ),
			),
			GeofenceContent::parse_handle( $handle )
		);
	}

	public function test_a_tampered_handle_is_refused(): void {
		$handle = GeofenceContent::handle( 12, 3, str_repeat( 'a', 16 ) );

		$this->assertNull( GeofenceContent::parse_handle( str_replace( '12.3.', '13.3.', $handle ) ), 'Another post.' );
		$this->assertNull( GeofenceContent::parse_handle( str_replace( '12.3.', '12.4.', $handle ) ), 'Another position.' );
		$this->assertNull( GeofenceContent::parse_handle( substr( $handle, 0, -1 ) . ( 'a' === substr( $handle, -1 ) ? 'b' : 'a' ) ), 'Another signature.' );
		$this->assertNull( GeofenceContent::parse_handle( 'garbage' ) );
		$this->assertNull( GeofenceContent::parse_handle( GeofenceContent::handle( 0, 0, str_repeat( 'a', 16 ) ) ), 'No post 0.' );
	}

	public function test_the_fingerprint_ignores_the_index_and_the_attribute_order(): void {
		$this->assertSame(
			GeofenceContent::fingerprint(
				array(
					'date_end' => '2026-12-31',
					'areas'    => '1,2,3',
				)
			),
			GeofenceContent::fingerprint(
				array(
					'areas'     => '1,2,3',
					'ffc_index' => '4',
					'date_end'  => '2026-12-31',
				)
			)
		);
		$this->assertNotSame(
			GeofenceContent::fingerprint( array( 'date_end' => '2026-12-31' ) ),
			GeofenceContent::fingerprint( array( 'date_end' => '2026-12-30' ) )
		);
	}

	// ------------------------------------------------------------------
	// Rendering the placeholder.
	// ------------------------------------------------------------------

	public function test_the_protected_content_is_not_in_the_rendered_html(): void {
		Functions\when( 'get_the_ID' )->justReturn( 5 );

		$html = ( new GeofenceContent() )->render(
			array(
				'date_end'  => '2026-12-31',
				'ffc_index' => '0',
			),
			'TOP SECRET'
		);

		$this->assertStringNotContainsString( 'TOP SECRET', $html );
		$this->assertStringContainsString( 'data-ffc-geofence-handle="5.0.', $html );
		$this->assertStringContainsString( 'data-ffc-geofence-config="', $html );
		$this->assertStringContainsString( '<noscript>', $html );
	}

	public function test_a_shortcode_with_nothing_to_check_renders_its_content(): void {
		Functions\when( 'get_the_ID' )->justReturn( 5 );

		$html = ( new GeofenceContent() )->render( array( 'ffc_index' => '0' ), 'Open to all' );

		$this->assertSame( '<rendered>Open to all</rendered>', $html );
	}

	public function test_a_shortcode_outside_post_content_is_never_served(): void {
		Functions\when( 'get_the_ID' )->justReturn( 5 );

		$html = ( new GeofenceContent() )->render( array( 'date_end' => '2026-12-31' ), 'TOP SECRET' );

		$this->assertStringNotContainsString( 'TOP SECRET', $html );
		$this->assertStringNotContainsString( 'data-ffc-geofence-handle', $html );
		$this->assertStringContainsString( 'This content is not available.', $html );
	}

	// ------------------------------------------------------------------
	// The content endpoint.
	// ------------------------------------------------------------------

	public function test_the_endpoint_serves_content_inside_the_window(): void {
		$this->post( 7, 'Intro [ffc_geofence date_start="2026-01-01" date_end="2026-12-31"]Answer key[/ffc_geofence]' );

		$response = $this->request( $this->handle_for( 7, 0 ) );

		$this->assertTrue( $response->success );
		$this->assertSame( '<rendered>Answer key</rendered>', $response->data['html'] );
	}

	public function test_the_endpoint_refuses_an_expired_window(): void {
		$this->post( 7, '[ffc_geofence date_end="2026-01-31" message="Closed."]Answer key[/ffc_geofence]' );

		$response = $this->request( $this->handle_for( 7, 0 ) );

		$this->assertFalse( $response->success );
		$this->assertSame( 403, $response->status );
		$this->assertSame( 'Closed.', $response->data['message'] );
		$this->assertSame( 'datetime_invalid', $response->data['reason'] );
		$this->assertStringNotContainsString( 'Answer key', (string) json_encode( $response->data ) );
	}

	public function test_the_endpoint_honours_the_admin_bypass(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->locations = array();
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return 'ffc_geolocation_settings' === $key ? array( 'admin_bypass_datetime' => true ) : $default;
			}
		);
		$this->post( 7, '[ffc_geofence date_end="2026-01-31"]Answer key[/ffc_geofence]' );

		$response = $this->request( $this->handle_for( 7, 0 ) );

		$this->assertTrue( $response->success );
	}

	public function test_the_endpoint_refuses_a_tampered_handle(): void {
		$this->post( 7, '[ffc_geofence date_end="2026-12-31"]Answer key[/ffc_geofence]' );
		$handle = $this->handle_for( 7, 0 );

		$response = $this->request( '8' . substr( $handle, 1 ) );

		$this->assertFalse( $response->success );
		$this->assertSame( 400, $response->status );
	}

	public function test_the_endpoint_refuses_a_handle_for_a_shortcode_that_changed(): void {
		$this->post( 7, '[ffc_geofence date_end="2026-12-31"]Answer key[/ffc_geofence]' );
		$handle = $this->handle_for( 7, 0 );

		// The post is edited after the page was cached: the shortcode at that
		// position now has other attributes.
		$this->post( 7, '[ffc_geofence date_end="2027-12-31"]Answer key[/ffc_geofence]' );

		$response = $this->request( $handle );

		$this->assertFalse( $response->success );
		$this->assertSame( 404, $response->status );
	}

	public function test_the_endpoint_refuses_a_foreign_handle(): void {
		$this->post( 7, '[ffc_geofence date_end="2026-12-31"]Answer key[/ffc_geofence]' );
		$this->post( 8, 'No shortcode in this one.' );
		$fingerprint = GeofenceContent::fingerprint( array( 'date_end' => '2026-12-31' ) );

		$response = $this->request( GeofenceContent::handle( 8, 0, $fingerprint ) );

		$this->assertFalse( $response->success );
		$this->assertSame( 404, $response->status );
	}

	public function test_the_endpoint_does_not_serve_a_post_the_visitor_cannot_read(): void {
		$this->post( 7, '[ffc_geofence date_end="2026-12-31"]Draft key[/ffc_geofence]', 'draft' );
		$draft = $this->request( $this->handle_for( 7, 0 ) );

		$this->post( 9, '[ffc_geofence date_end="2026-12-31"]Locked key[/ffc_geofence]', 'publish', 'secret' );
		$locked = $this->request( $this->handle_for( 9, 0 ) );

		$this->assertSame( 404, $draft->status );
		$this->assertSame( 404, $locked->status );
	}

	public function test_the_endpoint_checks_ip_location_only_when_the_shortcode_asks(): void {
		$this->post( 7, '[ffc_geofence areas="-23.5, -46.6, 300"]Answer key[/ffc_geofence]' );

		// GPS only: the server cannot check it, so it serves the content.
		$response = $this->request( $this->handle_for( 7, 0 ) );

		$this->assertTrue( $response->success );
	}
}
