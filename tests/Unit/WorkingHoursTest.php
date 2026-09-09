<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\WorkingHours;

/**
 * Tests for the shared working-hours row sanitizer (#1128).
 *
 * The defect this covers is not "an invalid row is stored" in the abstract —
 * it is that `isset( '' )` is true, so the old guard
 * `isset( $entry['day'], $entry['entry1'], $entry['exit2'] )` accepted a row
 * whose times were empty strings. Every "missing" case below therefore uses
 * `''`, not an absent key: an absent key was already handled, and testing that
 * would pass against the old code too.
 *
 * @covers \FreeFormCertificate\Core\WorkingHours
 */
class WorkingHoursTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov attribution: preload the class-under-test (CLAUDE.md gotcha).
		class_exists( '\FreeFormCertificate\Core\WorkingHours' );

		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( strip_tags( $value ) ) : '';
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $value ) {
				return json_encode( $value );
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string $raw Raw JSON.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( string $raw ): array {
		$decoded = json_decode( WorkingHours::sanitize( $raw )['json'], true );
		return is_array( $decoded ) ? $decoded : array();
	}

	// ------------------------------------------------------------------
	// The complete row
	// ------------------------------------------------------------------

	public function test_a_row_with_both_required_times_is_kept(): void {
		$rows = $this->rows( '[{"day":1,"entry1":"08:00","exit1":"","entry2":"","exit2":"17:00"}]' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 1, $rows[0]['day'] );
		$this->assertSame( '08:00', $rows[0]['entry1'] );
		$this->assertSame( '17:00', $rows[0]['exit2'] );
	}

	public function test_the_optional_middle_shift_is_preserved_when_supplied(): void {
		$rows = $this->rows( '[{"day":2,"entry1":"08:00","exit1":"12:00","entry2":"13:00","exit2":"17:00"}]' );

		$this->assertSame( '12:00', $rows[0]['exit1'] );
		$this->assertSame( '13:00', $rows[0]['entry2'] );
	}

	// ------------------------------------------------------------------
	// The empty row — dropped in silence
	// ------------------------------------------------------------------

	public function test_a_row_with_no_times_at_all_is_dropped(): void {
		$result = WorkingHours::sanitize( '[{"day":3,"entry1":"","exit1":"","entry2":"","exit2":""}]' );

		$this->assertSame( '[]', $result['json'] );
	}

	public function test_a_row_with_no_times_is_not_reported_as_incomplete(): void {
		$result = WorkingHours::sanitize( '[{"day":3,"entry1":"","exit1":"","entry2":"","exit2":""}]' );

		$this->assertSame(
			array(),
			$result['incomplete'],
			'"I do not work this day" is an intention, not a mistake to announce.'
		);
	}

	public function test_whitespace_only_times_count_as_empty(): void {
		$result = WorkingHours::sanitize( '[{"day":3,"entry1":"  ","exit1":"","entry2":"","exit2":"   "}]' );

		$this->assertSame( '[]', $result['json'] );
		$this->assertSame( array(), $result['incomplete'] );
	}

	// ------------------------------------------------------------------
	// The partial row — dropped AND reported
	// ------------------------------------------------------------------

	public function test_a_row_missing_the_first_entry_is_dropped(): void {
		$result = WorkingHours::sanitize( '[{"day":4,"entry1":"","exit1":"","entry2":"","exit2":"17:00"}]' );

		$this->assertSame( '[]', $result['json'] );
	}

	public function test_a_row_missing_the_first_entry_is_reported_with_the_day(): void {
		$result = WorkingHours::sanitize( '[{"day":4,"entry1":"","exit1":"","entry2":"","exit2":"17:00"}]' );

		$this->assertCount( 1, $result['incomplete'] );
		$this->assertSame( 4, $result['incomplete'][0]['day'] );
		$this->assertSame( array( 'entry1' ), $result['incomplete'][0]['missing'] );
	}

	public function test_a_row_missing_the_last_exit_is_reported(): void {
		$result = WorkingHours::sanitize( '[{"day":5,"entry1":"08:00","exit1":"","entry2":"","exit2":""}]' );

		$this->assertSame( '[]', $result['json'] );
		$this->assertSame( array( 'exit2' ), $result['incomplete'][0]['missing'] );
	}

	public function test_a_row_carrying_only_the_optional_middle_reports_both_required_keys(): void {
		$result = WorkingHours::sanitize( '[{"day":6,"entry1":"","exit1":"12:00","entry2":"","exit2":""}]' );

		$this->assertSame( '[]', $result['json'] );
		$this->assertSame( array( 'entry1', 'exit2' ), $result['incomplete'][0]['missing'] );
	}

	/**
	 * The whole point of dropping the row instead of reverting the field.
	 */
	public function test_a_partial_row_does_not_take_the_valid_rows_with_it(): void {
		$result = WorkingHours::sanitize(
			'[{"day":1,"entry1":"08:00","exit1":"","entry2":"","exit2":"17:00"},'
			. '{"day":2,"entry1":"","exit1":"","entry2":"","exit2":"17:00"},'
			. '{"day":3,"entry1":"09:00","exit1":"","entry2":"","exit2":"18:00"}]'
		);

		$rows = json_decode( $result['json'], true );

		$this->assertCount( 2, $rows, 'The two complete rows survive the one incomplete row.' );
		$this->assertSame( 1, $rows[0]['day'] );
		$this->assertSame( 3, $rows[1]['day'] );
		$this->assertCount( 1, $result['incomplete'] );
		$this->assertSame( 2, $result['incomplete'][0]['day'] );
	}

	// ------------------------------------------------------------------
	// Malformed input
	// ------------------------------------------------------------------

	public function test_malformed_json_yields_an_empty_array(): void {
		$result = WorkingHours::sanitize( 'not json at all' );

		$this->assertSame( '[]', $result['json'] );
		$this->assertSame( array(), $result['incomplete'] );
	}

	public function test_a_json_scalar_yields_an_empty_array(): void {
		$this->assertSame( '[]', WorkingHours::sanitize( '"08:00"' )['json'] );
	}

	public function test_an_entry_without_a_day_is_skipped(): void {
		$this->assertSame( '[]', WorkingHours::sanitize( '[{"entry1":"08:00","exit2":"17:00"}]' )['json'] );
	}

	public function test_a_non_scalar_time_is_read_as_empty_rather_than_cast(): void {
		$result = WorkingHours::sanitize( '[{"day":1,"entry1":["08:00"],"exit1":"","entry2":"","exit2":"17:00"}]' );

		$this->assertSame( '[]', $result['json'], 'An array time is not a time; casting it would store "Array".' );
		$this->assertSame( array( 'entry1' ), $result['incomplete'][0]['missing'] );
	}

	// ------------------------------------------------------------------
	// The guard's own inputs
	// ------------------------------------------------------------------

	public function test_the_required_and_optional_key_sets_are_disjoint_and_complete(): void {
		$this->assertSame( array( 'entry1', 'exit2' ), WorkingHours::REQUIRED_KEYS );
		$this->assertSame( array( 'exit1', 'entry2' ), WorkingHours::OPTIONAL_KEYS );
		$this->assertSame(
			array(),
			array_intersect( WorkingHours::REQUIRED_KEYS, WorkingHours::OPTIONAL_KEYS ),
			'A key that is both required and optional would make the rule unreadable.'
		);
	}

	public function test_key_label_covers_every_row_key(): void {
		foreach ( array_merge( WorkingHours::REQUIRED_KEYS, WorkingHours::OPTIONAL_KEYS ) as $key ) {
			$this->assertNotSame( $key, WorkingHours::key_label( $key ), "No label for {$key}" );
		}
	}

	public function test_day_label_falls_back_to_the_index_without_wp_locale(): void {
		$this->assertSame( '3', WorkingHours::day_label( 3 ) );
		$this->assertSame( '9', WorkingHours::day_label( 9 ) );
	}
}
