<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminAudience;
use FreeFormCertificate\Audience\AudienceAdminCalendar;
use FreeFormCertificate\Audience\AudienceAdminEnvironment;

/**
 * The audience calendar and audience editors moved into vertical tabs
 * (#1761) as a visual reorganisation only: no field, button, form, nonce,
 * autosave attribute or link may appear, disappear or change form.
 *
 * The "before" side is `tests/fixtures/audience-editor-inventory.php`,
 * written by THIS test from the renderers as they stood before the tabs
 * (`FFC_UPDATE_AUDIENCE_EDITOR_INVENTORY=1`), never by hand. Each entry is
 * the screen's inventory: every control with the form that posts it, every
 * form, every `data-*` attribute and every link. The "after" side renders
 * the same screens with the same data, so a difference is a change the tabs
 * made to what the screen does, not to how it looks.
 *
 * The screens merged: the Members tab is what `action=members` rendered,
 * and the Environments tab is the Environments list filtered to the
 * calendar, rendered live from the Environments view (which did not change),
 * minus its filter form and its Calendars / Environments view switch — the
 * tab is already filtered, and it sits inside the calendar.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminCalendar
 * @covers \FreeFormCertificate\Audience\AudienceAdminAudience
 * @covers \FreeFormCertificate\Audience\AudienceAdminAudienceRenderer
 * @covers \FreeFormCertificate\Audience\AudienceAdminEnvironment
 * @covers \FreeFormCertificate\Audience\AudienceEditorTabs
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceEditorTabsInventoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const FIXTURE = __DIR__ . '/../fixtures/audience-editor-inventory.php';

	/** View-only and manage tiers (#1777). */
	private const TIERS = array(
		'view'   => array( 'ffc_view_audiences', 'ffc_view_custom_fields' ),
		'manage' => array( 'ffc_view_audiences', 'ffc_manage_audiences', 'ffc_delete_audiences', 'ffc_manage_custom_fields' ),
	);

	/** The data attribute the masked account-email cell carries (#1824). */
	private const MASKED_EMAIL = 'data:data-field=email';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The Post-event form tab lists the published certificate forms (#1948).
		Functions\when( 'get_posts' )->justReturn( array() );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
		Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'wp_nonce_url' )->alias( static fn( $url, $action ) => $url . '&_wpnonce=' . $action );
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( $action, $name ) {
				echo '<input type="hidden" name="' . $name . '" value="nonce:' . $action . '">';
			}
		);
		Functions\when( 'submit_button' )->alias(
			static function ( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other = null ) {
				$form = is_array( $other ) && isset( $other['form'] ) ? ' form="' . $other['form'] . '"' : '';
				echo '<input type="submit" name="' . $name . '" value="' . $text . '" class="button ' . $type . '"' . $form . '>';
			}
		);
		Functions\when( 'wp_editor' )->alias(
			static function ( $content, $id, $settings = array() ) {
				echo '<textarea name="' . ( $settings['textarea_name'] ?? $id ) . '" id="' . $id . '">' . $content . '</textarea>';
			}
		);
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_trim_words' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		// The member and access tables mask account emails (#1824). No tier
		// here holds a PII cap, so a logged-out viewer resolves to the same
		// masked tier without stubbing the role lookup.
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== strpos( (string) $e, '@' ) );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_userdata' )->alias(
			static fn( $id ) => (object) array( 'ID' => $id, 'display_name' => 'User' . $id, 'user_email' => $id . '@e.com' )
		);
		Functions\when( 'get_user_by' )->alias(
			static fn( $field, $id ) => (object) array( 'ID' => $id, 'display_name' => 'User' . $id, 'user_email' => $id . '@e.com' )
		);

	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string $tier Key of self::TIERS.
	 */
	private function grant( string $tier ): void {
		$caps = self::TIERS[ $tier ];
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => in_array( $cap, $caps, true ) );
	}

	/**
	 * The calendar, its grants, holidays and environments. Environment 4
	 * belongs to another calendar, so the filter is what keeps it out.
	 */
	private function mock_calendar_data(): void {
		$schedule = (object) array(
			'id'                           => 5,
			'name'                         => 'Cal Five',
			'description'                  => 'desc',
			'environment_label'            => 'Rooms',
			'visibility'                   => 'public',
			'future_days_limit'            => 30,
			'notify_on_booking'            => 1,
			'notify_on_cancellation'       => 0,
			'include_ics'                  => 1,
			'notify_admin_on_booking'      => 1,
			'notify_admin_on_cancellation' => 0,
			'admin_notification_emails'    => 'a@b.c',
			'show_event_list'              => 1,
			'event_list_position'          => 'below',
			'audience_badge_format'        => 'parent_name',
			'booking_label_singular'       => 'session',
			'booking_label_plural'         => 'sessions',
			'is_isolated'                  => 1,
			'status'                       => 'active',
		);
		$sched = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$sched->shouldReceive( 'get_by_id' )->andReturn( $schedule );
		$sched->shouldReceive( 'get_all' )->andReturn( array( $schedule, (object) array( 'id' => 9, 'name' => 'Other' ) ) );
		$sched->shouldReceive( 'get_environment_label' )->andReturnUsing(
			static fn( $id = null, $singular = false ) => $singular ? 'Room' : 'Rooms'
		);
		$sched->shouldReceive( 'get_all_permissions' )->andReturn(
			array( (object) array( 'user_id' => 7, 'can_book' => '1', 'can_cancel_others' => '0', 'can_override_conflicts' => '1' ) )
		);

		$environments = array(
			(object) array( 'id' => 1, 'schedule_id' => 5, 'name' => 'Room A', 'description' => 'first', 'color' => '#112233', 'status' => 'active' ),
			(object) array( 'id' => 2, 'schedule_id' => 5, 'name' => 'Room B', 'description' => '', 'color' => '#445566', 'status' => 'inactive' ),
			(object) array( 'id' => 4, 'schedule_id' => 9, 'name' => 'Elsewhere', 'description' => '', 'color' => '#778899', 'status' => 'active' ),
		);
		$env = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$env->shouldReceive( 'get_holidays' )->andReturn(
			array( (object) array( 'id' => 3, 'holiday_date' => '2026-12-25', 'description' => 'Christmas' ) )
		);
		$env->shouldReceive( 'get_all' )->andReturnUsing(
			static function ( $args = array() ) use ( $environments ) {
				if ( empty( $args['schedule_id'] ) ) {
					return $environments;
				}
				return array_values( array_filter( $environments, static fn( $e ) => (int) $e->schedule_id === (int) $args['schedule_id'] ) );
			}
		);
		$env->shouldReceive( 'count' )->andReturn( 2 );

		Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )->shouldReceive( 'format_wallclock_date' )->andReturn( '25/12/2026' );
	}

	/**
	 * A child audience (so the breadcrumb and the replicate button render),
	 * its members and one field of each editor kind.
	 */
	private function mock_audience_data(): void {
		$audience = (object) array( 'id' => 5, 'name' => 'Grp', 'color' => '#123456', 'status' => 'active', 'parent_id' => 2, 'allow_self_join' => 1 );
		$reader   = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$reader->shouldReceive( 'get_by_id' )->andReturn( $audience );
		$reader->shouldReceive( 'get_possible_parents' )->andReturn( array( (object) array( 'id' => 2, 'name' => 'Parent', 'depth' => 0 ) ) );
		$reader->shouldReceive( 'get_ancestors' )->andReturn( array( (object) array( 'id' => 2, 'name' => 'Parent', 'color' => '#000000' ) ) );
		$reader->shouldReceive( 'get_children' )->andReturn( array( (object) array( 'id' => 6 ) ) );
		$reader->shouldReceive( 'get_members' )->andReturn( array( 7, 8 ) );
		$reader->shouldReceive( 'get_member_count' )->andReturn( 2 );
		$reader->shouldReceive( 'get_ancestor_ids' )->andReturn( array( 5, 2 ) );

		$seeder = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' );
		$seeder->shouldReceive( 'seed_for_audience' )->andReturn( 0 );
		$seeder->shouldReceive( 'get_group_labels' )->andReturn( array( 'personal' => 'Personal' ) );

		$fields = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldReader' );
		$fields->shouldReceive( 'field_type_labels' )->andReturn( array( 'text' => 'Text', 'select' => 'Select', 'acknowledgment' => 'Acknowledgment' ) );
		$fields->shouldReceive( 'get_by_audience' )->andReturn(
			array(
				(object) array( 'id' => 11, 'field_key' => 'nome_completo', 'field_label' => 'Name', 'field_type' => 'text', 'field_options' => null, 'validation_rules' => null, 'field_source' => 'standard', 'field_group' => 'personal', 'is_required' => 1, 'is_active' => 1, 'is_sensitive' => 0 ),
				(object) array( 'id' => 12, 'field_key' => 'termo_ciencia', 'field_label' => 'Terms', 'field_type' => 'acknowledgment', 'field_options' => '{"html":"<p>ok</p>"}', 'validation_rules' => null, 'field_source' => 'custom', 'field_group' => '', 'is_required' => 0, 'is_active' => 1, 'is_sensitive' => 0 ),
			)
		);
	}

	/**
	 * Render one screen of one page class.
	 *
	 * @param string               $page_slug ?page= of the screen.
	 * @param array<string, mixed> $get       Query arguments.
	 */
	private function render( string $page_slug, array $get ): string {
		$_GET = array_merge( array( 'page' => $page_slug ), $get );
		ob_start();
		if ( AudienceAdminEnvironment::VIEW === ( $get['view'] ?? '' ) ) {
			// The Environments view of the calendars page (#1783), without the page's wrap.
			( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_view();
		} elseif ( 'ffc-scheduling-audiences' === $page_slug ) {
			( new AudienceAdminAudience( 'ffc-scheduling' ) )->render_page();
		} else {
			( new AudienceAdminCalendar( 'ffc-scheduling' ) )->render_page();
		}
		return (string) ob_get_clean();
	}

	/**
	 * Attributes of one tag, as a name => value map.
	 *
	 * @return array<string, string>
	 */
	private static function attrs( string $tag ): array {
		preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:="([^"]*)")?/', (string) preg_replace( '/^<\w+/', '', $tag ), $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $a ) {
			$out[ $a[1] ] = $a[2] ?? '';
		}
		return $out;
	}

	/**
	 * What a screen does, as a sorted list of strings.
	 *
	 * - `form:` every form, by method, action and the `ffc_action` it posts;
	 * - `field:` every named control, by tag, type and name (a hidden or
	 *   submit control with its value), and the form that posts it, resolved
	 *   through a `form="…"` attribute when the control sits outside it;
	 * - `id:` every element id (the hooks the scripts bind to);
	 * - `data:` every `data-*` attribute with its value;
	 * - `href:` every link.
	 *
	 * The tabs' own markup — the nav links, the panel ids, the attributes the
	 * tab script reads — is left out by `$new_ids_allowed` at comparison time,
	 * not here, so the fixture is the plain reading.
	 *
	 * @return list<string>
	 */
	private static function inventory( string $html ): array {
		$items = array();

		// Form regions and their keys.
		$regions = array();
		$ids     = array();
		preg_match_all( '/<form\b[^>]*>/', $html, $fm, PREG_OFFSET_CAPTURE );
		foreach ( $fm[0] as $open ) {
			$start = (int) $open[1];
			$end   = (int) strpos( $html, '</form>', $start );
			$body  = substr( $html, $start, $end - $start );
			$a     = self::attrs( $open[0] );
			$key   = preg_match( '/name="ffc_action" value="([^"]*)"/', $body, $am ) ? $am[1] : strtolower( $a['method'] ?? 'get' ) . '-filter';
			$regions[] = array( $start, $end, $key );
			if ( isset( $a['id'] ) ) {
				$ids[ $a['id'] ] = $key;
			}
			$items[] = 'form:' . strtolower( $a['method'] ?? 'get' ) . ':action=' . ( $a['action'] ?? '(none)' ) . ':' . $key;
		}

		preg_match_all( '/<(input|select|textarea|button)\b[^>]*>/', $html, $cm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
		foreach ( $cm as $c ) {
			$a = self::attrs( $c[0][0] );
			if ( ! isset( $a['name'] ) ) {
				continue;
			}
			$at   = (int) $c[0][1];
			$form = '(none)';
			if ( isset( $a['form'] ) ) {
				$form = $ids[ $a['form'] ] ?? 'unknown-form:' . $a['form'];
			} else {
				foreach ( $regions as $r ) {
					if ( $at > $r[0] && $at < $r[1] ) {
						$form = $r[2];
					}
				}
			}
			$type  = $a['type'] ?? $c[1][0];
			$value = in_array( $type, array( 'hidden', 'submit' ), true ) ? '=' . ( $a['value'] ?? '' ) : '';
			$items[] = 'field:' . $c[1][0] . ':' . $type . ':' . $a['name'] . $value . '@' . $form;
		}

		preg_match_all( '/\sid="([^"]*)"/', $html, $im );
		foreach ( $im[1] as $id ) {
			$items[] = 'id:' . $id;
		}
		preg_match_all( '/\s(data-[a-z-]+)="([^"]*)"/', $html, $dm, PREG_SET_ORDER );
		foreach ( $dm as $d ) {
			$items[] = 'data:' . $d[1] . '=' . $d[2];
		}
		preg_match_all( '/\shref="([^"]*)"/', $html, $hm );
		foreach ( $hm[1] as $href ) {
			$items[] = 'href:' . $href;
		}

		$items = array_values( array_unique( $items ) );
		sort( $items );
		return $items;
	}

	/**
	 * Every screen the fixture records, with how to render it.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
	 */
	private static function screens(): array {
		$screens = array();
		foreach ( array_keys( self::TIERS ) as $tier ) {
			$screens[ 'calendar-new-' . $tier ]       = array( $tier, 'ffc-scheduling-calendars', array( 'action' => 'new' ) );
			$screens[ 'calendar-edit-' . $tier ]      = array( $tier, 'ffc-scheduling-calendars', array( 'action' => 'edit', 'id' => '5' ) );
			$screens[ 'audience-new-' . $tier ]       = array( $tier, 'ffc-scheduling-audiences', array( 'action' => 'new' ) );
			$screens[ 'audience-edit-' . $tier ]      = array( $tier, 'ffc-scheduling-audiences', array( 'action' => 'edit', 'id' => '5' ) );
			$screens[ 'audience-members-' . $tier ]   = array( $tier, 'ffc-scheduling-audiences', array( 'action' => 'members', 'id' => '5' ) );
		}
		return $screens;
	}

	/**
	 * @return array<string, list<string>>
	 */
	private static function fixture(): array {
		$data = require self::FIXTURE;
		$data = is_array( $data ) ? $data : array();

		// Controls the calendar editor gained after the tabs, each by name and
		// with the issue that added it. The fixture records the screens before
		// the tabs and is never edited by hand; a control a later feature adds
		// is declared here instead, so the comparison still catches anything
		// the tabs themselves changed.
		foreach ( self::ADDED_AFTER_TABS as $key => $items ) {
			if ( isset( $data[ $key ] ) ) {
				$data[ $key ] = array_values( array_unique( array_merge( $data[ $key ], $items ) ) );
				sort( $data[ $key ] );
			}
		}

		// Every submit joined the button family (#1904): the same name, value
		// and form, now on a `<button type="submit">` instead of an `<input>`.
		foreach ( $data as $key => $items ) {
			$data[ $key ] = array_map(
				static fn( string $item ): string => 0 === strpos( $item, 'field:input:submit:' ) ? 'field:button:submit:' . substr( $item, strlen( 'field:input:submit:' ) ) : $item,
				$items
			);
			sort( $data[ $key ] );
		}

		return $data;
	}

	/**
	 * Controls added to a screen after the tabs, by fixture key.
	 *
	 * The "Admin bypass" toggle (#1859): on an existing calendar it carries
	 * the record autosave key, as every toggle there does; on a new one it is
	 * a plain field until the calendar is created.
	 *
	 * The holiday Delete's confirm (#1904): the family refuses an inline
	 * `onclick`, so the question moved to ffc-core's delegated `data-confirm`.
	 *
	 * @var array<string, list<string>>
	 */
	private const ADDED_AFTER_TABS = array(
		'calendar-edit-manage' => array(
			'data:data-confirm=Delete this holiday?',
			'data:data-ffc-autosave-record-key=admin_bypass',
			'field:input:checkbox:schedule_admin_bypass@save_schedule',
			'id:schedule_admin_bypass',
		),
		'calendar-edit-view'   => array(
			'data:data-ffc-autosave-record-key=admin_bypass',
			'field:input:checkbox:schedule_admin_bypass@save_schedule',
			'id:schedule_admin_bypass',
		),
		'calendar-new-manage'  => array(
			'field:input:checkbox:schedule_admin_bypass@save_schedule',
			'id:schedule_admin_bypass',
		),
		'calendar-new-view'    => array(
			'field:input:checkbox:schedule_admin_bypass@save_schedule',
			'id:schedule_admin_bypass',
		),
	);

	/**
	 * @return array<string, array{0: string}>
	 */
	public function screen_keys(): array {
		$out = array();
		foreach ( array_keys( self::screens() ) as $key ) {
			$out[ $key ] = array( $key );
		}
		return $out;
	}

	/**
	 * Drop the tabs' own markup from an inventory, and nothing else: the nav
	 * links, the tab and panel ids, the container's script hook, and the id
	 * that lets the side Save button name the calendar form.
	 *
	 * @param list<string> $items Inventory.
	 * @return list<string>
	 */
	private static function without_tab_markup( array $items ): array {
		return array_values(
			array_filter(
				$items,
				static fn( string $item ): bool => 1 !== preg_match( '/^(href:#ffc-tab-|id:ffc-tab(nav|panel)-|id:ffc-schedule-form$|data:data-ffc-vtabs=)/', $item )
			)
		);
	}

	/**
	 * Drop the Post-event form tab's controls (#1948). The fixture predates
	 * them: they are a feature added after the tabs, not a control the tabs
	 * moved, so the callers assert them apart and compare the rest.
	 *
	 * @param list<string> $items Inventory.
	 * @return list<string>
	 */
	private static function without_post_event( array $items ): array {
		return array_values( array_filter( $items, static fn( string $item ): bool => ! str_contains( $item, 'post_event' ) ) );
	}

	/**
	 * Drop the masked account-email cell (#1824). The fixture predates it, and
	 * it is markup rather than a control, so the callers assert it is present
	 * and compare the rest against the fixture.
	 *
	 * @param list<string> $items Inventory.
	 * @return list<string>
	 */
	private static function without_masked_email( array $items ): array {
		return array_values( array_diff( $items, array( self::MASKED_EMAIL ) ) );
	}

	/**
	 * @param list<string> ...$sets Inventories.
	 * @return list<string>
	 */
	private static function union( array ...$sets ): array {
		$all = array_values( array_unique( array_merge( ...$sets ) ) );
		sort( $all );
		return $all;
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function tiers(): array {
		return array(
			'view-only' => array( 'view' ),
			'manage'    => array( 'manage' ),
		);
	}

	/**
	 * @dataProvider tiers
	 */
	public function test_calendar_editor_keeps_every_control( string $tier ): void {
		$this->grant( $tier );
		$this->mock_calendar_data();
		$fixture = self::fixture();

		// The Environments tab is the Environments list of this calendar,
		// rendered by that view, less the filter form and the Calendars /
		// Environments view switch (#1783) the tab does not need: it is
		// already filtered, and it sits inside the calendar.
		// Both Add New links carry the calendar so the form opens with it
		// chosen: the tab's since #1796, the filtered list's since #1801, so
		// the list's `&action=new&schedule_id=5` is taken as it renders and
		// no longer rewritten here.
		$view = 'href:https://example.com/wp-admin/admin.php?page=ffc-scheduling-calendars&view=environments';
		$list = array_values(
			array_filter(
				self::inventory( $this->render( 'ffc-scheduling-calendars', array( 'view' => 'environments', 'schedule_id' => '5' ) ) ),
				static fn( string $item ): bool => ! str_contains( $item, 'get-filter' ) && $view !== $item
			)
		);

		$after = self::inventory( $this->render( 'ffc-scheduling-calendars', array( 'action' => 'edit', 'id' => '5' ) ) );
		$this->assertContains( self::MASKED_EMAIL, $after, 'the User access table masks the account email' );
		$this->assertContains( 'field:select:select:schedule_post_event_form@save_schedule', $after, 'the Post-event form tab posts with the calendar' );
		$this->assertSame( self::union( $fixture[ 'calendar-edit-' . $tier ], $list ), self::without_post_event( self::without_masked_email( self::without_tab_markup( $after ) ) ) );
		if ( 'manage' === $tier ) {
			$this->assertContains( $view . '&action=new&schedule_id=5', $after, 'the tab\'s Add New carries the calendar' );
		}
		$this->assertNotContains( $view . '&action=new', $after, 'no Add New without the calendar' );
		$this->assertContains( $view . '&action=edit&id=1', $after, 'an environment of this calendar is listed' );
		$this->assertNotContains( $view . '&action=edit&id=4', $after, 'another calendar\'s environment is not' );
		$this->assertSame( array(), preg_grep( '/page=ffc-scheduling-environments/', $after ), 'no link is left on the previous slug' );

		$after_new = self::inventory( $this->render( 'ffc-scheduling-calendars', array( 'action' => 'new' ) ) );
		$this->assertSame( $fixture[ 'calendar-new-' . $tier ], self::without_post_event( self::without_tab_markup( $after_new ) ) );
	}

	/**
	 * @dataProvider tiers
	 */
	public function test_audience_editor_keeps_every_control( string $tier ): void {
		$this->grant( $tier );
		$this->mock_audience_data();
		$fixture  = self::fixture();
		$expected = self::union( $fixture[ 'audience-edit-' . $tier ], $fixture[ 'audience-members-' . $tier ] );

		foreach ( array( 'edit', 'members' ) as $action ) {
			$after = self::inventory( $this->render( 'ffc-scheduling-audiences', array( 'action' => $action, 'id' => '5' ) ) );
			$this->assertContains( self::MASKED_EMAIL, $after, 'the Members table masks the account email, action=' . $action );
			$this->assertSame( $expected, self::without_masked_email( self::without_tab_markup( $after ) ), 'action=' . $action );
		}

		// The new form is a top-level audience, so it carries the two
		// tree-access controls (#1918) the fixture predates; assert them, then
		// compare the rest.
		$after_new   = self::inventory( $this->render( 'ffc-scheduling-audiences', array( 'action' => 'new' ) ) );
		$tree_access = preg_grep( '/audience_(members_only|require_membership|tree_access)/', $after_new );
		$this->assertSame(
			array(
				'field:input:checkbox:audience_members_only@save_audience',
				'field:input:checkbox:audience_require_membership@save_audience',
				'field:input:hidden:audience_tree_access=1@save_audience',
				'id:audience_members_only',
				'id:audience_members_only-description',
				'id:audience_require_membership',
				'id:audience_require_membership-description',
			),
			array_values( $tree_access )
		);
		$this->assertSame( $fixture[ 'audience-new-' . $tier ], self::without_tab_markup( array_values( array_diff( $after_new, $tree_access ) ) ) );
	}

	/**
	 * The filter that drops the tab markup must not drop anything the
	 * fixture recorded, or the comparison above would be blind to it.
	 */
	public function test_the_tab_filter_drops_nothing_the_fixture_recorded(): void {
		foreach ( self::fixture() as $key => $items ) {
			$this->assertSame( $items, self::without_tab_markup( $items ), $key );
		}
		$this->assertCount( count( self::screens() ), self::fixture() );
	}

	/**
	 * Writes one screen into the fixture when regenerating; the fixture is
	 * otherwise read-only. Regenerate only from a renderer whose behaviour is
	 * the baseline, and review the diff.
	 *
	 * @dataProvider screen_keys
	 */
	public function test_regenerate_fixture( string $key ): void {
		if ( '1' !== getenv( 'FFC_UPDATE_AUDIENCE_EDITOR_INVENTORY' ) ) {
			$this->assertArrayHasKey( $key, self::fixture(), 'the fixture records every screen' );
			return;
		}
		list( $tier, $page, $get ) = self::screens()[ $key ];
		$this->grant( $tier );
		$this->mock_calendar_data();
		$this->mock_audience_data();
		$data         = is_file( self::FIXTURE ) ? self::fixture() : array();
		$data[ $key ] = self::inventory( $this->render( $page, $get ) );
		ksort( $data );
		$php = "<?php\n// Generated by AudienceEditorTabsInventoryTest from the renderers before #1761. Do not edit by hand.\nreturn array(\n";
		foreach ( $data as $screen => $items ) {
			$php .= "\t" . var_export( $screen, true ) . " => array(\n";
			foreach ( $items as $item ) {
				$php .= "\t\t" . var_export( $item, true ) . ",\n";
			}
			$php .= "\t),\n";
		}
		file_put_contents( self::FIXTURE, $php . ");\n" );
		$this->assertNotEmpty( $data[ $key ] );
	}
}
