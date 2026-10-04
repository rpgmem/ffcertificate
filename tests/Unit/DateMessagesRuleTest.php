<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\Rule;
use PHPUnit\Framework\TestCase;

/**
 * A date-message rule is validated in one place (#1538).
 *
 * @covers \FreeFormCertificate\DateMessages\Rule
 */
class DateMessagesRuleTest extends TestCase {

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
	private function valid( array $over = array() ): array {
		return array_merge(
			array(
				'id'              => '4',
				'name'            => 'Birthday, on the day',
				'source'          => 'birthday',
				'offset_days'     => '-7',
				'audience_id'     => '0',
				'subject'         => 'Happy birthday',
				'body'            => '<p>Hi</p>',
				'send_to_user'    => '1',
				'digest_enabled'  => '0',
				'digest_mode'     => 'detailed',
				'digest_user_ids' => '[3, "5", 3, 0, -1, "x"]',
				'is_active'       => '1',
			),
			$over
		);
	}

	public function test_a_stored_row_builds_a_typed_rule(): void {
		$rule = Rule::from_array( $this->valid() );

		$this->assertInstanceOf( Rule::class, $rule );
		$this->assertSame( 4, $rule->id );
		$this->assertSame( -7, $rule->offset_days );
		$this->assertNull( $rule->audience_id, 'Audience 0 means everyone.' );
		$this->assertTrue( $rule->send_to_user );
		$this->assertFalse( $rule->digest_enabled );
		$this->assertSame( array( 3, 5 ), $rule->digest_user_ids, 'Only positive, unique ids survive.' );
	}

	public function test_to_columns_round_trips(): void {
		$rule    = Rule::from_array( $this->valid( array( 'audience_id' => '12' ) ) );
		$columns = $rule->to_columns();

		$this->assertSame( 12, $columns['audience_id'] );
		$this->assertSame( '[3,5]', $columns['digest_user_ids'] );
		$this->assertSame( 1, $columns['is_active'] );

		$again = Rule::from_array( $columns + array( 'id' => 4 ) );
		$this->assertEquals( $rule, $again );
	}

	/**
	 * @dataProvider invalid
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @param string               $code Expected error code.
	 */
	public function test_invalid_input_is_refused_with_a_reason( array $over, string $code ): void {
		$result = Rule::from_array( $this->valid( $over ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public function invalid(): array {
		return array(
			'no name'            => array( array( 'name' => '  ' ), 'ffc_rule_name' ),
			'no subject'         => array( array( 'subject' => '' ), 'ffc_rule_message' ),
			'no body'            => array( array( 'body' => '   ' ), 'ffc_rule_message' ),
			'unknown source'     => array( array( 'source' => 'admission' ), 'ffc_rule_source' ),
			'fractional offset'  => array( array( 'offset_days' => '1.5' ), 'ffc_rule_offset' ),
			'offset out of range' => array( array( 'offset_days' => '-61' ), 'ffc_rule_offset' ),
			'offset not a number' => array( array( 'offset_days' => 'soon' ), 'ffc_rule_offset' ),
			'unknown digest mode' => array( array( 'digest_mode' => 'full' ), 'ffc_rule_digest_mode' ),
		);
	}

	public function test_the_offset_limits_are_inclusive(): void {
		$this->assertInstanceOf( Rule::class, Rule::from_array( $this->valid( array( 'offset_days' => Rule::MAX_OFFSET_DAYS ) ) ) );
		$this->assertInstanceOf( Rule::class, Rule::from_array( $this->valid( array( 'offset_days' => -Rule::MAX_OFFSET_DAYS ) ) ) );
	}

	public function test_flags_read_form_and_stored_spellings(): void {
		$rule = Rule::from_array( $this->valid( array( 'send_to_user' => 'on', 'digest_enabled' => 'yes', 'is_active' => 0 ) ) );

		$this->assertTrue( $rule->send_to_user );
		$this->assertTrue( $rule->digest_enabled );
		$this->assertFalse( $rule->is_active );
	}
}
