<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingEditor;

/**
 * Runs in the shared process: the occupancy report runs the real
 * DateFormatter down to a stubbed `wp_date`, and `$wpdb` is restored in
 * tearDown.
 *
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingEditor
 */
class SelfSchedulingEditorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array{0: bool, 1: mixed} Whether `$wpdb` was set, and its value. */
	private array $wpdb_backup = array( false, null );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb_backup = array( array_key_exists( 'wpdb', $GLOBALS ), $GLOBALS['wpdb'] ?? null );
		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );

		Functions\when( '__' )->returnArg();
		// The calendar names partial reads the default state names (#2223).
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		// The section chips (#1629) label their On/Off states.
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_meta_box' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		// default_confirmation_body() now resolves the effective global (#965) →
		// reads ffc_email_bodies; no override → the shipped file default.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_the_ID' )->justReturn( 10 );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'disabled' )->justReturn( '' );

		// $wpdb mock — no appointments, so the #941 mode/block locks stay off.
		global $wpdb;
		$wpdb         = \Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $query ) {
				return $query;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturn( 0 );
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'add_query_arg' )->justReturn( '/' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
	}

	protected function tearDown(): void {
		if ( $this->wpdb_backup[0] ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup[1];
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_creates_instance(): void {
		$editor = new SelfSchedulingEditor();
		$this->assertInstanceOf( SelfSchedulingEditor::class, $editor );
	}

	// ==================================================================
	// enqueue_scripts() — wrong hook
	// ==================================================================

	public function test_enqueue_scripts_returns_early_on_wrong_hook(): void {
		// #1030: this asserted nothing, so the guard could be deleted and it
		// would still pass. Collect what gets enqueued and state that nothing
		// did — that is the whole claim in the test's name.
		$enqueued = array();
		$collect  = static function ( $handle ) use ( &$enqueued ) {
			$enqueued[] = $handle;
		};
		Functions\when( 'wp_enqueue_script' )->alias( $collect );
		Functions\when( 'wp_enqueue_style' )->alias( $collect );

		$editor = new SelfSchedulingEditor();
		$editor->enqueue_scripts( 'edit.php' );
		$this->assertSame( array(), $enqueued );
	}

	// ==================================================================
	// enqueue_scripts() — wrong post type
	// ==================================================================

	public function test_enqueue_scripts_returns_early_on_wrong_post_type(): void {
		$screen = (object) array( 'post_type' => 'post' );
		Functions\when( 'get_current_screen' )->justReturn( $screen );

		// #1030: this asserted nothing, so the guard could be deleted and it
		// would still pass. Collect what gets enqueued and state that nothing
		// did — that is the whole claim in the test's name.
		$enqueued = array();
		$collect  = static function ( $handle ) use ( &$enqueued ) {
			$enqueued[] = $handle;
		};
		Functions\when( 'wp_enqueue_script' )->alias( $collect );
		Functions\when( 'wp_enqueue_style' )->alias( $collect );

		$editor = new SelfSchedulingEditor();
		$editor->enqueue_scripts( 'post.php' );
		$this->assertSame( array(), $enqueued );
	}

	// ==================================================================
	// enqueue_scripts() — no screen
	// ==================================================================

	public function test_enqueue_scripts_returns_early_without_screen(): void {
		Functions\when( 'get_current_screen' )->justReturn( null );

		// #1030: this asserted nothing, so the guard could be deleted and it
		// would still pass. Collect what gets enqueued and state that nothing
		// did — that is the whole claim in the test's name.
		$enqueued = array();
		$collect  = static function ( $handle ) use ( &$enqueued ) {
			$enqueued[] = $handle;
		};
		Functions\when( 'wp_enqueue_script' )->alias( $collect );
		Functions\when( 'wp_enqueue_style' )->alias( $collect );

		$editor = new SelfSchedulingEditor();
		$editor->enqueue_scripts( 'post.php' );
		$this->assertSame( array(), $enqueued );
	}

	// ==================================================================
	// enqueue_scripts() — correct context
	// ==================================================================

	public function test_enqueue_scripts_enqueues_on_self_scheduling(): void {
		$screen = (object) array( 'post_type' => 'ffc_self_scheduling' );
		Functions\when( 'get_current_screen' )->justReturn( $screen );

		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued ) {
			$enqueued[] = func_get_arg( 0 );
		} );
		$styles = array();
		Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$styles ) {
			$styles[] = func_get_arg( 0 );
		} );

		$editor = new SelfSchedulingEditor();
		$editor->enqueue_scripts( 'post.php' );

		$this->assertContains( 'ffc-calendar-editor', $enqueued );
		// ffc-common carries the .ffc-control-toggle switch styles the editor's
		// render_toggle controls depend on.
		$this->assertContains( 'ffc-common', $styles );
		$this->assertContains( 'ffc-calendar-editor', $styles );
		// The vertical tabs (#1761) and the sections inside the Emails tab (#1629).
		$this->assertContains( 'ffc-admin-components', $styles );
		$this->assertContains( 'ffc-vertical-tabs', $enqueued );
		$this->assertContains( 'ffc-admin-sections', $enqueued );
	}

	// ==================================================================
	// add_custom_metaboxes()
	// ==================================================================

	public function test_add_custom_metaboxes_registers_boxes(): void {
		// #1030: the name claims metaboxes are registered; nothing checked it.
		$boxes = array();
		Functions\when( 'add_meta_box' )->alias(
			static function ( $id ) use ( &$boxes ) {
				$boxes[] = $id;
				return true;
			}
		);

		$editor = new SelfSchedulingEditor();
		$editor->add_custom_metaboxes();

		$this->assertNotEmpty( $boxes, 'add_custom_metaboxes() registered no box' );
	}

	public function test_metabox_titles_carry_no_step_number(): void {
		// The titles read "1. …" to "5. …" while the boxes can be reordered
		// and the fifth only exists in custom mode (#1629).
		$titles = array();
		Functions\when( 'add_meta_box' )->alias(
			static function ( $id, $title ) use ( &$titles ) {
				$titles[] = $title;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( array( 'schedule_type' => 'custom' ) );

		$editor = new SelfSchedulingEditor();
		$editor->add_custom_metaboxes();

		// One tabbed box in the normal column, the shortcode and clean-up cards
		// in the side (#1761); the occupancy report is a tab, not a box.
		$this->assertSame( array( 'Calendar Configuration', 'How to Use / Shortcode', 'Clean Up Appointments' ), $titles );
		foreach ( $titles as $title ) {
			$this->assertDoesNotMatchRegularExpression( '/^\d+\./', $title );
		}
	}

	// ==================================================================
	// render_box_config() — every setting, in vertical tabs (#1761)
	// ==================================================================

	/**
	 * Render the configuration box for a post.
	 *
	 * @return string
	 */
	private function render_config(): string {
		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = 10;
		// The administrator-recipients field prints the site address as its placeholder.
		Functions\when( 'get_option' )->alias( static fn( $key ) => 'admin_email' === $key ? 'site@example.com' : array() );
		Functions\when( 'wp_editor' )->alias(
			static function ( $content, $id ) {
				echo '<textarea id="' . $id . '">' . $content . '</textarea>';
			}
		);

		// No calendar row: the Blocked dates tab (#2244) says to save first.
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->andReturn( null );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		// No grid refusal left by a save (#2244).
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );

		$editor = new SelfSchedulingEditor();
		ob_start();
		$editor->render_box_config( $post );
		return (string) ob_get_clean();
	}

	public function test_render_box_config_outputs_every_group(): void {
		$output = $this->render_config();

		$this->assertStringContainsString( 'slot_duration', $output );
		$this->assertStringContainsString( 'slot_interval', $output );
		$this->assertStringContainsString( 'ffc-working-hours', $output );
		$this->assertStringContainsString( 'advance_booking', $output );
		$this->assertStringContainsString( 'send_user_confirmation', $output );
		// TinyMCE editor + the "Restore Default Text" button for the confirmation body.
		$this->assertStringContainsString( 'id="user_confirmation_body"', $output );
		$this->assertStringContainsString( 'ffc-email-restore-default', $output );
	}

	public function test_tabs_use_the_form_editor_component_and_registry_icons(): void {
		$output = $this->render_config();

		$this->assertStringContainsString( '<div class="ffc-vtabs" data-ffc-vtabs>', $output );
		preg_match_all( '/<span class="ffc-vtabs__icon ([^"]+)"/', $output, $m );
		// Each icon twice: in the nav and in the panel title. Ten tabs on a
		// saved calendar, the Blocked dates one included (#2244).
		$this->assertCount( 20, $m[1] );
		$this->assertContains( 'ffc-icon-lock', $m[1] );
		$this->assertStringContainsString( 'Save the calendar first; its blocked dates can be added once it exists.', $output );
		foreach ( $m[1] as $class ) {
			$this->assertMatchesRegularExpression( '/^ffc-icon-[a-z-]+$/', $class, 'A tab icon the registry does not know falls back to a dashicon' );
		}
		$this->assertStringNotContainsString( 'dashicons', $output );
		// The first tab starts active; the others are reachable by the arrow keys.
		$this->assertSame( 1, substr_count( $output, 'aria-selected="true"' ) );
		$this->assertSame( 9, substr_count( $output, 'aria-selected="false"' ) );
	}

	public function test_the_occupancy_tab_exists_only_for_a_saved_custom_calendar(): void {
		$this->assertStringNotContainsString( 'ffc-tabpanel-occupancy', $this->render_config() );

		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_ffc_self_scheduling_config' === $key ? array( 'schedule_type' => 'custom' ) : ''
		);
		$output = $this->render_config();
		$this->assertStringContainsString( 'id="ffc-tabpanel-occupancy"', $output );
		$this->assertStringContainsString( 'Add custom blocks to see the occupancy report.', $output );
	}

	public function test_dependent_rows_start_hidden_with_a_real_style_attribute(): void {
		// The rows used to print `esc_attr( 'style="display:none;"' )`, which
		// escapes the quotes into an attribute nobody reads, so the deadline
		// and waitlist-capacity rows always showed on load (#1629).
		Functions\when( 'esc_attr' )->alias(
			static function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES );
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_ffc_self_scheduling_config' === $key
				? array(
					'allow_cancellation' => 0,
					'waitlist_enabled'   => 0,
				)
				: ''
		);

		$output = $this->render_config();

		$this->assertStringContainsString( '<tr class="ffc-cancellation-hours" style="display:none;">', $output );
		$this->assertStringContainsString( '<tr class="ffc-waitlist-capacity" style="display:none;">', $output );
		$this->assertStringNotContainsString( '&quot;display:none;&quot;', $output );
	}

	public function test_the_emails_tab_keeps_its_three_sections(): void {
		// The Emails tab holds three subjects, so they stay collapsible
		// sections inside it -- the only sections left on the screen.
		$output = $this->render_config();

		$this->assertSame( 3, substr_count( $output, '<details class="ffc-section"' ) );
		$this->assertSame( 3, substr_count( $output, '<span class="ffc-section__icon"><svg' ) );
		$this->assertStringContainsString( 'data-ffc-section-master="ffc_send_admin_notification"', $output );
		$this->assertStringContainsString( 'data-ffc-section-master="ffc_send_user_confirmation"', $output );
		$this->assertSame( substr_count( $output, '<details' ), substr_count( $output, '</details>' ) );
		$this->assertSame( substr_count( $output, '<section' ), substr_count( $output, '</section>' ) );
	}

	// ==================================================================
	// render_box_occupancy() — #941 phase 3
	// ==================================================================

	public function test_render_box_occupancy_empty_blocks_shows_hint(): void {
		// get_post_meta returns '' (setUp default) → no configured blocks.
		$post     = (object) array( 'ID' => 10 );
		$editor   = new SelfSchedulingEditor();
		ob_start();
		$editor->render_box_occupancy( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Add custom blocks', $output );
	}

	public function test_render_box_occupancy_renders_table_with_counts(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				if ( '_ffc_self_scheduling_custom_slots' === $key ) {
					return array(
						array( 'date' => '2026-09-03', 'start' => '08:00', 'end' => '13:00', 'capacity' => 40, 'label' => 'Manha' ),
					);
				}
				return '';
			}
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		global $wpdb;
		// CalendarRepository::findByPostId → the calendar row.
		$wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 5, 'post_id' => 10 ) );
		// AppointmentReader::getOccupancyCounts → grouped counts (39 booked / 2 waiting).
		$wpdb->shouldReceive( 'get_results' )->andReturn( array(
			array( 'date' => '2026-09-03', 'start' => '08:00:00', 'booked' => '39', 'waitlisted' => '2' ),
		) );

		// The real DateFormatter renders the block's date: no plugin setting
		// (get_option answers an empty array), so the shipped 'd/m/Y' format,
		// in UTC because the date is a wall-clock value.
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts, $tz = null ) => ( new \DateTimeImmutable( '@' . $ts ) )
				->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )
				->format( $format )
		);

		$editor = new SelfSchedulingEditor();
		ob_start();
		$editor->render_box_occupancy( (object) array( 'ID' => 10 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( '39 / 40', $output );
		$this->assertStringContainsString( 'Manha', $output );
		$this->assertStringContainsString( '98%', $output ); // round(100 * 39 / 40)
		$this->assertStringContainsString( '08:00–13:00', $output );
		$this->assertStringContainsString( '<td>03/09/2026</td>', $output );
	}

	// ==================================================================
	// render_shortcode_metabox() — published
	// ==================================================================

	public function test_render_shortcode_metabox_shows_shortcode_for_published(): void {
		$post = Mockery::mock( 'WP_Post' );
		$post->ID = 42;
		$post->post_status = 'publish';

		$editor = new SelfSchedulingEditor();
		ob_start();
		$editor->render_shortcode_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ffc_self_scheduling id="42"', $output );
		// The shared copy button reads the read-only input it targets (#1629).
		$this->assertStringContainsString( 'id="ffc-calendar-shortcode"', $output );
		$this->assertStringContainsString( 'data-ffc-copy-target="#ffc-calendar-shortcode"', $output );
	}

	public function test_render_shortcode_metabox_prints_no_dead_preview_link(): void {
		$post = Mockery::mock( 'WP_Post' );
		$post->ID = 42;
		$post->post_status = 'publish';

		$editor = new SelfSchedulingEditor();
		ob_start();
		$editor->render_shortcode_metabox( $post );
		$output = ob_get_clean();

		// Nothing handles `calendar_preview`, and a live calendar would book for real (#1770).
		$this->assertStringNotContainsString( 'calendar_preview', $output );
		$this->assertStringNotContainsString( 'Preview Calendar', $output );
	}

	// ==================================================================
	// render_shortcode_metabox() — draft
	// ==================================================================

	public function test_render_shortcode_metabox_shows_message_for_draft(): void {
		$post = Mockery::mock( 'WP_Post' );
		$post->ID = 42;
		$post->post_status = 'draft';

		$editor = new SelfSchedulingEditor();
		ob_start();
		$editor->render_shortcode_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Publish this calendar', $output );
	}
}
