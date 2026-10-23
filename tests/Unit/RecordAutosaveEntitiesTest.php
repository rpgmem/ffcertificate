<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceRecordAutosave;
use FreeFormCertificate\Core\RecordAutosave;
use FreeFormCertificate\DateMessages\DateMessagesRecordAutosave;
use FreeFormCertificate\Recruitment\RecruitmentRecordAutosave;
use FreeFormCertificate\Reregistration\ReregistrationRecordAutosave;
use FreeFormCertificate\UrlShortener\UrlShortenerRecordAutosave;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;
use FreeFormCertificate\UrlShortener\UrlShortenerService;

/**
 * The entities each module registers with the record autosave (#1757):
 * which fields each one allows, and what its save callback writes.
 *
 * @covers \FreeFormCertificate\Audience\AudienceRecordAutosave
 * @covers \FreeFormCertificate\Reregistration\ReregistrationRecordAutosave
 * @covers \FreeFormCertificate\Recruitment\RecruitmentRecordAutosave
 * @covers \FreeFormCertificate\DateMessages\DateMessagesRecordAutosave
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerRecordAutosave
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecordAutosaveEntitiesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, array<string, mixed>> */
	private array $registered = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

		$registered = &$this->registered;
		Mockery::mock( 'alias:' . RecordAutosave::class )
			->shouldReceive( 'register' )
			->andReturnUsing(
				static function ( string $entity, array $def ) use ( &$registered ): void {
					$registered[ $entity ] = $def;
				}
			);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Audience
	// ------------------------------------------------------------------

	public function test_the_calendar_and_audience_register_their_toggles(): void {
		AudienceRecordAutosave::register();

		$schedule = $this->registered['audience_schedule'];
		$this->assertSame( 'ffc_manage_audiences', $schedule['cap'] );
		$this->assertSame(
			array_merge( AudienceRecordAutosave::SCHEDULE_FLAGS, array( 'status', 'visibility' ) ),
			array_keys( $schedule['fields'] )
		);
		$this->assertSame( array( 1 => 'public', 0 => 'private' ), $schedule['fields']['visibility']['map'] );
		$this->assertSame( array( 'status', 'allow_self_join', 'self_join_scope', 'members_only', 'require_membership' ), array_keys( $this->registered['audience']['fields'] ) );
		$this->assertSame(
			array(
				'type'    => 'enum',
				'options' => array( 'off', 'self', 'all', 'descendants' ),
			),
			$this->registered['audience']['fields']['self_join_scope']
		);
	}

	public function test_the_booking_registers_its_attendance_and_post_event_fields(): void {
		AudienceRecordAutosave::register();

		$booking = $this->registered['audience_booking'];
		$this->assertSame( 'ffc_manage_audiences', $booking['cap'] );
		$this->assertSame(
			array( 'attendance_mode', 'attendance_min_percent', 'post_event_custom', 'post_event_form_id', 'post_event_window_days', 'post_event_attendees_only' ),
			array_keys( $booking['fields'] )
		);
		$this->assertSame( 1, $booking['fields']['attendance_min_percent']['min'] );
		$this->assertSame( 90, $booking['fields']['post_event_window_days']['max'] );
	}

	/**
	 * The booking reader and writer, the booking it answers, and the
	 * calendar the booking follows.
	 *
	 * @param array<string, mixed> $booking Booking columns.
	 * @return \Mockery\MockInterface The writer.
	 */
	private function booking_world( array $booking ) {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' )
			->shouldReceive( 'get_by_id' )->andReturn( (object) array_merge( array( 'id' => 5, 'schedule_id' => 3 ), $booking ) );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' )
			->shouldReceive( 'get_by_id' )->andReturn(
				(object) array(
					'post_event_form_id'        => '40',
					'post_event_window_days'    => '10',
					'post_event_attendees_only' => '1',
				)
			);
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				if ( 40 !== (int) $id && 41 !== (int) $id ) {
					return null;
				}
				$post              = new \WP_Post();
				$post->post_type   = 'ffc_form';
				$post->post_status = 'publish';
				return $post;
			}
		);
		return Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingWriter' );
	}

	public function test_choosing_share_of_days_keeps_the_stored_share_or_starts_from_the_default(): void {
		$writer = $this->booking_world( array( 'attendance_mode' => null, 'attendance_min_percent' => null ) );
		$writer->shouldReceive( 'set_attendance_rule' )->once()->with( 5, 'total', 75 )->andReturn( true );

		$this->assertTrue( AudienceRecordAutosave::save_booking( 5, 'attendance_mode', 'total' ) );
	}

	public function test_a_share_typed_saves_under_share_of_days(): void {
		$writer = $this->booking_world( array( 'attendance_mode' => 'total', 'attendance_min_percent' => '75' ) );
		$writer->shouldReceive( 'set_attendance_rule' )->once()->with( 5, 'total', 50 )->andReturn( true );

		$this->assertTrue( AudienceRecordAutosave::save_booking( 5, 'attendance_min_percent', 50 ) );
	}

	public function test_switching_to_own_settings_starts_from_the_calendar_s(): void {
		$writer = $this->booking_world( array( 'post_event_custom' => '0' ) );
		$writer->shouldReceive( 'set_post_event_form' )->once()->with( 5, true, 40, 10, true )->andReturn( true );

		$this->assertTrue( AudienceRecordAutosave::save_booking( 5, 'post_event_custom', 1 ) );
	}

	public function test_one_own_setting_changes_and_the_others_keep_their_value(): void {
		$writer = $this->booking_world(
			array(
				'post_event_custom'         => '1',
				'post_event_form_id'        => '40',
				'post_event_window_days'    => '7',
				'post_event_attendees_only' => '0',
			)
		);
		$writer->shouldReceive( 'set_post_event_form' )->once()->with( 5, true, 41, 7, false )->andReturn( true );

		$this->assertTrue( AudienceRecordAutosave::save_booking( 5, 'post_event_form_id', 41 ) );
	}

	public function test_a_form_that_is_not_a_published_form_is_refused_and_nothing_written(): void {
		$writer = $this->booking_world( array( 'post_event_custom' => '1' ) );
		$writer->shouldNotReceive( 'set_post_event_form' );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_booking( 5, 'post_event_form_id', 99 ) );
	}

	public function test_a_failed_booking_write_is_an_error(): void {
		$writer = $this->booking_world( array() );
		$writer->shouldReceive( 'set_attendance_rule' )->andReturn( false );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_booking( 5, 'attendance_mode', 'single' ) );
	}

	public function test_a_calendar_field_is_a_one_column_update(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' )
			->shouldReceive( 'update' )->once()->with( 4, array( 'visibility' => 'public' ) )->andReturn( true );

		$this->assertTrue( AudienceRecordAutosave::save_schedule( 4, 'visibility', 'public' ) );
	}

	public function test_a_failed_calendar_write_is_an_error(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' )
			->shouldReceive( 'update' )->andReturn( false );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_schedule( 4, 'include_ics', 1 ) );
	}

	public function test_a_parent_s_own_self_join_no_longer_cascades(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'id' => 9, 'parent_id' => null ) );
		$writer = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$writer->shouldReceive( 'update' )->once()->with( 9, array( 'allow_self_join' => 1 ) )->andReturn( true );
		$writer->shouldNotReceive( 'cascade_self_join' );

		$this->assertTrue( AudienceRecordAutosave::save_audience( 9, 'allow_self_join', 1 ) );
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function scope_choices(): iterable {
		foreach ( array( 'off', 'self', 'all', 'descendants' ) as $scope ) {
			yield $scope => array( $scope );
		}
	}

	/**
	 * The four-option choice (#1901) goes through the writer, which sets the
	 * audience's own flag and every descendant's.
	 *
	 * @dataProvider scope_choices
	 *
	 * @param string $scope The chosen option.
	 */
	public function test_the_scope_choice_is_applied_through_the_writer( string $scope ): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'id' => 9, 'parent_id' => null ) );
		$writer = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$writer->shouldNotReceive( 'update' );
		$writer->shouldReceive( 'apply_self_join_scope' )->once()->with( 9, $scope )->andReturn( true );

		$this->assertTrue( AudienceRecordAutosave::save_audience( 9, 'self_join_scope', $scope ) );
	}

	public function test_a_failed_scope_write_is_an_error(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'id' => 9, 'parent_id' => null ) );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' )
			->shouldReceive( 'apply_self_join_scope' )->once()->andReturn( false );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_audience( 9, 'self_join_scope', 'all' ) );
	}

	public function test_the_scope_choice_on_a_child_audience_is_refused(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'id' => 9, 'parent_id' => 3 ) );
		$writer = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$writer->shouldNotReceive( 'apply_self_join_scope' );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_audience( 9, 'self_join_scope', 'all' ) );
	}

	public function test_self_join_on_a_child_audience_is_refused(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'id' => 9, 'parent_id' => 3 ) );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' )->shouldNotReceive( 'update' );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_audience( 9, 'allow_self_join', 1 ) );
	}

	/**
	 * Turning members-only on keeps the stored keep-at-least-one value; the
	 * writer stores the pair (#1918).
	 */
	public function test_members_only_is_written_with_the_stored_keep_flag(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'id' => 9, 'parent_id' => null, 'members_only' => '0', 'require_membership' => '1' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' )
			->shouldReceive( 'set_tree_access' )->once()->with( 9, true, true )->andReturn( true );

		$this->assertTrue( AudienceRecordAutosave::save_audience( 9, 'members_only', 1 ) );
	}

	public function test_turning_members_only_off_passes_through_the_writer(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'id' => 9, 'parent_id' => null, 'members_only' => '1', 'require_membership' => '1' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' )
			->shouldReceive( 'set_tree_access' )->once()->with( 9, false, true )->andReturn( true );

		$this->assertTrue( AudienceRecordAutosave::save_audience( 9, 'members_only', 0 ) );
	}

	public function test_keep_one_group_saves_under_members_only(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'id' => 9, 'parent_id' => null, 'members_only' => '1', 'require_membership' => '0' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' )
			->shouldReceive( 'set_tree_access' )->once()->with( 9, true, true )->andReturn( true );

		$this->assertTrue( AudienceRecordAutosave::save_audience( 9, 'require_membership', 1 ) );
	}

	public function test_keep_one_group_without_members_only_is_refused(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'id' => 9, 'parent_id' => null, 'members_only' => '0', 'require_membership' => '0' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' )->shouldNotReceive( 'set_tree_access' );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_audience( 9, 'require_membership', 1 ) );
	}

	public function test_tree_access_on_a_child_audience_is_refused(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'id' => 9, 'parent_id' => 3 ) );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' )->shouldNotReceive( 'set_tree_access' );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_audience( 9, 'members_only', 1 ) );
	}

	public function test_an_audience_status_does_not_cascade(): void {
		$writer = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$writer->shouldReceive( 'update' )->once()->with( 9, array( 'status' => 'inactive' ) )->andReturn( true );
		$writer->shouldNotReceive( 'cascade_self_join' );

		$this->assertTrue( AudienceRecordAutosave::save_audience( 9, 'status', 'inactive' ) );
	}

	// ------------------------------------------------------------------
	// Reregistration
	// ------------------------------------------------------------------

	public function test_the_campaign_registers_its_flags_and_never_its_status(): void {
		ReregistrationRecordAutosave::register();

		$fields = $this->registered['reregistration']['fields'];
		$this->assertSame( ReregistrationRecordAutosave::FLAGS, array_keys( $fields ) );
		$this->assertArrayNotHasKey( 'status', $fields, 'Moving a campaign to active reopens and seeds submissions; that stays on Save.' );
	}

	public function test_a_campaign_flag_is_a_one_column_update(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' )
			->shouldReceive( 'update' )->once()->with( 5, array( 'auto_approve' => 0 ) )->andReturn( true );

		$this->assertTrue( ReregistrationRecordAutosave::save( 5, 'auto_approve', 0 ) );
	}

	// ------------------------------------------------------------------
	// Recruitment
	// ------------------------------------------------------------------

	public function test_the_notice_registers_every_column_but_the_fixed_ones(): void {
		RecruitmentRecordAutosave::register();

		$fields = array_keys( $this->registered['recruitment_notice']['fields'] );
		$this->assertContains( 'preview_reason', $fields );
		$this->assertContains( 'cpf_masked', $fields );
		$this->assertNotContains( 'rank', $fields );
		$this->assertNotContains( 'name', $fields );
		$this->assertSame( 'list', $this->registered['recruitment_reason']['fields']['applies_to']['type'] );
	}

	public function test_a_notice_column_merges_into_the_stored_config(): void {
		// The real reader (its default config is a constant an alias mock
		// would drop), answered from the object cache.
		Functions\when( 'wp_cache_get' )->justReturn( (object) array( 'public_columns_config' => '{"rank":false,"score":true}' ) );
		$written = null;
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeWriter' )
			->shouldReceive( 'update' )->once()->andReturnUsing(
				static function ( $id, $data ) use ( &$written ) {
					$written = json_decode( $data['public_columns_config'], true );
					return true;
				}
			);

		$this->assertTrue( RecruitmentRecordAutosave::save_notice( 3, 'cpf_masked', 1 ) );
		$this->assertTrue( $written['cpf_masked'], 'The switched column.' );
		$this->assertTrue( $written['score'], 'A stored column the request did not touch.' );
		$this->assertTrue( $written['rank'], 'A fixed column is forced back on.' );
		$this->assertTrue( $written['adjutancy'], 'A default the stored JSON did not carry.' );
	}

	public function test_a_reason_saves_the_ticked_set(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonWriter' )
			->shouldReceive( 'update' )->once()->with( 2, array( 'applies_to' => array( 'denied' ) ) )->andReturn( true );

		$this->assertTrue( RecruitmentRecordAutosave::save_reason( 2, 'applies_to', array( 'denied' ) ) );
	}

	// ------------------------------------------------------------------
	// Date Messages
	// ------------------------------------------------------------------

	/**
	 * A stored rule, as the reader returns it.
	 *
	 * @return \FreeFormCertificate\DateMessages\Rule
	 */
	private function rule() {
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\DateSources' )->shouldReceive( 'has' )->andReturn( true );

		$rule = \FreeFormCertificate\DateMessages\Rule::from_array(
			array(
				'id'         => 6,
				'name'       => 'Birthdays',
				'subject'    => 'Happy birthday',
				'body'       => '<p>Hi</p>',
				'source'     => 'birthday',
				'is_active'  => '1',
				'appearance' => array( 'position' => 'left', 'text_width' => 60 ),
			)
		);
		$this->assertInstanceOf( \FreeFormCertificate\DateMessages\Rule::class, $rule );
		return $rule;
	}

	public function test_the_rule_registers_its_flags_and_appearance(): void {
		DateMessagesRecordAutosave::register();

		$fields = $this->registered['date_message_rule']['fields'];
		$this->assertSame( array( 'send_to_user', 'is_active', 'digest_enabled', 'appearance_position', 'appearance_text_width' ), array_keys( $fields ) );
		$this->assertSame( array( '50', '60', '70' ), $fields['appearance_text_width']['options'] );
	}

	public function test_a_rule_flag_rebuilds_the_rule_and_saves_it(): void {
		$rule = $this->rule();
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleReader' )->shouldReceive( 'get_by_id' )->with( 6 )->andReturn( $rule );
		$saved = null;
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleWriter' )
			->shouldReceive( 'save' )->once()->andReturnUsing(
				static function ( $r ) use ( &$saved ) {
					$saved = $r;
					return 6;
				}
			);

		$this->assertTrue( DateMessagesRecordAutosave::save( 6, 'is_active', 0 ) );
		$this->assertFalse( $saved->is_active );
		$this->assertSame( 'Birthdays', $saved->name, 'Every other field survives the rebuild.' );
		$this->assertSame( 'left', $saved->appearance->position );
	}

	public function test_a_rule_appearance_field_changes_only_that_part(): void {
		$rule = $this->rule();
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleReader' )->shouldReceive( 'get_by_id' )->andReturn( $rule );
		$saved = null;
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleWriter' )
			->shouldReceive( 'save' )->once()->andReturnUsing(
				static function ( $r ) use ( &$saved ) {
					$saved = $r;
					return 6;
				}
			);

		$this->assertTrue( DateMessagesRecordAutosave::save( 6, 'appearance_text_width', '70' ) );
		$this->assertSame( 70, $saved->appearance->text_width );
		$this->assertSame( 'left', $saved->appearance->position );
		$this->assertTrue( $saved->is_active );
	}

	public function test_a_missing_rule_is_an_error(): void {
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleReader' )->shouldReceive( 'get_by_id' )->andReturn( null );
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleWriter' )->shouldNotReceive( 'save' );

		$this->assertInstanceOf( \WP_Error::class, DateMessagesRecordAutosave::save( 6, 'is_active', 1 ) );
	}

	public function test_a_failed_rule_write_is_an_error(): void {
		$rule = $this->rule();
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleReader' )->shouldReceive( 'get_by_id' )->andReturn( $rule );
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleWriter' )->shouldReceive( 'save' )->andReturn( false );

		$this->assertInstanceOf( \WP_Error::class, DateMessagesRecordAutosave::save( 6, 'send_to_user', 0 ) );
	}

	/**
	 * An inactive announcement with a future send time.
	 *
	 * @param bool $active Whether it is active.
	 * @return \FreeFormCertificate\DateMessages\Rule
	 */
	private function announcement( bool $active ) {
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );

		$rule = \FreeFormCertificate\DateMessages\Rule::from_array(
			array(
				'id'        => 6,
				'name'      => 'Closing',
				'kind'      => 'announcement',
				'subject'   => 'Closed',
				'body'      => '<p>x</p>',
				'send_at'   => time() + 3600,
				'is_active' => $active ? '1' : '0',
			)
		);
		$this->assertInstanceOf( \FreeFormCertificate\DateMessages\Rule::class, $rule );
		return $rule;
	}

	public function test_switching_an_announcement_on_schedules_its_send(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_send_date_messages' === $cap );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$before = $this->announcement( false );
		$after  = $this->announcement( true );
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleReader' )->shouldReceive( 'get_by_id' )->with( 6 )->andReturn( $before, $after );
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleWriter' )->shouldReceive( 'save' )->once()->andReturn( 6 );
		Functions\expect( 'wp_clear_scheduled_hook' )->once();
		Functions\expect( 'wp_schedule_single_event' )->once()->with( $after->send_at, \FreeFormCertificate\DateMessages\Runner::ANNOUNCE_HOOK, array( 6 ) )->andReturn( true );

		$this->assertTrue( DateMessagesRecordAutosave::save( 6, 'is_active', 1 ) );
	}

	public function test_switching_an_announcement_on_needs_the_right_to_send(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleReader' )->shouldReceive( 'get_by_id' )->andReturn( $this->announcement( false ) );
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleWriter' )->shouldNotReceive( 'save' );
		Functions\expect( 'wp_schedule_single_event' )->never();

		$result = DateMessagesRecordAutosave::save( 6, 'is_active', 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( \FreeFormCertificate\DateMessages\DateMessagesAdminPage::send_refusal(), $result->get_error_message() );
	}

	public function test_switching_an_announcement_off_clears_its_send(): void {
		$before = $this->announcement( true );
		$after  = $this->announcement( false );
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleReader' )->shouldReceive( 'get_by_id' )->andReturn( $before, $after );
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleWriter' )->shouldReceive( 'save' )->once()->andReturn( 6 );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( \FreeFormCertificate\DateMessages\Runner::ANNOUNCE_HOOK, array( 6 ) );
		Functions\expect( 'wp_schedule_single_event' )->never();

		$this->assertTrue( DateMessagesRecordAutosave::save( 6, 'is_active', 0 ) );
	}

	// ------------------------------------------------------------------
	// Short URL
	// ------------------------------------------------------------------

	/**
	 * A service whose repository holds one row.
	 *
	 * @param array<string, mixed>|null $row The stored row.
	 */
	private function short_url_service( ?array $row ): UrlShortenerService {
		$repo = Mockery::mock( UrlShortenerRepository::class );
		$repo->shouldReceive( 'findById' )->andReturn( $row );

		$service = Mockery::mock( UrlShortenerService::class );
		$service->shouldReceive( 'get_repository' )->andReturn( $repo );

		return $service;
	}

	public function test_the_short_url_registers_its_status_as_a_two_state_map(): void {
		UrlShortenerRecordAutosave::register( $this->short_url_service( null ) );

		$def = $this->registered['short_url'];
		$this->assertSame( 'ffc_manage_url_shortener', $def['cap'] );
		$this->assertSame(
			array(
				'status' => array(
					'type' => 'map',
					'map'  => array(
						1 => 'active',
						0 => 'disabled',
					),
				),
			),
			$def['fields']
		);
	}

	public function test_a_trashed_or_missing_short_url_has_no_toggle_to_save(): void {
		UrlShortenerRecordAutosave::register( $this->short_url_service( array( 'status' => 'trashed' ) ) );
		$this->assertFalse( ( $this->registered['short_url']['exists'] )( 3 ) );

		UrlShortenerRecordAutosave::register( $this->short_url_service( null ) );
		$this->assertFalse( ( $this->registered['short_url']['exists'] )( 3 ) );

		UrlShortenerRecordAutosave::register( $this->short_url_service( array( 'status' => 'disabled' ) ) );
		$this->assertTrue( ( $this->registered['short_url']['exists'] )( 3 ) );
	}

	public function test_the_short_url_toggle_sets_the_status_it_means(): void {
		$service = $this->short_url_service( array( 'status' => 'active' ) );
		$service->shouldReceive( 'set_active' )->once()->with( 5, false )->andReturn( true );
		$service->shouldReceive( 'set_active' )->once()->with( 5, true )->andReturn( false );
		UrlShortenerRecordAutosave::register( $service );
		$save = $this->registered['short_url']['save'];

		$this->assertTrue( $save( 5, 'status', 'disabled' ) );
		$this->assertInstanceOf( \WP_Error::class, $save( 5, 'status', 'active' ) );
	}
}
