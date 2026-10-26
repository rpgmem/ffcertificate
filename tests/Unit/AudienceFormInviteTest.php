<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceFormInvite;
use PHPUnit\Framework\TestCase;

/**
 * The signed code of a post-event invitation (#1948).
 *
 * @covers \FreeFormCertificate\Audience\AudienceFormInvite
 */
class AudienceFormInviteTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_code_names_its_booking_and_person_for_its_form(): void {
		$code = AudienceFormInvite::code( 5, 7, 40 );

		$this->assertMatchesRegularExpression( '/^5\.7\.[A-Za-z0-9_-]{22}$/', $code );
		$this->assertSame(
			array(
				'booking_id' => 5,
				'user_id'    => 7,
			),
			AudienceFormInvite::verify( $code, 40 )
		);
	}

	public function test_a_code_for_another_form_or_a_changed_part_is_refused(): void {
		$code = AudienceFormInvite::code( 5, 7, 40 );
		$sig  = substr( $code, 4 );

		$this->assertNull( AudienceFormInvite::verify( $code, 41 ), 'Pointing the booking at another form voids the link.' );
		$this->assertNull( AudienceFormInvite::verify( '5.8.' . $sig, 40 ), 'Another person cannot reuse the signature.' );
		$this->assertNull( AudienceFormInvite::verify( '6.7.' . $sig, 40 ), 'Another booking cannot reuse the signature.' );

		$tampered = substr( $code, 0, -1 ) . ( 'A' === substr( $code, -1 ) ? 'B' : 'A' );
		$this->assertNull( AudienceFormInvite::verify( $tampered, 40 ) );
	}

	public function test_a_malformed_code_is_refused(): void {
		foreach ( array( '', '5.7', '0.7.' . str_repeat( 'a', 22 ), '5.7.short', '5.7.' . str_repeat( 'a', 22 ) . 'x', '5.7.' . str_repeat( '+', 22 ) ) as $code ) {
			$this->assertNull( AudienceFormInvite::parse( $code ), $code );
			$this->assertNull( AudienceFormInvite::verify( $code, 40 ), $code );
		}

		$this->assertNull( AudienceFormInvite::verify( AudienceFormInvite::code( 5, 7, 40 ), 0 ) );
	}

	public function test_rotating_the_salt_voids_the_codes(): void {
		$code = AudienceFormInvite::code( 5, 7, 40 );
		Functions\when( 'wp_salt' )->justReturn( 'another salt' );

		$this->assertNull( AudienceFormInvite::verify( $code, 40 ) );
	}
}
