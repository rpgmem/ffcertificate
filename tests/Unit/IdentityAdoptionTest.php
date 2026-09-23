<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityAdoption;
use WP_Error;

/**
 * Giving an orphaned record an account (#1397 sprint 6).
 *
 * IT MUST NOT CREATE A USER ITSELF, AND THAT IS THE FIRST THING ASSERTED.
 *
 * `UserCreator::get_or_create_user_dual()` is the plugin's one path from an
 * identity to an account, and a second one is how two accounts come to exist
 * for one person — the population this whole queue is cleaning up.
 * `IdentityConvergenceGuardTest` holds that rule repository-wide; what these
 * hold is that this class validates and hands over rather than doing any of
 * it again.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityAdoption
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IdentityAdoptionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * A CPF whose two check digits match.
	 *
	 * @var string
	 */
	private const GOOD_CPF = '11144477735';

	/**
	 * An RF whose check digit matches.
	 *
	 * @var string
	 */
	private const GOOD_RF = '1234561';

	/**
	 * What reached the creator, or null if it was never called.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $handed = null;

	/**
	 * What the creator answers with.
	 *
	 * @var int|WP_Error
	 */
	private $answers = 0;

	/**
	 * What `resolve_existing_user()` answers.
	 *
	 * @var int
	 */
	private int $already = 0;

	/**
	 * Lines that reached the activity log.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $logged = array();

	/**
	 * Stand up the WordPress boundary.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Maintenance\IdentityAdoption' );

		$this->handed  = null;
		$this->answers = 4711;
		$this->already = 0;
		$this->logged  = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'is_email' )->alias(
			static function ( $value ) {
				return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
			}
		);
	}

	/**
	 * Tear down Brain\Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * An adoption whose creator and log are doubles.
	 *
	 * @return IdentityAdoption
	 */
	private function adoption(): IdentityAdoption {
		return new class( $this ) extends IdentityAdoption {

			/** @var IdentityAdoptionTest */
			private $test;

			/**
			 * @param IdentityAdoptionTest $test The case.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * @param string $cpf_hash CPF hash.
			 * @param string $rf_hash  RF hash.
			 * @param string $email    Address.
			 * @return int
			 */
			protected function existing( string $cpf_hash, string $rf_hash, string $email ): int {
				return $this->test->already_there();
			}

			/**
			 * @param string $cpf_hash CPF hash.
			 * @param string $rf_hash  RF hash.
			 * @param string $email    Address.
			 * @return int|\WP_Error
			 */
			protected function creator( string $cpf_hash, string $rf_hash, string $email ) {
				return $this->test->record_handover( $cpf_hash, $rf_hash, $email );
			}
		};
	}

	/**
	 * What `resolve_existing_user()` would answer.
	 *
	 * @return int
	 */
	public function already_there(): int {
		return $this->already;
	}

	/**
	 * Record the handover and answer as the creator would.
	 *
	 * @param string $cpf_hash CPF hash.
	 * @param string $rf_hash  RF hash.
	 * @param string $email    Address.
	 * @return int|WP_Error
	 */
	public function record_handover( string $cpf_hash, string $rf_hash, string $email ) {
		$this->handed = array(
			'cpf'   => $cpf_hash,
			'rf'    => $rf_hash,
			'email' => $email,
		);

		return $this->answers;
	}

	/**
	 * THE HANDOVER, AND NOTHING BUT.
	 *
	 * Both numbers reach the creator as HASHES and the address as it stands,
	 * which is the shape that method takes — a value passed where a hash is
	 * expected matches nobody and creates a duplicate in silence.
	 */
	public function test_it_hands_hashes_to_the_one_creation_path(): void {
		$result = $this->adoption()->adopt( self::GOOD_CPF, self::GOOD_RF, 'person@example.org' );

		$this->assertIsArray( $result );
		$this->assertSame( 4711, $result['account'] );
		$this->assertNotNull( $this->handed, 'The adoption must go through the creator, never around it.' );
		$this->assertSame( 'person@example.org', $this->handed['email'] );
		$this->assertNotSame( self::GOOD_CPF, $this->handed['cpf'], 'The CPF must be hashed, not passed through.' );
		$this->assertNotSame( self::GOOD_RF, $this->handed['rf'], 'The RF must be hashed, not passed through.' );
		$this->assertNotSame( '', $this->handed['cpf'] );
		$this->assertNotSame( '', $this->handed['rf'] );
	}

	/**
	 * ALL THREE ARE REQUIRED, AND THE REFUSAL SAYS WHY.
	 *
	 * `get_or_create_user_dual()` accepts any one of them. Accepting one here
	 * would open an account the resolver fails to match on the next record
	 * carrying a different identifier — which manufactures the duplicate this
	 * screen exists to resolve.
	 */
	public function test_it_refuses_fewer_than_three_identifiers(): void {
		foreach (
			array(
				array( '', self::GOOD_RF, 'person@example.org' ),
				array( self::GOOD_CPF, '', 'person@example.org' ),
				array( self::GOOD_CPF, self::GOOD_RF, '' ),
			) as $case
		) {
			$result = $this->adoption()->adopt( $case[0], $case[1], $case[2] );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'ffc_identity_adopt_incomplete', $result->get_error_code() );
			$this->assertNull( $this->handed, 'Nothing may reach the creator when the set is incomplete.' );
		}
	}

	/**
	 * A CPF that fails its check digits is refused before anything is opened.
	 */
	public function test_it_refuses_a_cpf_that_fails_its_check_digits(): void {
		$result = $this->adoption()->adopt( '11144477734', self::GOOD_RF, 'person@example.org' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_adopt_bad_cpf', $result->get_error_code() );
		$this->assertNull( $this->handed );
	}

	/**
	 * THE RF'S CHECK DIGIT IS ENFORCED HERE, WHICH `validate_rf()` ALONE
	 * WOULD NOT DO.
	 *
	 * That function checks the digit only behind the
	 * `ffc_validate_rf_check_digit` opt-in, because refusing a REGISTRATION
	 * on an inferred rule is worse than storing a typo the audit finds later.
	 * An account opened from a value an operator confirmed is a different
	 * moment, judged by the same rule a correction is — so this fails if the
	 * shared `IdentityRepair::well_formed()` is swapped for `validate_rf()`.
	 */
	public function test_it_refuses_an_rf_that_fails_its_check_digit(): void {
		$result = $this->adoption()->adopt( self::GOOD_CPF, '1234562', 'person@example.org' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_adopt_bad_rf', $result->get_error_code() );
		$this->assertNull( $this->handed );
	}

	/**
	 * An address can only be format-checked, and it is refused in its own
	 * words — different confidences, stated differently.
	 */
	public function test_it_refuses_an_address_it_cannot_use(): void {
		$result = $this->adoption()->adopt( self::GOOD_CPF, self::GOOD_RF, 'not-an-address' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_adopt_bad_email', $result->get_error_code() );
		$this->assertNull( $this->handed );
	}

	/**
	 * AN ACCOUNT THAT ALREADY ANSWERS IS USED, AND SAYING SO IS THE POINT.
	 *
	 * `get_or_create_user_dual()` returns an id whichever of its three
	 * branches ran, so afterwards there is no telling. Asking before is what
	 * lets the screen say "adopted into an existing account" rather than
	 * implying a login was opened.
	 */
	public function test_it_reports_whether_a_login_was_opened(): void {
		$this->already = 0;
		$opened        = $this->adoption()->adopt( self::GOOD_CPF, self::GOOD_RF, 'person@example.org' );

		$this->assertIsArray( $opened );
		$this->assertTrue( $opened['created'] );

		$this->handed  = null;
		$this->already = 4711;
		$matched       = $this->adoption()->adopt( self::GOOD_CPF, self::GOOD_RF, 'person@example.org' );

		$this->assertIsArray( $matched );
		$this->assertFalse( $matched['created'], 'An account that already answered was not created.' );
		$this->assertSame( 4711, $matched['account'] );
	}

	/**
	 * A refusal from the creator is passed through rather than reworded.
	 */
	public function test_a_refusal_from_the_creator_is_passed_through(): void {
		$this->answers = new WP_Error( 'ffc_user_no_identifier', 'No identifier provided.' );

		$result = $this->adoption()->adopt( self::GOOD_CPF, self::GOOD_RF, 'person@example.org' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffc_user_no_identifier', $result->get_error_code() );
	}

	/**
	 * A creator answering with nothing usable is a refusal, not a success
	 * carrying account zero.
	 */
	public function test_an_account_of_zero_is_a_refusal(): void {
		$this->answers = 0;

		$result = $this->adoption()->adopt( self::GOOD_CPF, self::GOOD_RF, 'person@example.org' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_adopt_failed', $result->get_error_code() );
	}
}
