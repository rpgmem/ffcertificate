<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Captcha\ChallengeSigner;
use FreeFormCertificate\Core\Honeypot;
use FreeFormCertificate\Tests\Support\HoneypotStamp;
use PHPUnit\Framework\TestCase;

/**
 * The honeypot: a neutral trap field and a signed render time (#2339).
 *
 * @covers \FreeFormCertificate\Core\Honeypot
 */
class HoneypotTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_person_who_took_a_few_seconds_passes(): void {
		$this->assertTrue( Honeypot::check( HoneypotStamp::fields() ) );
	}

	public function test_a_fresh_stamp_is_too_fast_to_pass(): void {
		$this->assertSame( Honeypot::refusal(), Honeypot::check( array( Honeypot::STAMP_FIELD => Honeypot::stamp() ) ) );
	}

	public function test_a_submission_just_under_the_minimum_is_refused(): void {
		$this->assertSame( Honeypot::refusal(), Honeypot::check( HoneypotStamp::fields( Honeypot::MIN_SECONDS - 2 ) ) );
		$this->assertTrue( Honeypot::check( HoneypotStamp::fields( Honeypot::MIN_SECONDS + 1 ) ) );
	}

	/**
	 * @dataProvider forged_stamps
	 *
	 * @param mixed $stamp What the request carries.
	 */
	public function test_a_missing_or_forged_stamp_is_refused( $stamp ): void {
		$data = null === $stamp ? array() : array( Honeypot::STAMP_FIELD => $stamp );
		$this->assertSame( Honeypot::refusal(), Honeypot::check( $data ) );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function forged_stamps(): array {
		$old = time() - 60;
		return array(
			'absent'             => array( null ),
			'not a string'       => array( array( 'x' ) ),
			'unsigned time'      => array( (string) $old ),
			'wrong signature'    => array( $old . '.' . str_repeat( 'a', 64 ) ),
			'a captcha payload'  => array( $old . '.' . hash_hmac( 'sha256', 'math|' . $old, 'x' ) ),
		);
	}

	public function test_a_time_moved_after_signing_is_refused(): void {
		$signed_at = time() - 1;
		$stamp     = ( $signed_at - 60 ) . '.' . ChallengeSigner::sign( 'hp|' . $signed_at );

		$this->assertSame( Honeypot::refusal(), Honeypot::check( array( Honeypot::STAMP_FIELD => $stamp ) ) );
	}

	public function test_a_filled_trap_is_refused_under_either_name(): void {
		$this->assertSame( Honeypot::refusal(), Honeypot::check( HoneypotStamp::fields() + array( Honeypot::TRAP_FIELD => 'x' ) ) );
		$this->assertSame( Honeypot::refusal(), Honeypot::check( HoneypotStamp::fields() + array( Honeypot::LEGACY_TRAP_FIELD => 'x' ) ) );
	}

	public function test_the_trap_alone_ignores_the_stamp(): void {
		$this->assertTrue( Honeypot::check_trap( array() ) );
		$this->assertSame( Honeypot::refusal(), Honeypot::check_trap( array( Honeypot::TRAP_FIELD => 'x' ) ) );
	}

	public function test_the_trap_name_is_not_one_autofill_fills(): void {
		foreach ( array( 'name', 'email', 'mail', 'phone', 'tel', 'address', 'url', 'website', 'company', 'honeypot', 'trap' ) as $word ) {
			$this->assertStringNotContainsString( $word, Honeypot::TRAP_FIELD );
		}
	}
}
