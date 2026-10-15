<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingEditor;
use FreeFormCertificate\Tests\Support\FormControls;

/**
 * The personal calendar editor posts what it posted before its reorganisation
 * into vertical tabs (#1761).
 *
 * The reorganisation moved every field of the four stacked metaboxes (and the
 * occupancy report) into one box with tabs, and is allowed to change nothing
 * but the container and the order on screen. So this renders everything the
 * normal column draws, the same way before and after, and compares every
 * form control with every attribute it carries -- name, type, id, value,
 * min, max, step, required, checked, disabled, class, the options of a
 * select -- against an inventory frozen from the renderer as it stood
 * before the change.
 *
 * The fixture was CAPTURED, not written: `tests/fixtures/self-scheduling-editor-fields.php`
 * is this class's own `render_normal_column()` run over the pre-#1761
 * renderer (origin/develop 0eba0ec), through `FormControls::inventory()`. It
 * deliberately has no regenerate switch: regenerating it from the new
 * renderer would compare the new screen with itself.
 *
 * Two scenarios, because a default calendar and a configured one take
 * different branches: the locked mode, the forced-private scheduling, the
 * hidden deadline row and the custom-mode rows only appear in the second.
 *
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingEditor
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class SelfSchedulingEditorFieldParityTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Post meta by key for the scenario being rendered.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	/**
	 * Appointment count the mode-lock query answers.
	 *
	 * @var int
	 */
	private int $bookings = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$attr = static function ( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES );
		};
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->alias( $attr );
		Functions\when( 'esc_html' )->alias( $attr );
		Functions\when( 'esc_attr' )->alias( $attr );
		Functions\when( 'esc_attr__' )->alias( $attr );
		Functions\when( 'esc_url' )->alias( $attr );
		Functions\when( 'esc_textarea' )->alias( $attr );
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) use ( $attr ) { echo $attr( $text ); } );
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) use ( $attr ) { echo $attr( $text ); } );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );

		// The real helpers' output, so a lost `checked` or `disabled` shows.
		$flag = static function ( string $word ) {
			return static function ( $helper, $current = true, $echo = true ) use ( $word ) {
				$out = (string) $helper === (string) $current ? " {$word}='{$word}'" : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			};
		};
		Functions\when( 'checked' )->alias( $flag( 'checked' ) );
		Functions\when( 'selected' )->alias( $flag( 'selected' ) );
		Functions\when( 'disabled' )->alias( $flag( 'disabled' ) );

		Functions\when( 'wp_nonce_field' )->alias(
			static function ( $action, $name ) {
				echo '<input type="hidden" id="' . $name . '" name="' . $name . '" value="nonce:' . $action . '" />';
			}
		);
		Functions\when( 'wp_editor' )->alias(
			static function ( $content, $id, $settings ) {
				echo '<textarea id="' . $id . '" name="' . $settings['textarea_name'] . '" rows="' . $settings['textarea_rows'] . '">' . htmlspecialchars( (string) $content ) . '</textarea>';
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $key, $fallback = false ) {
				return 'admin_email' === $key ? 'site@example.com' : $fallback;
			}
		);
		Functions\when( 'get_the_ID' )->justReturn( 10 );
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key ) {
				return $this->meta[ $key ] ?? '';
			}
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		// The occupancy report formats each block's date.
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( (string) $format, (int) $ts ) );

		global $wpdb;
		$wpdb         = \Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q ) => $q );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( fn() => $this->bookings );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
		if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
			define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
		}
		if ( ! defined( 'FFC_VERSION' ) ) {
			define( 'FFC_VERSION', '4.12.0' );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The two scenarios: post meta and the number of bookings.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int}>
	 */
	public static function scenarios(): array {
		return array(
			'new calendar, every default' => array( array(), 0 ),
			'configured custom calendar with bookings' => array(
				array(
					'_ffc_self_scheduling_config'        => array(
						'description'               => 'Front desk',
						'schedule_type'             => 'custom',
						'status'                    => 'inactive',
						'slot_duration'             => 45,
						'slot_interval'             => 10,
						'slots_per_day'             => 12,
						'max_appointments_per_slot' => 3,
						'advance_booking_min'       => 2,
						'advance_booking_max'       => 60,
						'allow_cancellation'        => 0,
						'cancellation_min_hours'    => 12,
						'requires_approval'         => 1,
						'waitlist_enabled'          => 1,
						'waitlist_capacity'         => 5,
						'max_blocks_per_user'       => 2,
						'visibility'                => 'private',
						'scheduling_visibility'     => 'private',
						'restrict_viewing_to_hours' => 1,
						'admin_bypass'              => 0,
					),
					'_ffc_self_scheduling_working_hours' => array(
						array( 'day' => 2, 'start' => '08:00', 'end' => '12:00' ),
						array( 'day' => 4, 'start' => '13:00', 'end' => '18:00' ),
					),
					'_ffc_self_scheduling_custom_slots'  => array(
						array( 'date' => '2026-11-03', 'start' => '08:00', 'end' => '12:00', 'capacity' => 40, 'label' => 'Morning' ),
					),
					'_ffc_self_scheduling_email_config'  => array(
						'send_user_confirmation'    => 1,
						'send_reminder'             => 1,
						'reminder_hours_before'     => 48,
						'admin_emails'              => 'desk@example.com',
						'user_confirmation_subject' => 'Booked',
						'user_confirmation_body'    => '<p>See you</p>',
					),
				),
				3,
			),
		);
	}

	/**
	 * Render every box the editor registers in the normal column, in order.
	 *
	 * The procedure is the same before and after the reorganisation -- ask
	 * `add_custom_metaboxes()` what it registers, then call each callback --
	 * which is what lets the fixture be captured from the old renderer.
	 *
	 * @return string
	 */
	private function render_normal_column(): string {
		$boxes = array();
		Functions\when( 'add_meta_box' )->alias(
			static function ( $id, $title, $callback, $screen, $context = 'advanced' ) use ( &$boxes ) {
				$boxes[] = array( $callback, $context );
				return true;
			}
		);

		$editor = new SelfSchedulingEditor();
		$editor->add_custom_metaboxes();

		$post              = new \stdClass();
		$post->ID          = 10;
		$post->post_status = 'publish';

		ob_start();
		foreach ( $boxes as $box ) {
			if ( 'normal' === $box[1] ) {
				call_user_func( $box[0], $post );
			}
		}
		return (string) ob_get_clean();
	}

	/**
	 * The frozen inventory for a scenario.
	 *
	 * @param int $bookings Appointments on the calendar.
	 * @return array{controls: list<array<string, mixed>>, texts: list<string>}
	 */
	private static function before( int $bookings ): array {
		$all = require dirname( __DIR__ ) . '/fixtures/self-scheduling-editor-fields.php';
		return $all[ $bookings > 0 ? 'configured' : 'defaults' ];
	}

	/**
	 * @dataProvider scenarios
	 *
	 * @param array<string, mixed> $meta     Post meta.
	 * @param int                  $bookings Appointments on the calendar.
	 */
	public function test_every_field_posts_what_it_posted_before( array $meta, int $bookings ): void {
		$this->meta     = $meta;
		$this->bookings = $bookings;

		$before = self::before( $bookings );
		$this->assertGreaterThan( 40, count( $before['controls'] ), 'The frozen inventory lost its fields, so the comparison would prove little.' );

		$this->assertSame( $before['controls'], FormControls::inventory( $this->render_normal_column() ) );
	}

	/**
	 * Every label, help text and heading the old boxes printed is still on
	 * the screen, word for word. The new screen adds the tab names, so this
	 * is containment, not equality.
	 *
	 * @dataProvider scenarios
	 *
	 * @param array<string, mixed> $meta     Post meta.
	 * @param int                  $bookings Appointments on the calendar.
	 */
	public function test_every_label_and_help_text_is_kept( array $meta, int $bookings ): void {
		$this->meta     = $meta;
		$this->bookings = $bookings;

		$before = self::before( $bookings );
		$this->assertGreaterThan( 100, count( $before['texts'] ) );

		$html  = $this->render_normal_column();
		$after = FormControls::texts( $html );

		// The one deliberate exception: the Cancellation and Administrator
		// bypass chips printed "On" / "Off", and became tab dots whose state
		// the tab script writes from the nav's own On / Off attributes.
		$after = array_merge( $after, array( 'On', 'Off' ) );
		$this->assertStringContainsString( 'data-ffc-state-on="On" data-ffc-state-off="Off"', $html );

		$this->assertSame( array(), array_values( array_diff( $before['texts'], $after ) ) );
	}

	/**
	 * @dataProvider scenarios
	 *
	 * @param array<string, mixed> $meta     Post meta.
	 * @param int                  $bookings Appointments on the calendar.
	 */
	public function test_one_box_holds_every_field_in_a_tab_and_prints_the_nonce_once( array $meta, int $bookings ): void {
		$this->meta     = $meta;
		$this->bookings = $bookings;

		$html = $this->render_normal_column();

		$this->assertSame( 1, substr_count( $html, 'name="ffc_self_scheduling_config_nonce"' ) );
		$this->assertSame( 1, substr_count( $html, 'data-ffc-vtabs' ) );

		$keys = array( 'calendar', 'slots', 'window', 'cancellation', 'approval', 'access', 'bypass', 'emails' );
		if ( array() !== $meta ) {
			$keys[] = 'occupancy'; // A saved custom-mode calendar, as the report box required.
		}
		preg_match_all( '/<section id="ffc-tabpanel-([a-z]+)"/', $html, $m );
		$this->assertSame( $keys, $m[1] );

		// Only the nonce sits outside a panel; every other control is in one.
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$outside = ( new \DOMXPath( $doc ) )->query( '//*[self::input or self::select or self::textarea or self::button][not(ancestor::section[@role="tabpanel"])]/@name' );
		$names   = array();
		foreach ( $outside ?: array() as $attr ) {
			$names[] = $attr->nodeValue;
		}
		$this->assertSame( array( 'ffc_self_scheduling_config_nonce' ), $names );

		// The two dots follow the toggles their section chips followed.
		$this->assertMatchesRegularExpression( '/id="ffc-tabnav-cancellation"[^>]*data-ffc-tab-masters="allow_cancellation"/', $html );
		$this->assertMatchesRegularExpression( '/id="ffc-tabnav-bypass"[^>]*data-ffc-tab-masters="admin_bypass"/', $html );
		$this->assertSame( 2, substr_count( $html, 'data-ffc-tab-masters=' ) );
	}
}
