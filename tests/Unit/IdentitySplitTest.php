<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityRelink;
use FreeFormCertificate\Maintenance\IdentitySplit;
use WP_Error;

/**
 * Giving records an account of their own (#1386).
 *
 * Two properties carry this class and neither is visible from the happy path:
 * the address must be one a person supplied, because the records' own is
 * already taken; and a split that fails must leave NO account behind, or an
 * operator inherits an empty login nobody asked for and has to notice it.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentitySplit
 */
class IdentitySplitTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Accounts this run created, in order.
	 *
	 * @var array<int, int>
	 */
	private array $created = array();

	/**
	 * Accounts this run deleted, in order.
	 *
	 * @var array<int, int>
	 */
	private array $discarded = array();

	/**
	 * Index writes, as `[user_id, column, hash]`.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $seeded = array();

	/**
	 * What the stubbed relink answers with.
	 *
	 * @var array<string, mixed>|WP_Error|null
	 */
	private $relink_result = null;

	/**
	 * What happened, in order, across every seam.
	 *
	 * @var array<int, string>
	 */
	private array $sequence = array();

	/**
	 * Whether the stubbed index write is accepted.
	 *
	 * @var bool
	 */
	private bool $index_ok = true;

	/**
	 * Whether account creation succeeds.
	 *
	 * @var bool
	 */
	private bool $creation_ok = true;

	/**
	 * Set up Brain\Monkey and the WordPress functions this reaches.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Maintenance\IdentitySplit' );

		$this->created       = array();
		$this->discarded     = array();
		$this->seeded        = array();
		$this->sequence      = array();
		$this->index_ok      = true;
		$this->creation_ok   = true;
		$this->relink_result = array(
			'moved'  => array( 'wp_ffc_submissions' => 2 ),
			'gained' => array(),
			'from'   => 398,
		);

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $email );
			}
		);
		Functions\when( 'email_exists' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * Tear down Brain\Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A split whose account creation, index and move are all steerable.
	 *
	 * Every seam is overridden rather than stubbing WordPress: creating and
	 * deleting a user reaches a dozen core functions, and a test that stood
	 * them all up would be asserting against its own scaffolding.
	 *
	 * @return IdentitySplit
	 */
	private function split(): IdentitySplit {
		$relink = Mockery::mock( IdentityRelink::class );
		$relink->shouldReceive( 'relink' )->andReturnUsing(
			function () {
				$this->sequence[] = 'move';

				return $this->relink_result;
			}
		);

		return new class( $this, $relink ) extends IdentitySplit {

			/** @var IdentitySplitTest */
			private $test;

			/** @var IdentityRelink */
			private $relink;

			/**
			 * Take the case and the move double.
			 *
			 * @param IdentitySplitTest $test   The case.
			 * @param IdentityRelink    $relink Stand-in for the move.
			 */
			public function __construct( $test, $relink ) {
				$this->test   = $test;
				$this->relink = $relink;
			}

			/**
			 * @param string $email The address.
			 * @return int|\WP_Error
			 */
			protected function create_account( string $email ) {
				return $this->test->record_creation( $email );
			}

			/**
			 * @param int $user_id The account.
			 * @return void
			 */
			protected function discard_account( int $user_id ): void {
				$this->test->record_discard( $user_id );
			}

			/**
			 * @param int    $user_id The account.
			 * @param string $column  The index column.
			 * @param string $hash    The identifier.
			 * @return bool
			 */
			protected function seed_index( int $user_id, string $column, string $hash ): bool {
				return $this->test->record_seed( $user_id, $column, $hash );
			}

			/**
			 * @return IdentityRelink
			 */
			protected function movements(): IdentityRelink {
				return $this->relink;
			}
		};
	}

	/**
	 * Record an account creation.
	 *
	 * @param string $email The address.
	 * @return int|WP_Error
	 */
	public function record_creation( string $email ) {
		if ( ! $this->creation_ok ) {
			return new WP_Error( 'ffc_test_creation_failed', 'no' );
		}

		$id               = 9000 + count( $this->created );
		$this->created[]  = $id;
		$this->sequence[] = 'create';

		return $id;
	}

	/**
	 * Record an account deletion.
	 *
	 * @param int $user_id The account.
	 * @return void
	 */
	public function record_discard( int $user_id ): void {
		$this->discarded[] = $user_id;
	}

	/**
	 * Record an index seed.
	 *
	 * @param int    $user_id The account.
	 * @param string $column  The column.
	 * @param string $hash    The identifier.
	 * @return bool
	 */
	public function record_seed( int $user_id, string $column, string $hash ): bool {
		$this->seeded[]   = array( $user_id, $column, $hash );
		$this->sequence[] = 'seed';

		return $this->index_ok;
	}

	/**
	 * The ordinary split: an account is created, told what it holds, and the
	 * records move onto it.
	 */
	public function test_it_creates_an_account_and_moves_the_records_onto_it(): void {
		$result = $this->split()->split( 'rfMoving', 'someone@example.org' );

		$this->assertIsArray( $result );
		$this->assertSame( 9000, $result['account'] );
		$this->assertSame( 398, $result['from'] );
		$this->assertSame( array( array( 9000, 'rf_hash', 'rfMoving' ) ), $this->seeded );
		$this->assertSame( array(), $this->discarded );
	}

	/**
	 * THE ADDRESS THE RECORDS CARRY IS ALREADY TAKEN, EVERY TIME.
	 *
	 * All 32 production findings report `shared_email`: both identifiers sit
	 * under the address the existing account uses. So a split offered without
	 * asking for an address could never complete, and this refusal is what
	 * says so in words rather than as a WordPress error about a duplicate.
	 */
	public function test_it_refuses_an_address_that_already_belongs_to_an_account(): void {
		Functions\when( 'email_exists' )->justReturn( 77 );

		$result = $this->split()->split( 'rfMoving', 'taken@example.org' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_split_email_taken', $result->get_error_code() );
		$this->assertSame( array(), $this->created, 'Nothing may be created before the address is known to be free.' );
	}

	/**
	 * An address that is not an address is refused before anything is created.
	 */
	public function test_it_refuses_an_invalid_address(): void {
		foreach ( array( '', 'not-an-address' ) as $email ) {
			$result = $this->split()->split( 'rfMoving', $email );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'ffc_identity_split_invalid_email', $result->get_error_code() );
		}

		$this->assertSame( array(), $this->created );
	}

	/**
	 * A SPLIT THAT FAILS LEAVES NO ACCOUNT BEHIND.
	 *
	 * The move makes its own refusals — records split across two accounts,
	 * records already resolved by somebody else — and any of them would
	 * otherwise leave an empty login nobody asked for, which an operator has
	 * to notice before they can clean it up.
	 */
	public function test_a_refused_move_removes_the_account_it_created(): void {
		$this->relink_result = new WP_Error( 'ffc_identity_relink_ambiguous', 'split across accounts' );

		$result = $this->split()->split( 'rfMoving', 'someone@example.org' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_relink_ambiguous', $result->get_error_code(), 'The move\'s own reason must reach the operator.' );
		$this->assertSame( array( 9000 ), $this->discarded );
	}

	/**
	 * The same for an index that refuses: the account goes too.
	 */
	public function test_an_index_refusal_removes_the_account_it_created(): void {
		$this->index_ok = false;

		$result = $this->split()->split( 'rfMoving', 'someone@example.org' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_split_index_failed', $result->get_error_code() );
		$this->assertSame( array( 9000 ), $this->discarded );
	}

	/**
	 * An account WordPress refuses to create is reported as WordPress put it,
	 * and nothing is seeded or moved.
	 */
	public function test_a_refused_account_creation_stops_the_split(): void {
		$this->creation_ok = false;

		$result = $this->split()->split( 'rfMoving', 'someone@example.org' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffc_test_creation_failed', $result->get_error_code() );
		$this->assertSame( array(), $this->seeded );
		$this->assertSame( array(), $this->discarded, 'There is no account to discard.' );
	}

	/**
	 * THE SEED IS WHAT MAKES THE MOVE POSSIBLE, so it must come first.
	 *
	 * A brand-new account holds nothing, and the move refuses a target that
	 * shares no identifier with the records. Writing the identifier first is
	 * what says whose account this is — and writing it BEFORE the move is what
	 * keeps a failure from leaving something half-owned.
	 */
	public function test_the_new_account_is_told_what_it_holds_before_the_move(): void {
		$this->relink_result = array(
			'moved'  => array(),
			'gained' => array(),
			'from'   => 0,
		);

		$this->split()->split( 'cpfMoving', 'someone@example.org', 0, 'cpf' );

		$this->assertSame( array( array( 9000, 'cpf_hash', 'cpfMoving' ) ), $this->seeded );
		$this->assertSame(
			array( 'create', 'seed', 'move' ),
			$this->sequence,
			'Seeding after the move would ask the move to accept a target holding nothing.'
		);
	}

	/**
	 * THE ADDRESS IS CANONICALISED BEFORE IT IS COMPARED TO EXISTING ONES.
	 *
	 * An operator typing `Joao@...` must reach the same account as `joao@...`,
	 * so the uniqueness check and the account both have to see the canonical
	 * form. Comparing the raw input would open a second account for the same
	 * person, differing only in capitals — which is the defect this whole
	 * queue exists to resolve, created by the tool meant to resolve it.
	 */
	public function test_the_address_is_canonicalised_before_it_is_used(): void {
		$seen = array();

		Functions\when( 'email_exists' )->alias(
			static function ( $email ) use ( &$seen ) {
				$seen[] = $email;

				return false;
			}
		);

		$this->split()->split( 'rfMoving', '  Joao@Example.ORG  ' );

		$this->assertSame( array( 'joao@example.org' ), $seen );
	}

	/**
	 * An identifier this class does not split is refused before anything runs.
	 */
	public function test_it_refuses_an_identifier_it_does_not_split(): void {
		$result = $this->split()->split( 'hash', 'someone@example.org', 0, 'email' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_split_unknown_field', $result->get_error_code() );
		$this->assertSame( array(), $this->created );
	}

	/**
	 * A split with no records named writes nothing.
	 */
	public function test_it_refuses_a_split_with_no_records(): void {
		$result = $this->split()->split( '   ', 'someone@example.org' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_split_no_subject', $result->get_error_code() );
		$this->assertSame( array(), $this->created );
	}
}
