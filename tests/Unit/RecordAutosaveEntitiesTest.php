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

/**
 * The entities each module registers with the record autosave (#1757):
 * which fields each one allows, and what its save callback writes.
 *
 * @covers \FreeFormCertificate\Audience\AudienceRecordAutosave
 * @covers \FreeFormCertificate\Reregistration\ReregistrationRecordAutosave
 * @covers \FreeFormCertificate\Recruitment\RecruitmentRecordAutosave
 * @covers \FreeFormCertificate\DateMessages\DateMessagesRecordAutosave
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
		$this->assertSame( array( 'status', 'allow_self_join' ), array_keys( $this->registered['audience']['fields'] ) );
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

	public function test_self_join_on_a_parent_cascades_to_its_children(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'id' => 9, 'parent_id' => null ) );
		$writer = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$writer->shouldReceive( 'update' )->once()->with( 9, array( 'allow_self_join' => 1 ) )->andReturn( true );
		$writer->shouldReceive( 'cascade_self_join' )->once()->with( 9, 1 );

		$this->assertTrue( AudienceRecordAutosave::save_audience( 9, 'allow_self_join', 1 ) );
	}

	public function test_self_join_on_a_child_audience_is_refused(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'id' => 9, 'parent_id' => 3 ) );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' )->shouldNotReceive( 'update' );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_audience( 9, 'allow_self_join', 1 ) );
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
}
