<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\CustomSlots;

/**
 * Tests for CustomSlots — the pure parsing/query helpers over the custom
 * scheduling blocks JSON (#941).
 *
 * @covers \FreeFormCertificate\SelfScheduling\CustomSlots
 */
class CustomSlotsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		class_exists( '\\FreeFormCertificate\\SelfScheduling\\CustomSlots' );
	}

	/** @return array<int, array<string, mixed>> */
	private function sample(): array {
		return array(
			array( 'date' => '2026-09-03', 'start' => '08:00', 'end' => '13:00', 'capacity' => 40, 'label' => 'Manhã' ),
			array( 'date' => '2026-09-03', 'start' => '14:00', 'end' => '18:00', 'capacity' => 40 ),
			array( 'date' => '2026-09-05', 'start' => '08:00', 'end' => '13:00', 'capacity' => 20 ),
		);
	}

	public function test_decode_accepts_json_string_and_array(): void {
		$json = wp_json_encode_shim( $this->sample() );

		$from_json  = CustomSlots::decode( $json );
		$from_array = CustomSlots::decode( $this->sample() );

		$this->assertCount( 3, $from_json );
		$this->assertSame( $from_json, $from_array );
		$this->assertSame( '2026-09-03', $from_json[0]['date'] );
		$this->assertSame( 40, $from_json[0]['capacity'] );
		$this->assertSame( 'Manhã', $from_json[0]['label'] );
		// Missing label defaults to ''.
		$this->assertSame( '', $from_json[1]['label'] );
	}

	public function test_decode_drops_malformed_and_clamps_capacity(): void {
		$blocks = CustomSlots::decode(
			array(
				array( 'date' => '2026-09-03', 'start' => '08:00', 'end' => '13:00', 'capacity' => 0 ),
				array( 'date' => '2026-09-03', 'start' => '', 'end' => '13:00' ), // no start → dropped
				array( 'nonsense' => true ),                                       // no date → dropped
				'not-an-array',                                                    // scalar → dropped
			)
		);

		$this->assertCount( 1, $blocks );
		$this->assertSame( 1, $blocks[0]['capacity'], 'capacity clamps to >= 1' );
	}

	public function test_decode_of_garbage_returns_empty(): void {
		$this->assertSame( array(), CustomSlots::decode( 'not json' ) );
		$this->assertSame( array(), CustomSlots::decode( null ) );
		$this->assertSame( array(), CustomSlots::decode( 42 ) );
	}

	public function test_for_date_filters_and_reindexes(): void {
		$sep3 = CustomSlots::for_date( $this->sample(), '2026-09-03' );
		$this->assertCount( 2, $sep3 );
		$this->assertSame( array( 0, 1 ), array_keys( $sep3 ) );

		$this->assertCount( 1, CustomSlots::for_date( $this->sample(), '2026-09-05' ) );
		$this->assertSame( array(), CustomSlots::for_date( $this->sample(), '2026-09-04' ) );
	}

	public function test_find_matches_on_date_and_start_at_hm_granularity(): void {
		// A booking start_time of 'H:i:s' still matches a stored 'H:i' block.
		$block = CustomSlots::find( $this->sample(), '2026-09-03', '14:00:00' );
		$this->assertNotNull( $block );
		$this->assertSame( '18:00', $block['end'] );
		$this->assertSame( 40, $block['capacity'] );

		$this->assertNull( CustomSlots::find( $this->sample(), '2026-09-03', '09:00' ) );
		$this->assertNull( CustomSlots::find( $this->sample(), '2026-09-04', '08:00' ) );
	}

	public function test_dates_returns_distinct_sorted(): void {
		$this->assertSame(
			array( '2026-09-03', '2026-09-05' ),
			CustomSlots::dates( $this->sample() )
		);
	}

	public function test_hm_strips_seconds(): void {
		$this->assertSame( '08:00', CustomSlots::hm( '08:00:00' ) );
		$this->assertSame( '08:00', CustomSlots::hm( '08:00' ) );
	}
}

/**
 * Minimal JSON encoder standing in for wp_json_encode in this pure-PHP test.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_shim( $value ): string {
	return (string) json_encode( $value );
}
