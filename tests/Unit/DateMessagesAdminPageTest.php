<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\DateMessagesAdminPage;
use FreeFormCertificate\DateMessages\Rule;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The Date Messages screen's writes (#1538).
 *
 * Every handler ends in a redirect, which the stub turns into an exception so
 * the test can read the outcome the handler left behind.
 *
 * @covers \FreeFormCertificate\DateMessages\DateMessagesAdminPage
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DateMessagesAdminPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $reader;

	/** @var Mockery\MockInterface */
	private $writer;

	/**
	 * Capabilities the current user holds.
	 *
	 * @var array<int, string>
	 */
	private array $caps = array();

	/**
	 * Last outcome written.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $outcome = null;

	/**
	 * Last redirect target.
	 */
	private string $redirect = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_POST         = array();
		$_GET          = array();
		$this->caps    = array( 'ffc_manage_date_messages' );
		$this->outcome = null;

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_kses_post' )->alias( static fn( $v ) => str_replace( '<script>', '', (string) $v ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.org/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn( array $a, string $u ) => $u . '?' . http_build_query( $a ) );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->outcome = $value;
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) {
				$this->redirect = (string) $url;
				throw new \RuntimeException( 'redirect' );
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function ( $message ) {
				throw new \DomainException( (string) $message );
			}
		);

		$this->reader = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleReader' );
		$this->writer = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleWriter' );
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Run a handler to its redirect.
	 *
	 * @param string $method Handler.
	 */
	private function call( string $method ): void {
		try {
			( new DateMessagesAdminPage() )->$method();
			$this->fail( 'The handler did not redirect.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}
	}

	/**
	 * @param array<string, mixed> $over Overrides.
	 */
	private function rule( array $over = array() ): Rule {
		$rule = Rule::from_array(
			array_merge(
				array(
					'id'      => 3,
					'name'    => 'Birthday',
					'subject' => 'Hi',
					'body'    => '<p>Hi</p>',
				),
				$over
			)
		);
		$this->assertInstanceOf( Rule::class, $rule );
		return $rule;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function posted(): array {
		return array(
			'id'           => '0',
			'name'         => 'Week before',
			'source'       => 'birthday',
			'offset_days'  => '-7',
			'audience_ids' => array( '4', '6' ),
			'subject'      => 'Soon',
			'body'         => '<p>Body</p><script>',
			'send_to_user' => '1',
			'is_active'    => '1',
		);
	}

	public function test_every_write_refuses_an_operator_without_the_manage_capability(): void {
		$this->caps = array( 'ffc_view_date_messages' );
		$this->writer->shouldReceive( 'save' )->never();

		foreach ( array( 'handle_save', 'handle_delete', 'handle_duplicate', 'handle_toggle', 'handle_send_now' ) as $handler ) {
			try {
				( new DateMessagesAdminPage() )->$handler();
				$this->fail( "{$handler} did not refuse." );
			} catch ( \DomainException $e ) {
				$this->assertSame( 'You do not have permission to do this.', $e->getMessage(), $handler );
			}
		}
	}

	public function test_view_and_manage_each_open_the_screen_and_only_manage_writes(): void {
		$this->caps = array( 'ffc_view_date_messages' );
		$this->assertTrue( DateMessagesAdminPage::can_view() );
		$this->assertFalse( DateMessagesAdminPage::can_manage() );

		$this->caps = array( 'ffc_manage_date_messages' );
		$this->assertTrue( DateMessagesAdminPage::can_view(), 'A manage grant needs no view grant beside it.' );
		$this->assertFalse( DateMessagesAdminPage::can_view_pii() );

		$this->caps = array();
		$this->assertFalse( DateMessagesAdminPage::can_view() );
	}

	public function test_the_menu_names_the_capability_the_user_holds(): void {
		$registered = array();
		Functions\when( 'add_menu_page' )->alias(
			static function ( ...$args ) use ( &$registered ) {
				$registered[] = array( $args[2], $args[3], $args[6] );
				return 'hook';
			}
		);

		$this->caps = array( 'ffc_manage_date_messages' );
		( new DateMessagesAdminPage() )->register_menu();
		$this->caps = array( 'ffc_view_date_messages' );
		( new DateMessagesAdminPage() )->register_menu();

		$this->assertSame(
			array(
				array( 'ffc_manage_date_messages', 'ffc-date-messages', 26.5 ),
				array( 'ffc_view_date_messages', 'ffc-date-messages', 26.5 ),
			),
			$registered,
			'A top-level menu in the FFC block.'
		);
	}

	public function test_a_new_rule_is_saved_with_its_body_filtered(): void {
		$_POST['rule'] = $this->posted();
		$this->writer->shouldReceive( 'save' )->once()->with(
			Mockery::on(
				static fn( Rule $r ): bool => 0 === $r->id && -7 === $r->offset_days && '<p>Body</p>' === $r->body && $r->is_active
			)
		)->andReturn( 12 );

		$this->call( 'handle_save' );

		$this->assertSame( 'success', $this->outcome['type'] );
		$this->assertStringContainsString( 'rule=12', $this->redirect );
	}

	public function test_an_invalid_rule_goes_back_to_the_editor_with_what_was_typed(): void {
		$_POST['rule']                = $this->posted();
		$_POST['rule']['offset_days'] = '400';
		$this->writer->shouldReceive( 'save' )->never();

		$this->call( 'handle_save' );

		$this->assertSame( 'error', $this->outcome['type'] );
		$this->assertSame( 'Week before', $this->outcome['draft']['name'] );
		$this->assertStringContainsString( 'rule=0', $this->redirect );
	}

	public function test_the_digest_settings_are_saved_from_the_form(): void {
		$_POST['rule']                    = $this->posted();
		$_POST['rule']['id']              = '3';
		$_POST['rule']['digest_enabled']  = '1';
		$_POST['rule']['digest_mode']     = 'detailed';
		$_POST['rule']['digest_user_ids'] = array( '9', '9', 'x', '4' );
		$this->reader->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( $this->rule() );
		$this->writer->shouldReceive( 'save' )->once()->with(
			Mockery::on( static fn( Rule $r ): bool => 3 === $r->id && $r->digest_enabled && 'detailed' === $r->digest_mode && array( 9, 4 ) === $r->digest_user_ids )
		)->andReturn( 3 );

		$this->call( 'handle_save' );

		$this->assertSame( 'success', $this->outcome['type'] );
	}

	public function test_an_unchecked_digest_box_turns_the_digest_off(): void {
		$_POST['rule']       = $this->posted();
		$_POST['rule']['id'] = '3';
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $this->rule( array( 'digest_enabled' => '1' ) ) );
		$this->writer->shouldReceive( 'save' )->once()->with(
			Mockery::on( static fn( Rule $r ): bool => ! $r->digest_enabled && array() === $r->digest_user_ids )
		)->andReturn( 3 );

		$this->call( 'handle_save' );
	}

	public function test_manager_options_offer_only_accounts_that_may_open_the_screen(): void {
		$queried = null;
		$admin   = new \WP_User();
		$admin->ID           = 1;
		$admin->display_name = 'Admin';
		$admin->user_email   = 'admin@example.org';
		Functions\when( 'get_users' )->alias(
			static function ( $args ) use ( &$queried, $admin ) {
				$queried = $args;
				return array( $admin, 'not a user' );
			}
		);

		$this->assertSame( array( 1 => 'Admin <admin@example.org>' ), DateMessagesAdminPage::manager_options() );
		$this->assertSame(
			array( 'manage_options', 'ffc_view_date_messages', 'ffc_manage_date_messages', 'ffc_view_date_messages_pii' ),
			$queried['capability__in']
		);
	}

	/**
	 * @dataProvider periods
	 */
	public function test_upcoming_resolves_each_period_to_its_dates( string $period, string $from, string $to ): void {
		$preview = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RecipientPreview' );
		$preview->shouldReceive( 'collect' )->once()->with(
			Mockery::type( Rule::class ),
			Mockery::on( static fn( $d ): bool => $from === $d->format( 'Y-m-d' ) ),
			Mockery::on( static fn( $d ): bool => $to === $d->format( 'Y-m-d' ) ),
			true
		)->andReturn(
			array(
				'totals'    => array(),
				'rows'      => array(),
				'truncated' => false,
				'pii'       => true,
			)
		);

		$out = DateMessagesAdminPage::upcoming( $period, array(), new \DateTimeImmutable( '2026-10-03', new \DateTimeZone( 'America/Sao_Paulo' ) ) );

		$this->assertSame( $from, $out['from']->format( 'Y-m-d' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function periods(): array {
		return array(
			'next 7'          => array( 'next7', '2026-10-03', '2026-10-09' ),
			'next 30'         => array( 'next30', '2026-10-03', '2026-11-01' ),
			'unknown → 30'    => array( 'whatever', '2026-10-03', '2026-11-01' ),
			'this month'      => array( 'm10', '2026-10-01', '2026-10-31' ),
			'a later month'   => array( 'm12', '2026-12-01', '2026-12-31' ),
			'a past month'    => array( 'm2', '2027-02-01', '2027-02-28' ),
		);
	}

	public function test_upcoming_drops_people_outside_the_audience_and_keeps_opt_outs_flagged(): void {
		$row     = static fn( int $id, string $d ): array => array(
			'user_id'  => $id,
			'name'     => "P{$id}",
			'email'    => 'x',
			'date'     => '2026-10-05',
			'decision' => $d,
		);
		$preview = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RecipientPreview' );
		$preview->shouldReceive( 'collect' )->once()->with(
			Mockery::on( static fn( Rule $r ): bool => array( 9 ) === $r->audience_ids ),
			Mockery::any(),
			Mockery::any(),
			true
		)->andReturn(
			array(
				'totals'    => array(),
				'rows'      => array( $row( 1, 'will_send' ), $row( 2, 'out_of_audience' ), $row( 3, 'opted_out' ) ),
				'truncated' => true,
				'pii'       => true,
			)
		);

		$out = DateMessagesAdminPage::upcoming( 'next7', array( 9 ), new \DateTimeImmutable( '2026-10-03' ) );

		$this->assertSame( array( 1, 3 ), array_column( $out['rows'], 'user_id' ) );
		$this->assertTrue( $out['truncated'] );
	}

	public function test_saving_a_rule_that_was_deleted_meanwhile_fails(): void {
		$_POST['rule']       = $this->posted();
		$_POST['rule']['id'] = '3';
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( null );
		$this->writer->shouldReceive( 'save' )->never();

		$this->call( 'handle_save' );

		$this->assertSame( 'error', $this->outcome['type'] );
	}

	public function test_delete_checks_a_nonce_keyed_to_the_rule(): void {
		$_POST['rule_id'] = '3';
		$checked = array();
		Functions\when( 'check_admin_referer' )->alias(
			static function ( $action ) use ( &$checked ) {
				$checked[] = $action;
				return 1;
			}
		);
		$this->writer->shouldReceive( 'delete' )->once()->with( 3 )->andReturn( true );

		$this->call( 'handle_delete' );

		$this->assertSame( array( DateMessagesAdminPage::DELETE_ACTION . '_3' ), $checked );
		$this->assertSame( 'success', $this->outcome['type'] );
	}

	public function test_a_duplicate_is_an_inactive_copy_named_as_such(): void {
		$_POST['rule_id'] = '3';
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$this->writer->shouldReceive( 'save' )->once()->with(
			Mockery::on( static fn( Rule $r ): bool => 0 === $r->id && ! $r->is_active && 'Birthday (copy)' === $r->name )
		)->andReturn( 13 );

		$this->call( 'handle_duplicate' );

		$this->assertStringContainsString( 'rule=13', $this->redirect );
	}

	public function test_toggle_flips_the_active_flag(): void {
		$_POST['rule_id'] = '3';
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$this->writer->shouldReceive( 'save' )->once()->with(
			Mockery::on( static fn( Rule $r ): bool => 3 === $r->id && ! $r->is_active )
		)->andReturn( 3 );

		$this->call( 'handle_toggle' );

		$this->assertSame( 'Rule deactivated.', $this->outcome['message'] );
	}

	public function test_send_now_needs_a_rule_and_two_real_dates(): void {
		$runner = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\Runner' );
		$runner->shouldReceive( 'start' )->never();
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$_POST = array(
			'rule_id' => '3',
			'from'    => '2026-02-30',
			'to'      => '2026-03-01',
		);

		$this->call( 'handle_send_now' );

		$this->assertSame( 'error', $this->outcome['type'] );
		$this->assertStringContainsString( 'tab=send', $this->redirect );
	}

	public function test_send_now_starts_a_manual_run_by_the_operator(): void {
		$runner = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\Runner' );
		$runner->shouldReceive( 'start' )->once()->with(
			Mockery::type( Rule::class ),
			Mockery::on( static fn( $d ): bool => '2026-10-01' === $d->format( 'Y-m-d' ) ),
			Mockery::on( static fn( $d ): bool => '2026-10-07' === $d->format( 'Y-m-d' ) ),
			'manual',
			5
		)->andReturn( 44 );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$_POST = array(
			'rule_id' => '3',
			'from'    => '2026-10-01',
			'to'      => '2026-10-07',
		);

		$this->call( 'handle_send_now' );

		$this->assertSame( 'success', $this->outcome['type'] );
		$this->assertStringContainsString( 'tab=history', $this->redirect );
	}

	public function test_send_now_reports_a_range_the_runner_refuses(): void {
		$runner = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\Runner' );
		$runner->shouldReceive( 'start' )->andReturn( new \WP_Error( 'ffc_date_messages_range', 'Too wide' ) );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$_POST = array(
			'rule_id' => '3',
			'from'    => '2026-10-01',
			'to'      => '2026-12-01',
		);

		$this->call( 'handle_send_now' );

		$this->assertSame( 'Too wide', $this->outcome['message'] );
	}

	public function test_date_accepts_only_a_real_calendar_day(): void {
		$this->assertSame( '2028-02-29', DateMessagesAdminPage::date( '2028-02-29' )->format( 'Y-m-d' ) );
		$this->assertNull( DateMessagesAdminPage::date( '2027-02-29' ) );
		$this->assertNull( DateMessagesAdminPage::date( '29/02/2028' ) );
		$this->assertNull( DateMessagesAdminPage::date( '' ) );
	}

	public function test_form_data_reads_unchecked_boxes_as_off(): void {
		$data = DateMessagesAdminPage::form_data(
			array(
				'name'    => 'x',
				'subject' => 's',
				'body'    => array( 'not a string' ),
			)
		);

		$this->assertSame( '0', $data['send_to_user'] );
		$this->assertSame( '0', $data['is_active'] );
		$this->assertSame( '', $data['body'] );
		$this->assertSame( 'birthday', $data['source'] );
	}
	public function test_the_screen_refuses_an_operator_with_neither_capability(): void {
		$this->caps = array();

		$this->expectException( \DomainException::class );
		( new DateMessagesAdminPage() )->render_page();
	}

	public function test_assets_load_on_this_screen_only_and_carry_both_nonces(): void {
		if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
			define( 'FFC_PLUGIN_URL', 'https://example.org/p/' );
		}
		Mockery::mock( 'alias:FreeFormCertificate\Core\AssetHelper' )->shouldReceive( 'asset_suffix' )->andReturn( '.min' );
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\MessageBuilder' )->shouldReceive( 'defaults' )->andReturn(
			array(
				'subject' => 'S',
				'body'    => 'DEFAULT',
			)
		);
		Functions\when( 'wp_create_nonce' )->alias( static fn( $a ) => 'nonce-' . $a );
		$media     = 0;
		Functions\when( 'wp_enqueue_media' )->alias(
			static function () use ( &$media ) {
				++$media;
			}
		);
		$scripts   = array();
		$styles    = array();
		$localized = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $handle ) use ( &$styles ) {
				$styles[] = $handle;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$scripts ) {
				$scripts[] = $handle;
			}
		);
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $name, $data ) use ( &$localized ) {
				$localized[ $name ] = $data;
				return true;
			}
		);

		( new DateMessagesAdminPage() )->enqueue( 'toplevel_page_ffc-settings' );
		$this->assertSame( 0, $media );
		$this->assertSame( array(), $scripts );
		$this->assertSame( array(), $styles );

		( new DateMessagesAdminPage() )->enqueue( 'ffc_form_page_ffc-date-messages' );
		$this->assertSame( array( 'ffc-date-messages-admin', 'ffc-audience-transfer-list', 'ffc-email-restore-default' ), $scripts );
		$this->assertSame( array( 'ffc-admin-settings', 'wp-color-picker', 'ffc-audience-transfer-list' ), $styles, 'the vertical tab layout lives in that sheet; the body appearance colours use core\'s picker; the rule editor\'s audience picker brings its own' );
		$this->assertSame( 1, $media, 'the background image comes from the Media Library' );
		$this->assertSame( 'nonce-ffc_date_messages_message_preview', $localized['ffcDateMessages']['messageNonce'] );
		$this->assertSame( 'nonce-ffc_date_messages_preview', $localized['ffcDateMessages']['previewNonce'] );
		$this->assertSame( 'nonce-ffc_date_messages_test_send', $localized['ffcDateMessages']['testNonce'] );
		$this->assertSame( 'DEFAULT', $localized['ffcEmailRestoreDefaults']['date_message_body']['body'] );
	}

	public function test_audience_options_indent_the_hierarchy(): void {
		$child  = (object) array(
			'id'       => '2',
			'name'     => 'Teachers',
			'children' => array(),
		);
		$parent = (object) array(
			'id'       => '1',
			'name'     => 'Schools',
			'children' => array( $child, 'not a node' ),
		);
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )->shouldReceive( 'get_hierarchical' )->with( 'active' )->andReturn( array( $parent ) );

		$this->assertSame(
			array(
				1 => 'Schools',
				2 => '— Teachers',
			),
			DateMessagesAdminPage::audience_options()
		);
	}

	/**
	 * Render the backfill notice with a given pending count.
	 *
	 * @param int $pending Accounts the migration card still has to examine.
	 * @return array{0: string, 1: array<string, mixed>}|null The notice, or null when none was printed.
	 */
	private function backfill_notice( int $pending ): ?array {
		Mockery::mock( 'alias:FreeFormCertificate\Migrations\Strategies\BirthDateBackfillMigrationStrategy' )
			->shouldReceive( 'pending_accounts' )->andReturn( $pending );
		Functions\when( '_n' )->alias( static fn( $one, $many, $n ) => 1 === $n ? $one : $many );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();

		$printed = null;
		Functions\when( 'wp_admin_notice' )->alias(
			function ( $message, $args ) use ( &$printed ) {
				$printed = array( $message, $args );
			}
		);

		DateMessagesAdminPage::render_backfill_notice();

		return $printed;
	}

	/**
	 * An empty preview must never read as "nobody has a birthday" while the
	 * migration has dates it has not copied yet (#1538).
	 */
	public function test_the_backfill_notice_names_the_pending_accounts(): void {
		$notice = $this->backfill_notice( 3 );

		$this->assertNotNull( $notice );
		$this->assertStringContainsString( 'has 3 accounts left to examine', $notice[0] );
		$this->assertSame( 'warning', $notice[1]['type'] );
		$this->assertFalse( $notice[1]['dismissible'] );
	}

	public function test_no_backfill_notice_once_the_migration_is_done(): void {
		$this->assertNull( $this->backfill_notice( 0 ) );
	}

	public function test_the_migrations_link_is_offered_only_to_who_can_run_them(): void {
		$this->caps = array( 'ffc_view_date_messages' );
		$without    = $this->backfill_notice( 1 );

		$this->assertStringContainsString( 'has 1 account left', $without[0] );
		$this->assertStringNotContainsString( 'tab=migrations', $without[0] );
	}

	public function test_the_migrations_link_is_offered_to_a_danger_zone_operator(): void {
		$this->caps = array( 'ffc_view_date_messages', 'ffc_manage_settings_dangerzone' );
		$with       = $this->backfill_notice( 1 );

		$this->assertStringContainsString( 'admin.php?page=ffc-settings&tab=migrations', $with[0] );
	}

	// ------------------------------------------------------------------
	// Upcoming dates filtered by rule, then audience (#1648)
	// ------------------------------------------------------------------

	/**
	 * @param int        $id        Rule id.
	 * @param array<int> $audiences Audience ids.
	 * @param bool       $active    Whether it is active.
	 * @return Rule
	 */
	private function filter_rule( int $id, array $audiences, bool $active = true ): Rule {
		$rule = Rule::from_array(
			array(
				'id'           => $id,
				'name'         => 'R' . $id,
				'subject'      => 's',
				'body'         => 'b',
				'audience_ids' => $audiences,
				'is_active'    => $active ? '1' : '0',
			)
		);
		$this->assertInstanceOf( Rule::class, $rule );
		return $rule;
	}

	public function test_upcoming_audiences_are_those_of_the_chosen_rule(): void {
		$all   = array( 1 => 'A', 2 => '— A1', 3 => 'B', 4 => 'C' );
		$rules = array( $this->filter_rule( 7, array( 3, 1 ) ), $this->filter_rule( 8, array( 4 ) ), $this->filter_rule( 9, array(), false ) );

		$this->assertSame( array( 1 => 'A', 3 => 'B' ), DateMessagesAdminPage::upcoming_audience_options( $rules, 7, $all ), 'In the tree\'s order.' );
		$this->assertSame( array( 1 => 'A', 3 => 'B', 4 => 'C' ), DateMessagesAdminPage::upcoming_audience_options( $rules, 0, $all ), 'No rule chosen: every active rule\'s audiences.' );
	}

	public function test_a_rule_reaching_everyone_offers_every_audience(): void {
		$all = array( 1 => 'A', 3 => 'B' );

		$this->assertSame( $all, DateMessagesAdminPage::upcoming_audience_options( array( $this->filter_rule( 7, array() ) ), 7, $all ) );
		$this->assertSame( $all, DateMessagesAdminPage::upcoming_audience_options( array( $this->filter_rule( 7, array( 1 ) ), $this->filter_rule( 8, array() ) ), 0, $all ), 'An active rule for everyone widens the "all rules" list.' );
		$this->assertSame( $all, DateMessagesAdminPage::upcoming_audience_options( array( $this->filter_rule( 7, array( 1 ), false ) ), 0, $all ), 'With no active rule the filter is not narrowed to nothing.' );
	}

	public function test_upcoming_scope_prefers_the_audience_then_the_rule(): void {
		$rules = array( $this->filter_rule( 7, array( 3, 1 ) ), $this->filter_rule( 9, array( 5 ), false ) );

		$this->assertSame( array( 1 ), DateMessagesAdminPage::upcoming_scope( $rules, 7, 1 ) );
		$this->assertSame( array( 3, 1 ), DateMessagesAdminPage::upcoming_scope( $rules, 7, 0 ) );
		$this->assertSame( array(), DateMessagesAdminPage::upcoming_scope( $rules, 0, 0 ), 'Neither chosen: everyone.' );
		$this->assertSame( array(), DateMessagesAdminPage::upcoming_scope( $rules, 9, 0 ), 'An inactive rule does not narrow the list.' );
	}
}
