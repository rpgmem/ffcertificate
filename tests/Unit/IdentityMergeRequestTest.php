<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Maintenance\IdentityMerge;
use RuntimeException;

/**
 * What the merge form actually posts, read end to end.
 *
 * HISTORY, BECAUSE THE SHAPE THIS WAS WRITTEN FOR IS GONE.
 *
 * The merge USED to post one group per pair -- `ffc_pair[3][confirm]`, `[a]`,
 * `[b]`, `[keep]` -- and the handler read it through an accessor that runs
 * `sanitize_text_field()` over the container, which returns `''` for an array
 * because that is what core does. Every pair read as unconfirmed and the
 * screen said so, indistinguishable from an operator who ticked nothing
 * (#1386, fixed in #1396). #1397 sprint 5 then made the merge one pair per
 * request, so the payload is flat strings and that class cannot recur HERE.
 *
 * It can recur elsewhere: nine other handlers still read a nested container,
 * and `RequestInput`'s own tests are where that accessor's contract is held.
 * What stays here is the end-to-end reading of THIS form, now over the shape
 * it posts today -- including the acknowledgement, which is the one field
 * whose absence must refuse rather than quietly do nothing.
 *
 * The rest of this screen's tests read the source as TEXT, which is why the
 * original defect shipped: a static scan sees the accessor named and cannot
 * see what it does to a shape it was not written for.
 *
 * @covers \FreeFormCertificate\Admin\IdentityResolutionPage
 */
class IdentityMergeRequestTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * What `report()` put in the transient before redirecting.
	 *
	 * @var array<string, mixed>
	 */
	private array $outcome = array();

	/**
	 * Pairs the merge seam was asked to consolidate, as `[survivor, absorbed]`.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $merged = array();

	/**
	 * Stand up the WordPress functions the handler reaches.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Admin\IdentityResolutionPage' );

		$this->outcome = array();
		$this->merged  = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				return 1 === $number ? $single : $plural;
			}
		);
		Functions\when( 'number_format_i18n' )->returnArg( 1 );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		// THE REAL CAPABILITY CLASS, OPENED BY STUBBING WHAT IT CALLS.
		//
		// An alias mock on `Capabilities` is the obvious way and it breaks the
		// SUITE while passing under `--filter`: Mockery cannot alias a class
		// another test already autoloaded, so it dies with "class already
		// exists" in a file this one never touches. That is the order
		// dependence CLAUDE.md records, and the full run is what found it.
		//
		// `current_user_can_admin_or()` consults nothing but
		// `current_user_can()`, so stubbing that leaves the real gate in the
		// path and exercises it, which the alias mock never did.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.org/wp-admin/' );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);

		// FAITHFUL TO CORE, AND THAT IS THE WHOLE TEST.
		//
		// `sanitize_text_field()` returns '' for an array -- core's
		// `_sanitize_text_fields()` opens with exactly that check. A stub
		// written as `returnArg( 1 )` is the comfortable one and it makes this
		// test pass against the defect, because the flattening IS the defect.
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return is_array( $value ) || is_object( $value ) ? '' : trim( (string) $value );
			}
		);

		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->outcome = (array) $value;

				return true;
			}
		);

		// `report()` ends in `exit`, so the redirect is where the handler is
		// caught: throwing here is what lets the outcome be read at all.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function () {
				throw new RuntimeException( 'ffc_test_redirected' );
			}
		);
	}

	/**
	 * Tear down Brain\Monkey and the request.
	 */
	protected function tearDown(): void {
		$_POST = array();

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A page whose merge is a double and whose capability gate is open.
	 *
	 * @return IdentityResolutionPage
	 */
	private function page(): IdentityResolutionPage {
		$merge = Mockery::mock( IdentityMerge::class );
		$merge->shouldReceive( 'merge' )->andReturnUsing(
			function ( $survivor, $absorbed ) {
				$this->merged[] = array( (int) $survivor, (int) $absorbed );

				return array(
					'moved'    => array( 'wp_ffc_submissions' => 2 ),
					'gained'   => array(),
					'survivor' => (int) $survivor,
				);
			}
		);

		return new class( $merge ) extends IdentityResolutionPage {

			/** @var IdentityMerge */
			private $double;

			/**
			 * @param IdentityMerge $double Stand-in for the merge.
			 */
			public function __construct( $double ) {
				$this->double = $double;
			}

			/**
			 * @return IdentityMerge
			 */
			protected function mergers(): IdentityMerge {
				return $this->double;
			}
		};
	}

	/**
	 * Run the handler and let `report()`'s redirect end it.
	 *
	 * @return void
	 */
	private function submit(): void {
		try {
			$this->page()->handle_merge();
		} catch ( RuntimeException $e ) {
			if ( 'ffc_test_redirected' !== $e->getMessage() ) {
				throw $e;
			}
		}
	}

	/**
	 * THE ORDINARY CASE, EXACTLY AS THE FORM POSTS IT.
	 *
	 * One pair, the survivor chosen, the acknowledgement ticked. Nothing else
	 * travels: the other findings are other forms.
	 */
	public function test_an_acknowledged_pair_is_merged(): void {
		$_POST = array(
			'ffc_subject' => 'sharedHash',
			'ffc_ack'     => '1',
			'ffc_a'       => '5784',
			'ffc_b'       => '6092',
			'ffc_keep'    => '5784',
		);

		$this->submit();

		$this->assertSame( array( array( 5784, 6092 ) ), $this->merged );
		$this->assertSame( 'success', $this->outcome['type'] ?? '' );
	}

	/**
	 * The survivor decides the direction, not the field order.
	 */
	public function test_choosing_the_other_account_reverses_the_pair(): void {
		$_POST = array(
			'ffc_subject' => 'sharedHash',
			'ffc_ack'     => '1',
			'ffc_a'       => '5784',
			'ffc_b'       => '6092',
			'ffc_keep'    => '6092',
		);

		$this->submit();

		$this->assertSame( array( array( 6092, 5784 ) ), $this->merged );
	}

	/**
	 * THE ACKNOWLEDGEMENT IS A REFUSAL WHEN ABSENT, NOT A NO-OP.
	 *
	 * An unticked checkbox is absent from the payload. A form that posts and
	 * reports nothing reads as a bug, and on the one verb no other undoes the
	 * operator has to be told why nothing happened.
	 */
	public function test_an_unacknowledged_merge_is_refused_and_says_so(): void {
		$_POST = array(
			'ffc_subject' => 'sharedHash',
			'ffc_a'       => '5784',
			'ffc_b'       => '6092',
			'ffc_keep'    => '5784',
		);

		$this->submit();

		$this->assertSame( array(), $this->merged );
		$this->assertSame( 'error', $this->outcome['type'] ?? '' );
	}

	/**
	 * A survivor that is neither of the pair's two accounts is refused, so a
	 * tampered payload cannot empty an account the operator never saw.
	 */
	public function test_a_survivor_outside_the_pair_is_refused(): void {
		$_POST = array(
			'ffc_subject' => 'sharedHash',
			'ffc_ack'     => '1',
			'ffc_a'       => '5784',
			'ffc_b'       => '6092',
			'ffc_keep'    => '99',
		);

		$this->submit();

		$this->assertSame( array(), $this->merged );
		$this->assertSame( 'error', $this->outcome['type'] ?? '' );
	}

	/**
	 * ONE PAIR PER REQUEST, WHICH IS THE POINT OF THE CHANGE.
	 *
	 * The old payload could name several, and an outcome could then be part
	 * merged and part refused. A leftover group from that shape must not
	 * smuggle a second merge through: only the flat fields are read.
	 */
	public function test_a_leftover_batch_payload_merges_nothing_extra(): void {
		$_POST = array(
			'ffc_subject' => 'sharedHash',
			'ffc_ack'     => '1',
			'ffc_a'       => '5784',
			'ffc_b'       => '6092',
			'ffc_keep'    => '5784',
			'ffc_pair'    => array(
				'0' => array(
					'confirm' => '1',
					'a'       => '111',
					'b'       => '222',
					'keep'    => '111',
				),
			),
		);

		$this->submit();

		$this->assertSame( array( array( 5784, 6092 ) ), $this->merged );
	}
}
