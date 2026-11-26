<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\AttendanceTargetRegistry;
use FreeFormCertificate\Core\TerminalTargetKey;
use FreeFormCertificate\Core\TerminalVerdict;
use PHPUnit\Framework\TestCase;

/**
 * How a terminal names what it checks in (#2890): a booking stays the bare id
 * every stored queue already holds, and any other target is `type:id`.
 *
 * @covers \FreeFormCertificate\Core\TerminalTargetKey
 * @covers \FreeFormCertificate\Core\TerminalVerdict
 * @covers \FreeFormCertificate\Core\AttendanceTargetRegistry::terminal
 */
class TerminalTargetKeyTest extends TestCase {

	protected function tearDown(): void {
		AttendanceTargetRegistry::reset();
		parent::tearDown();
	}

	public function test_a_booking_is_its_bare_id_and_any_other_target_is_type_and_id(): void {
		$this->assertSame( 11, TerminalTargetKey::of( 'audience_booking', 11 ) );
		$this->assertSame( 'certificate_form:12', TerminalTargetKey::of( 'certificate_form', 12 ) );
	}

	public function test_a_key_reads_back_as_its_type_and_id(): void {
		$this->assertSame( array( 'type' => 'audience_booking', 'id' => 11 ), TerminalTargetKey::parse( 11 ) );
		$this->assertSame( array( 'type' => 'audience_booking', 'id' => 11 ), TerminalTargetKey::parse( '11' ), 'a JSON object key arrives as a string' );
		$this->assertSame( array( 'type' => 'certificate_form', 'id' => 12 ), TerminalTargetKey::parse( 'certificate_form:12' ) );
	}

	public function test_anything_that_is_not_a_key_is_refused(): void {
		foreach ( array( 0, -1, '0', '-1', '', 'x', 'certificate_form:0', 'certificate_form:', ':5', 'Certificate:5', 'a b:5', '1.5', 1.5, null, true, array( 1 ) ) as $value ) {
			$this->assertNull( TerminalTargetKey::parse( $value ), var_export( $value, true ) );
		}
	}

	public function test_a_list_is_cleaned_to_unique_keys_in_their_one_spelling(): void {
		$this->assertSame(
			array( 11, 'certificate_form:12' ),
			TerminalTargetKey::clean( array( '11', 11, 'certificate_form:12', 'x', -3, 'certificate_form:12' ) )
		);
		$this->assertSame( 11, TerminalTargetKey::normalize( '11' ) );
		$this->assertNull( TerminalTargetKey::normalize( 'x' ) );
	}

	public function test_the_registry_answers_a_key_only_with_a_target_a_terminal_can_read(): void {
		$plain = new class() implements \FreeFormCertificate\Core\AttendanceTargetInterface {
			public function type(): string {
				return 'plain';
			}
			public function record( int $target_id, int $user_id, int $checked_at, string $method, ?int $marked_by ): string {
				return 'recorded';
			}
		};
		AttendanceTargetRegistry::register( 'plain', static fn() => $plain );

		$this->assertNull( AttendanceTargetRegistry::terminal( 'plain:3' ), 'a target with no terminal half is not offered to a terminal' );
		$this->assertNull( AttendanceTargetRegistry::terminal( 'missing:3' ) );
		$this->assertNull( AttendanceTargetRegistry::terminal( 'x' ) );
	}

	public function test_a_refusal_carries_no_name(): void {
		$this->assertSame(
			array(
				'status'  => 'refused',
				'code'    => 'ffc_x',
				'message' => 'Do this.',
				'name'    => '',
			),
			TerminalVerdict::refused( 'ffc_x', 'Do this.' )
		);
	}
}
