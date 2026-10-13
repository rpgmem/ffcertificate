<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\QuizLeaveLevel;
use PHPUnit\Framework\TestCase;

/**
 * The window-leave level of a quiz (#1731, #1745).
 *
 * @covers \FreeFormCertificate\Core\QuizLeaveLevel
 */
class QuizLeaveLevelTest extends TestCase {

	public function test_a_stored_level_is_kept_and_anything_else_is_off(): void {
		$this->assertSame( QuizLeaveLevel::MODERATE, QuizLeaveLevel::sanitize( '1' ), 'The old toggle stored 1, which stays Moderate.' );
		$this->assertSame( QuizLeaveLevel::STRICT, QuizLeaveLevel::sanitize( 2 ) );
		$this->assertSame( QuizLeaveLevel::OFF, QuizLeaveLevel::sanitize( 'on' ) );
		$this->assertSame( QuizLeaveLevel::OFF, QuizLeaveLevel::sanitize( array( '2' ) ) );
		$this->assertSame( QuizLeaveLevel::OFF, QuizLeaveLevel::sanitize( null ) );
	}

	public function test_a_form_that_is_not_a_quiz_has_no_level(): void {
		$this->assertSame( QuizLeaveLevel::OFF, QuizLeaveLevel::of( array( 'quiz_reset_on_leave' => '2' ) ) );
		$this->assertSame( QuizLeaveLevel::STRICT, QuizLeaveLevel::of( array( 'quiz_enabled' => '1', 'quiz_reset_on_leave' => '2' ) ) );
		$this->assertSame( QuizLeaveLevel::OFF, QuizLeaveLevel::of( array( 'quiz_enabled' => '1' ) ) );
	}

	public function test_the_grace_is_clamped_to_its_bounds(): void {
		$this->assertSame( 0, QuizLeaveLevel::clamp_grace( -5 ) );
		$this->assertSame( 1500, QuizLeaveLevel::clamp_grace( 1500 ) );
		$this->assertSame( QuizLeaveLevel::GRACE_MAX_MS, QuizLeaveLevel::clamp_grace( 999999 ) );
	}

	public function test_the_levels_run_mildest_first(): void {
		$this->assertSame( array( '0', '1', '2' ), QuizLeaveLevel::LEVELS );
	}
}
