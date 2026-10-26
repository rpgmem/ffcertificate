<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceFormInvite;
use FreeFormCertificate\Audience\AudienceFormInviteResolver;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Who a post-event invitation is for, as the certificate form asks it (#1948).
 *
 * @covers \FreeFormCertificate\Audience\AudienceFormInviteResolver
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceFormInviteResolverTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const FORM = 40;

	private const CPF = '52998224725';

	/** @var \Mockery\MockInterface */
	private $users;

	/** @var \Mockery\MockInterface */
	private $bookings;

	/** @var int */
	private $now = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				$post              = new \WP_Post();
				$post->post_type   = 'ffc_form';
				$post->post_status = 'publish';
				return $post;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				if ( 7 !== (int) $id ) {
					return false;
				}
				return (object) array(
					'display_name' => 'Ana Souza',
					'user_email'   => 'ana@example.org',
				);
			}
		);

		$this->bookings = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$booking = (object) array(
			'id'                        => 5,
			'status'                    => 'active',
			'description'               => 'Workshop',
			'booking_date'              => '2027-03-01',
			'start_time'                => '09:00:00',
			'end_time'                  => '12:00:00',
			'post_event_custom'         => '1',
			'post_event_form_id'        => (string) self::FORM,
			'post_event_window_days'    => '7',
			'post_event_attendees_only' => '0',
		);
		$this->bookings->shouldReceive( 'get_by_id' )->andReturnUsing( static fn( int $id ) => 5 === $id ? $booking : null );
		$this->bookings->shouldReceive( 'get_all_affected_users' )->andReturn( array( '7' ) );

		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceAttendanceReader' )
			->shouldReceive( 'days_present' )->andReturn( array() );

		Mockery::mock( 'alias:FreeFormCertificate\Core\SensitiveFieldRegistry' )
			->shouldReceive( 'hash_identifier' )->andReturnUsing( static fn( string $k, string $v ): string => $k . ':' . $v );

		$this->users = Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserManager' );
		$this->users->shouldReceive( 'get_user_identifiers_masked' )->andReturn(
			array(
				'cpfs' => array(),
				'rfs'  => array(),
			)
		)->byDefault();

		$this->now = ( new \DateTimeImmutable( '2027-03-01 12:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp() + 3600;
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_an_invitation_names_the_person_from_their_account(): void {
		$invite = AudienceFormInviteResolver::resolve( self::FORM, AudienceFormInvite::code( 5, 7, self::FORM ), null, $this->now );

		$this->assertSame(
			array(
				'booking_id' => 5,
				'user_id'    => 7,
				'name'       => 'Ana Souza',
				'email'      => 'ana@example.org',
				'event'      => 'Workshop',
			),
			$invite
		);
	}

	/**
	 * Booking, person and form the code is signed for, the form being
	 * filled in, whether the signature is tampered with, and the refusal.
	 *
	 * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: bool, 5: string}>
	 */
	public function refusals(): array {
		return array(
			'a tampered code'             => array( 5, 7, self::FORM, self::FORM, true, 'invalid' ),
			'a code for another form'     => array( 5, 7, 41, self::FORM, false, 'invalid' ),
			'an unknown booking'          => array( 6, 7, self::FORM, self::FORM, false, 'invalid' ),
			'a deleted account'           => array( 5, 9, self::FORM, self::FORM, false, 'invalid' ),
			'a booking now on another form' => array( 5, 7, 41, 41, false, 'no_form' ),
		);
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param int    $booking_id Booking the code names.
	 * @param int    $user_id    Person the code names.
	 * @param int    $signed_for Form the code is signed for.
	 * @param int    $form_id    Form being filled in.
	 * @param bool   $tamper     Whether to change the signature.
	 * @param string $error      Expected error code.
	 */
	public function test_each_refusal_names_its_reason( int $booking_id, int $user_id, int $signed_for, int $form_id, bool $tamper, string $error ): void {
		$code = AudienceFormInvite::code( $booking_id, $user_id, $signed_for );
		if ( $tamper ) {
			$code = substr( $code, 0, -1 ) . ( 'A' === substr( $code, -1 ) ? 'B' : 'A' );
		}

		$result = AudienceFormInviteResolver::resolve( $form_id, $code, null, $this->now );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $error, $result->get_error_code() );
		$this->assertNotSame( '', AudienceFormInviteResolver::message( $error ) );
	}

	public function test_a_closed_window_is_refused_by_the_eligibility_check(): void {
		$result = AudienceFormInviteResolver::resolve( self::FORM, AudienceFormInvite::code( 5, 7, self::FORM ), null, $this->now + 8 * DAY_IN_SECONDS );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'closed', $result->get_error_code() );
	}

	public function test_a_cpf_the_person_already_holds_is_accepted(): void {
		$this->users->shouldReceive( 'resolve_existing_user' )->with( 'cpf:' . self::CPF, null, '' )->andReturn( 7 );

		$this->assertTrue( AudienceFormInviteResolver::check_identifier( 7, self::CPF ) );
	}

	public function test_a_cpf_of_another_account_is_refused(): void {
		$this->users->shouldReceive( 'resolve_existing_user' )->andReturn( 12 );

		$result = AudienceFormInviteResolver::resolve( self::FORM, AudienceFormInvite::code( 5, 7, self::FORM ), self::CPF, $this->now );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'identity_taken', $result->get_error_code() );
	}

	public function test_an_unknown_cpf_is_refused_when_the_person_has_another_on_record(): void {
		$this->users->shouldReceive( 'resolve_existing_user' )->andReturn( 0 );
		$this->users->shouldReceive( 'get_user_identifiers_masked' )->with( 7 )->andReturn(
			array(
				'cpfs' => array( '***.456.789-**' ),
				'rfs'  => array(),
			)
		);

		$result = AudienceFormInviteResolver::check_identifier( 7, self::CPF );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'identity_mismatch', $result->get_error_code() );
	}

	public function test_a_first_identifier_is_accepted_and_an_rf_is_checked_as_an_rf(): void {
		$this->users->shouldReceive( 'resolve_existing_user' )->with( null, 'rf:1234567', '' )->andReturn( 0 );
		$this->users->shouldReceive( 'get_user_identifiers_masked' )->with( 7 )->andReturn(
			array(
				'cpfs' => array( '***.456.789-**' ),
				'rfs'  => array(),
			)
		);

		$this->assertTrue( AudienceFormInviteResolver::check_identifier( 7, '1234567' ), 'A CPF on record does not stop a first RF.' );
	}

	public function test_the_filter_keeps_an_earlier_answer_and_resolves_otherwise(): void {
		$this->assertSame( 'earlier', AudienceFormInviteResolver::filter( 'earlier', self::FORM, 'x' ) );

		$result = AudienceFormInviteResolver::filter( null, self::FORM, 'not a code', '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid', $result->get_error_code() );
	}

	public function test_registering_hooks_the_filter_the_form_fires(): void {
		AudienceFormInviteResolver::register();

		$this->assertNotFalse( has_filter( 'ffcertificate_form_invite', array( AudienceFormInviteResolver::class, 'filter' ) ) );
		$this->assertSame( \FreeFormCertificate\Frontend\FormInvite::FILTER, AudienceFormInviteResolver::FILTER, 'The two modules name one hook.' );
	}
}
