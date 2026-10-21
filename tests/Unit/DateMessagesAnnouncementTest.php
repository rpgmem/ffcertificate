<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\AnnouncementSource;
use FreeFormCertificate\DateMessages\DateSources;
use FreeFormCertificate\DateMessages\OptOutCategory;
use FreeFormCertificate\DateMessages\Rule;
use FreeFormCertificate\DateMessages\Unsubscribe;
use PHPUnit\Framework\TestCase;

/**
 * Announcement rules and opt-out categories (#1949, PR A).
 *
 * @covers \FreeFormCertificate\DateMessages\Rule
 * @covers \FreeFormCertificate\DateMessages\OptOutCategory
 * @covers \FreeFormCertificate\DateMessages\AnnouncementSource
 * @covers \FreeFormCertificate\DateMessages\DateSources
 */
class DateMessagesAnnouncementTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function announcement( array $over = array() ): array {
		return array_merge(
			array(
				'name'            => 'Holiday closing',
				'kind'            => 'announcement',
				'subject'         => 'The office closes on Friday',
				'body'            => '<p>Details inside.</p>',
				'send_at'         => '1900000000',
				'respect_opt_out' => '0',
				'is_active'       => '1',
			),
			$over
		);
	}

	public function test_an_announcement_reads_everyone_and_ignores_offset_and_source(): void {
		$rule = Rule::from_array( $this->announcement( array( 'source' => 'birthday', 'offset_days' => '-7' ) ) );

		$this->assertInstanceOf( Rule::class, $rule );
		$this->assertTrue( $rule->is_announcement() );
		$this->assertSame( 'announcement', $rule->source, 'The source is fixed, not chosen.' );
		$this->assertSame( 0, $rule->offset_days );
		$this->assertSame( 1900000000, $rule->send_at );
		$this->assertFalse( $rule->respect_opt_out, 'An announcement may send to people who opted out.' );
	}

	public function test_a_dated_rule_always_respects_the_opt_out(): void {
		$rule = Rule::from_array(
			array(
				'name'            => 'Birthday',
				'source'          => 'birthday',
				'subject'         => 'Happy birthday',
				'body'            => '<p>Hi</p>',
				'respect_opt_out' => '0',
				'send_at'         => '1900000000',
			)
		);

		$this->assertInstanceOf( Rule::class, $rule );
		$this->assertFalse( $rule->is_announcement() );
		$this->assertTrue( $rule->respect_opt_out, 'Forcing a dated message past an opt-out is not offered.' );
		$this->assertSame( 0, $rule->send_at, 'A dated rule has no send instant.' );
		$this->assertSame( OptOutCategory::PERSONAL, $rule->opt_out_category() );
	}

	public function test_an_announcement_is_checked_against_its_own_category(): void {
		$rule = Rule::from_array( $this->announcement() );

		$this->assertInstanceOf( Rule::class, $rule );
		$this->assertSame( OptOutCategory::ANNOUNCEMENT, $rule->opt_out_category() );
	}

	public function test_an_unknown_kind_is_refused(): void {
		$rule = Rule::from_array( $this->announcement( array( 'kind' => 'weekly' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $rule );
		$this->assertSame( 'ffc_rule_kind', $rule->get_error_code() );
	}

	public function test_the_announcement_source_is_not_a_choice_for_a_dated_rule(): void {
		$rule = Rule::from_array(
			array(
				'name'    => 'Sneaky',
				'source'  => 'announcement',
				'subject' => 'x',
				'body'    => 'y',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $rule, 'A dated rule cannot name the announcement source.' );
		$this->assertSame( 'ffc_rule_source', $rule->get_error_code() );
		$this->assertFalse( DateSources::has( 'announcement' ), 'The operator never sees it in the source list.' );
		$this->assertInstanceOf( AnnouncementSource::class, DateSources::for_rule( Rule::from_array( $this->announcement() ) ) );
	}

	public function test_a_negative_send_instant_is_read_as_not_scheduled(): void {
		$rule = Rule::from_array( $this->announcement( array( 'send_at' => '-5' ) ) );

		$this->assertInstanceOf( Rule::class, $rule );
		$this->assertSame( 0, $rule->send_at );
	}

	public function test_the_new_columns_round_trip_through_to_columns(): void {
		$rule    = Rule::from_array( $this->announcement() );
		$columns = $rule->to_columns();

		$this->assertSame( 'announcement', $columns['kind'] );
		$this->assertSame( 1900000000, $columns['send_at'] );
		$this->assertSame( 0, $columns['respect_opt_out'] );
	}

	public function test_each_category_has_its_own_preference_key(): void {
		$this->assertSame( array( 'personal', 'announcement' ), OptOutCategory::all() );
		$this->assertSame( 'notify_date_messages', OptOutCategory::key( OptOutCategory::PERSONAL ) );
		$this->assertSame( 'notify_date_announcements', OptOutCategory::key( OptOutCategory::ANNOUNCEMENT ) );
	}

	public function test_an_unknown_category_reads_as_personal(): void {
		$this->assertFalse( OptOutCategory::has( 'weekly' ) );
		$this->assertSame( 'notify_date_messages', OptOutCategory::key( 'weekly' ) );
	}

	public function test_the_personal_token_is_the_one_shipped_first(): void {
		// Links in mail already sent carry this token; changing its payload
		// would invalidate every one of them.
		Functions\when( 'wp_salt' )->justReturn( 'salt' );

		$this->assertSame(
			hash_hmac( 'sha256', 'unsubscribe|42', hash_hmac( 'sha256', 'ffc_date_messages_unsub_v1', 'salt' ) ),
			Unsubscribe::token( 42 )
		);
		$this->assertSame( Unsubscribe::token( 42 ), Unsubscribe::token( 42, OptOutCategory::PERSONAL ) );
	}

	public function test_a_token_is_bound_to_its_category(): void {
		Functions\when( 'wp_salt' )->justReturn( 'salt' );

		$announcement = Unsubscribe::token( 42, OptOutCategory::ANNOUNCEMENT );

		$this->assertNotSame( Unsubscribe::token( 42 ), $announcement, 'A personal token does not unsubscribe from announcements.' );
		$this->assertTrue( Unsubscribe::verify( 42, $announcement, OptOutCategory::ANNOUNCEMENT ) );
		$this->assertFalse( Unsubscribe::verify( 42, $announcement, OptOutCategory::PERSONAL ) );
	}
}
