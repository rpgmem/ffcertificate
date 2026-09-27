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

		// THE DOUBLE IS FOR `proposal()` ALONE, and `split()` still reaches no
		// database: every seam of the write is overridden below, so a query
		// arriving from it would be a change nobody asked for.
		$this->store_rows = array();
		$this->plain      = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				return array( 'sql' => $sql, 'args' => $args );
			}
		);

		// Only the `SHOW TABLES LIKE <table>` probe reaches `get_var`, and it
		// answers with the table so every store exists.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $prepared ) {
				return $prepared['args'][0] ?? null;
			}
		);

		// `SELECT … FROM %i WHERE %i = %s` -- table, column, hash.
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$table = (string) ( $prepared['args'][0] ?? '' );
				$hash  = (string) ( $prepared['args'][2] ?? '' );

				return $this->store_rows[ $table ][ $hash ] ?? array();
			}
		);

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Rows a store answers with, keyed `table => hash => rows`.
	 *
	 * @var array<string, array<string, array<int, array<string, mixed>>>>
	 */
	private array $store_rows = array();

	/**
	 * Ciphertext => plaintext, for the proposal's decrypt seam.
	 *
	 * @var array<string, string>
	 */
	private array $plain = array();

	/**
	 * What a ciphertext decrypts to, or null for unreadable.
	 *
	 * @param string $cipher Stored ciphertext.
	 * @return string|null
	 */
	public function plaintext_of( string $cipher ): ?string {
		return $this->plain[ $cipher ] ?? null;
	}

	/**
	 * Teach a store which rows carry a hash.
	 *
	 * @param string                           $table Unprefixed table.
	 * @param string                           $hash  The identifier hash.
	 * @param array<int, array<string, mixed>> $rows  Rows to answer with.
	 * @return void
	 */
	private function carrying( string $table, string $hash, array $rows ): void {
		$this->store_rows[ 'wp_' . $table ][ $hash ] = $rows;
	}

	/**
	 * Tear down Brain\Monkey.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
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
			 * Decryption without a key, so a proposal can be driven.
			 *
			 * @param string $cipher Stored ciphertext.
			 * @return string|null
			 */
			protected function decrypt( string $cipher ): ?string {
				return $this->test->plaintext_of( $cipher );
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
	// ==================================================================
	// proposal() -- deriving the address, showing the names (#1480)
	// ==================================================================

	/**
	 * THE CASE THE OLD DESIGN DECISION DID NOT COVER.
	 *
	 * `split()`'s docblock argues the operator must supply the address because
	 * every production finding reports `shared_email`. That was measured and is
	 * still nearly true -- 37 of 38 -- but where each identifier has its own
	 * address there IS something to inherit, and asking somebody to type an
	 * address they can already see is where a typo enters.
	 */
	public function test_it_derives_the_address_the_records_carry(): void {
		$this->plain['cipherA'] = 'Clarice@Example.ORG';
		$this->carrying(
			'ffc_submissions',
			'rfTheirs',
			array(
				array(
					'email_hash'      => 'hashA',
					'email_encrypted' => 'cipherA',
					'data'            => '{"nome_completo":"Clarice Fontes Miranda"}',
					'data_encrypted'  => '',
				),
			)
		);

		$out = $this->split()->proposal( 'rfTheirs' );

		// NORMALISED, not taken as stored: `normalize_email()` is the one
		// function that decides what an address is, and the operator typing it
		// by hand would have gone through it too.
		$this->assertSame( 'clarice@example.org', $out['email'] );
		$this->assertSame( '', $out['reason'] );
		$this->assertSame( array( 'Clarice Fontes Miranda' ), $out['names'] );
	}

	/**
	 * THE NAME IS EVIDENCE, NOT A CONDITION, and it comes from three different
	 * places (#1480).
	 *
	 * `ffc_self_scheduling_appointments` and `ffc_recruitment_candidate` each
	 * declare a plain `name` column; `ffc_submissions` declares none, so the
	 * name sits inside the answers under a per-form key. A proposal that read
	 * only one of the two shapes would show a name for some rows and nothing
	 * for others, which reads as "this row has no name".
	 */
	public function test_it_collects_names_from_every_store_s_own_shape(): void {
		$this->plain['cipherA'] = 'shared@example.org';
		$this->carrying(
			'ffc_submissions',
			'rfTheirs',
			array(
				array(
					'email_hash'      => 'hashA',
					'email_encrypted' => 'cipherA',
					'data'            => '{"participante":"From The Answers"}',
					'data_encrypted'  => '',
				),
			)
		);
		$this->carrying(
			'ffc_self_scheduling_appointments',
			'rfTheirs',
			array(
				array( 'email_hash' => 'hashA', 'email_encrypted' => 'cipherA', 'name' => 'From The Column' ),
			)
		);

		$out = $this->split()->proposal( 'rfTheirs' );

		$this->assertSame( 'shared@example.org', $out['email'] );
		$this->assertContains( 'From The Answers', $out['names'] );
		$this->assertContains( 'From The Column', $out['names'] );
	}

	/**
	 * The encrypted answers win over the plaintext column, because that is the
	 * copy kept current: `data` is what installs held before the answers were
	 * encrypted, and a row carrying both has the ciphertext as the answer.
	 */
	public function test_the_encrypted_answers_are_preferred_over_the_plaintext_column(): void {
		$this->plain['cipherA'] = 'a@example.org';
		$this->plain['cipherD'] = '{"nome_completo":"The Current Name"}';
		$this->carrying(
			'ffc_submissions',
			'rfTheirs',
			array(
				array(
					'email_hash'      => 'hashA',
					'email_encrypted' => 'cipherA',
					'data'            => '{"nome_completo":"The Stale Name"}',
					'data_encrypted'  => 'cipherD',
				),
			)
		);

		$this->assertSame( array( 'The Current Name' ), $this->split()->proposal( 'rfTheirs' )['names'] );
	}

	/**
	 * TWO ADDRESSES IS NOT A PROPOSAL, the same rule the agreement applies to
	 * an identifier: two values do not say which the new account gets, and
	 * picking would invent an answer.
	 */
	public function test_two_addresses_propose_nothing(): void {
		$this->plain['cipherA'] = 'a@example.org';
		$this->plain['cipherB'] = 'b@example.org';
		$this->carrying(
			'ffc_submissions',
			'rfTheirs',
			array(
				array( 'email_hash' => 'hashA', 'email_encrypted' => 'cipherA', 'data' => '', 'data_encrypted' => '' ),
				array( 'email_hash' => 'hashB', 'email_encrypted' => 'cipherB', 'data' => '', 'data_encrypted' => '' ),
			)
		);

		$out = $this->split()->proposal( 'rfTheirs' );

		$this->assertSame( '', $out['email'] );
		$this->assertSame( 'several', $out['reason'] );
	}

	/**
	 * THE COUNT THAT DECIDES IS A COUNT OF HASHES, so two rows carrying the
	 * SAME address are one address however many rows there are -- and only the
	 * survivor is ever deciphered.
	 */
	public function test_many_rows_with_one_address_are_one_address(): void {
		$this->plain['cipherA'] = 'one@example.org';
		$this->carrying(
			'ffc_submissions',
			'rfTheirs',
			array(
				array( 'email_hash' => 'hashA', 'email_encrypted' => 'cipherA', 'data' => '', 'data_encrypted' => '' ),
				array( 'email_hash' => 'hashA', 'email_encrypted' => 'cipherA', 'data' => '', 'data_encrypted' => '' ),
			)
		);

		$this->assertSame( 'one@example.org', $this->split()->proposal( 'rfTheirs' )['email'] );
	}

	/**
	 * AN ADDRESS THAT IS ALREADY AN ACCOUNT'S MEANS THE VERB IS A MOVE.
	 *
	 * `split()` refuses it at write time and is right to. Reported here it is
	 * actionable instead: the destination exists, so the records go to it.
	 */
	public function test_an_address_that_already_has_an_account_is_reported_as_taken(): void {
		Functions\when( 'email_exists' )->justReturn( 77 );

		$this->plain['cipherA'] = 'taken@example.org';
		$this->carrying(
			'ffc_submissions',
			'rfTheirs',
			array(
				array( 'email_hash' => 'hashA', 'email_encrypted' => 'cipherA', 'data' => '', 'data_encrypted' => '' ),
			)
		);

		$out = $this->split()->proposal( 'rfTheirs' );

		$this->assertSame( '', $out['email'] );
		$this->assertSame( 'taken', $out['reason'] );
	}

	/**
	 * AN ADDRESS NOBODY CAN READ IS NOT AN ADDRESS, and saying so beats
	 * proposing an empty field that looks like "there is none" -- the #1071
	 * rule on a screen rather than in a guard.
	 */
	public function test_an_unreadable_address_says_so_rather_than_proposing_nothing(): void {
		$this->carrying(
			'ffc_submissions',
			'rfTheirs',
			array(
				array( 'email_hash' => 'hashA', 'email_encrypted' => 'cipherNoKey', 'data' => '', 'data_encrypted' => '' ),
			)
		);

		$out = $this->split()->proposal( 'rfTheirs' );

		$this->assertSame( '', $out['email'] );
		$this->assertSame( 'unreadable', $out['reason'] );
	}

	/**
	 * A stored value that decrypts to something that is not an address is
	 * unreadable too, not a proposal: `is_email()` is the separate question
	 * `split()` already asks, and asking it here keeps the field from being
	 * pre-filled with a value the write would refuse.
	 */
	public function test_a_decrypted_value_that_is_not_an_address_is_unreadable(): void {
		$this->plain['cipherA'] = 'not an address';
		$this->carrying(
			'ffc_submissions',
			'rfTheirs',
			array(
				array( 'email_hash' => 'hashA', 'email_encrypted' => 'cipherA', 'data' => '', 'data_encrypted' => '' ),
			)
		);

		$this->assertSame( 'unreadable', $this->split()->proposal( 'rfTheirs' )['reason'] );
	}

	/**
	 * No row at all proposes nothing, and says `none` rather than `several`:
	 * the two send an operator to different places.
	 */
	public function test_no_record_carrying_the_identifier_proposes_nothing(): void {
		$out = $this->split()->proposal( 'rfGone' );

		$this->assertSame( '', $out['email'] );
		$this->assertSame( 'none', $out['reason'] );
		$this->assertSame( array(), $out['names'] );
	}

	/**
	 * It proposes and never writes. Asserted rather than assumed, because the
	 * method exists to be called while the operator is still deciding.
	 */
	public function test_a_proposal_creates_nothing_and_moves_nothing(): void {
		$this->plain['cipherA'] = 'a@example.org';
		$this->carrying(
			'ffc_submissions',
			'rfTheirs',
			array(
				array( 'email_hash' => 'hashA', 'email_encrypted' => 'cipherA', 'data' => '', 'data_encrypted' => '' ),
			)
		);

		$this->split()->proposal( 'rfTheirs' );

		$this->assertSame( array(), $this->created );
		$this->assertSame( array(), $this->sequence );
	}

	/**
	 * An unknown identifier proposes nothing rather than querying a column that
	 * does not exist.
	 */
	public function test_an_unknown_identifier_proposes_nothing(): void {
		$this->assertSame( 'none', $this->split()->proposal( 'rfTheirs', 'ticket' )['reason'] );
	}

}
