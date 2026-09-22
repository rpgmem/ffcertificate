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
 * What the merge form actually posts, read end to end (#1386).
 *
 * THE ONLY HANDLER ON THIS SCREEN THAT READS A NESTED ARRAY.
 *
 * Every other verb posts flat strings, so every other handler is served by
 * an accessor that sanitises each element. The merge posts one group per
 * pair -- `ffc_pair[3][confirm]`, `[a]`, `[b]`, `[keep]` -- and an accessor
 * that runs `sanitize_text_field()` over the container returns `''` for each
 * group, because that is what core does with an array. Every pair then reads
 * as unconfirmed and the screen says so, which is indistinguishable from an
 * operator who ticked nothing.
 *
 * The rest of this screen's tests read the source as TEXT, which is why this
 * shipped: a static scan sees the accessor named and cannot see what it does
 * to a shape it was not written for.
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
	 * THE PRODUCTION CASE, EXACTLY AS THE FORM POSTS IT.
	 *
	 * The operator ticked one pair of three and chose the survivor. The two
	 * untouched pairs post nothing at all -- an unticked checkbox is absent
	 * from the payload, so their groups arrive carrying only the hidden ids.
	 */
	public function test_a_ticked_pair_is_merged(): void {
		$_POST = array(
			'ffc_pair' => array(
				'0' => array(
					'a' => '355',
					'b' => '5276',
				),
				'1' => array(
					'a' => '3634',
					'b' => '4963',
				),
				'2' => array(
					'confirm' => '1',
					'a'       => '5784',
					'b'       => '6092',
					'keep'    => '5784',
				),
			),
		);

		$this->submit();

		$this->assertSame(
			array( array( 5784, 6092 ) ),
			$this->merged,
			'The ticked pair must reach the merge, and only that one.'
		);
		$this->assertSame( 'success', $this->outcome['type'] ?? '' );
	}

	/**
	 * The survivor decides the direction, not the field order.
	 */
	public function test_choosing_the_other_account_reverses_the_pair(): void {
		$_POST = array(
			'ffc_pair' => array(
				'0' => array(
					'confirm' => '1',
					'a'       => '5784',
					'b'       => '6092',
					'keep'    => '6092',
				),
			),
		);

		$this->submit();

		$this->assertSame( array( array( 6092, 5784 ) ), $this->merged );
	}

	/**
	 * Ticking nothing still reports nothing merged -- the message the defect
	 * produced, which must stay reachable for the reason it names.
	 */
	public function test_an_empty_confirmation_merges_nothing(): void {
		$_POST = array(
			'ffc_pair' => array(
				'0' => array(
					'a' => '355',
					'b' => '5276',
				),
			),
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
			'ffc_pair' => array(
				'0' => array(
					'confirm' => '1',
					'a'       => '5784',
					'b'       => '6092',
					'keep'    => '99',
				),
			),
		);

		$this->submit();

		$this->assertSame( array(), $this->merged );
		$this->assertSame( 'error', $this->outcome['type'] ?? '' );
	}
}
