<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Maintenance\IdentityRepair;
use RuntimeException;
use WP_Error;

/**
 * The way through the acknowledgement gate, read end to end.
 *
 * WHY THIS IS BEHAVIOURAL AND NOT A SOURCE SCAN.
 *
 * Most of this screen's tests read the source as text, and that is what let
 * the defect ship: #1478 added a gate that refuses a correction until the
 * operator acknowledges it, plus a checkbox rendered for a named error code on
 * a named finding. Both halves existed and every text assertion about them
 * passed. What nothing checked is that the two halves meet -- `handle_repair()`
 * built its own transient payload, carrying neither key, so on the one form
 * that refusal can arrive from the box was never drawn. The gate refused and
 * the way through it did not exist, which is what an operator reported (#1487).
 *
 * A static scan cannot see that class: it sees the code named in one file and
 * the key read in another, and cannot tell that no request puts them together.
 *
 * @covers \FreeFormCertificate\Admin\IdentityResolutionPage
 */
class IdentityAcknowledgementPathTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * What the handler left for the next render.
	 *
	 * @var array<string, mixed>
	 */
	private array $outcome = array();

	/**
	 * Whether the repair seam was told the operator acknowledged.
	 *
	 * @var array<int, bool>
	 */
	private array $acknowledged = array();

	/**
	 * Stand up the WordPress functions both handlers reach.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Admin\IdentityResolutionPage' );

		$this->outcome      = array();
		$this->acknowledged = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		// The real capability gate, opened by stubbing what it consults, for
		// the reason `IdentityMergeRequestTest` records at length: an alias
		// mock on `Capabilities` passes under `--filter` and breaks the suite.
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
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );

		// Both handlers end in `exit`, so the redirect is where they are
		// caught. Without this the outcome cannot be read at all.
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
	 * A page whose repair seam refuses for want of an acknowledgement.
	 *
	 * The refusal is the SERVICE's, reproduced with its real error code,
	 * because the code is what the screen dispatches on.
	 *
	 * @return IdentityResolutionPage
	 */
	private function page(): IdentityResolutionPage {
		$repair = Mockery::mock( IdentityRepair::class );

		$refusal = static function () {
			return new WP_Error(
				'ffc_identity_repair_unacknowledged',
				'The value you confirmed already belongs to account 6247.',
				array( 'account' => 6247 )
			);
		};

		$repair->shouldReceive( 'repair' )->andReturnUsing(
			function ( $subject, $confirmed, $actor, $field, $scope, $acknowledged = false ) use ( $refusal ) {
				$this->acknowledged[] = (bool) $acknowledged;

				return $refusal();
			}
		);

		$repair->shouldReceive( 'consolidate' )->andReturnUsing(
			function ( $wrong, $target, $actor, $field, $acknowledged = false ) use ( $refusal ) {
				$this->acknowledged[] = (bool) $acknowledged;

				return $refusal();
			}
		);

		return new class( $repair ) extends IdentityResolutionPage {

			/** @var IdentityRepair */
			private $double;

			/**
			 * @param IdentityRepair $double Stand-in for the repair service.
			 */
			public function __construct( $double ) {
				$this->double = $double;
			}

			/**
			 * @return IdentityRepair
			 */
			protected function repairs(): IdentityRepair {
				return $this->double;
			}
		};
	}

	/**
	 * Drive one handler and return once its redirect fires.
	 *
	 * @param IdentityResolutionPage $page   The page under test.
	 * @param string                 $method Which handler.
	 * @return void
	 */
	private function drive( IdentityResolutionPage $page, string $method ): void {
		try {
			$page->{$method}();
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'ffc_test_redirected', $e->getMessage() );
		}
	}

	/**
	 * THE DEFECT: the repair's refusal must name its code and its finding.
	 *
	 * Without both, the checkbox that resolves this refusal is never rendered
	 * -- it is gated on exactly these two keys -- and the operator is told to
	 * confirm with nothing to confirm on.
	 */
	public function test_the_repair_refusal_carries_the_code_and_the_finding(): void {
		$_POST = array(
			'ffc_subject' => 'abc123def456',
			'ffc_rf'      => '5181780',
		);

		$this->drive( $this->page(), 'handle_repair' );

		$this->assertSame( 'error', $this->outcome['type'] ?? '' );
		$this->assertSame(
			'ffc_identity_repair_unacknowledged',
			$this->outcome['code'] ?? '',
			'The screen dispatches on the code, so a refusal that omits it can never grow the box that resolves it.'
		);
		$this->assertSame(
			'abc123def456',
			$this->outcome['subject'] ?? '',
			'The finding decides which of the operator\'s forms grows the box; without it no form matches.'
		);
	}

	/**
	 * The control, and it is what makes the case above a measurement.
	 *
	 * The consolidation reached the same refusal through `report()` and carried
	 * both keys all along. If this failed too, the harness would be what is
	 * broken rather than the handler -- a distinction a single failing test
	 * cannot make about itself.
	 */
	public function test_the_consolidation_refusal_already_carried_them(): void {
		$_POST = array(
			'ffc_subject' => 'abc123def456',
			'ffc_target'  => 'fed654cba321',
		);

		$this->drive( $this->page(), 'handle_consolidate' );

		$this->assertSame( 'ffc_identity_repair_unacknowledged', $this->outcome['code'] ?? '' );
		$this->assertSame( 'abc123def456', $this->outcome['subject'] ?? '' );
	}

	/**
	 * Both verbs read the acknowledgement off the request, and default to off.
	 *
	 * A gate that defaulted to acknowledged would pass every test here while
	 * removing the deliberateness it exists for.
	 */
	public function test_an_unticked_box_reaches_the_service_as_false(): void {
		foreach ( array( 'handle_repair', 'handle_consolidate' ) as $method ) {
			$this->acknowledged = array();

			$_POST = array(
				'ffc_subject' => 'abc123def456',
				'ffc_rf'      => '5181780',
				'ffc_target'  => 'fed654cba321',
			);

			$this->drive( $this->page(), $method );

			$this->assertSame( array( false ), $this->acknowledged, $method . ' must pass the absent box through as false.' );
		}
	}

	/**
	 * A ticked box reaches the service as true, through both verbs.
	 */
	public function test_a_ticked_box_reaches_the_service_as_true(): void {
		foreach ( array( 'handle_repair', 'handle_consolidate' ) as $method ) {
			$this->acknowledged = array();

			$_POST = array(
				'ffc_subject'      => 'abc123def456',
				'ffc_rf'           => '5181780',
				'ffc_target'       => 'fed654cba321',
				'ffc_acknowledged' => '1',
			);

			$this->drive( $this->page(), $method );

			$this->assertSame( array( true ), $this->acknowledged, $method . ' must carry the ticked box to the service.' );
		}
	}

	/**
	 * ONE BUILDER, WHICH IS WHAT STOPS THE NEXT WRITER REPEATING THIS.
	 *
	 * The two writers differ in where they send the operator -- `report()` to
	 * the top, `handle_repair()` to the next finding -- and that difference is
	 * why the second built its own payload. A third handler would have copied
	 * whichever it sat next to. So the invariant is not `both carry the keys`,
	 * which the cases above check for today's two: it is that there is one
	 * place where an outcome is built at all.
	 */
	public function test_the_outcome_payload_is_built_in_one_place(): void {
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );

		$this->assertSame(
			1,
			substr_count( $page, "'type'    => 'error'," ),
			'An outcome payload built anywhere but `outcome()` is a second answer to what an outcome is.'
		);
		$this->assertSame(
			2,
			substr_count( $page, 'self::outcome(' ),
			'Both writers must go through the shared builder; a new one that does not is what this catches.'
		);
	}

	/**
	 * The repair form ships the box, so the preflight has something to reveal.
	 *
	 * Hidden and WITHOUT `required`, which is the #1117 rule: a required
	 * control inside a hidden block blocks the submit against something nobody
	 * can see. Shipping the marker instead of the attribute is what removes
	 * the window between page load and the JS that would have stripped it.
	 */
	public function test_the_repair_form_ships_the_box_hidden_and_not_required(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		// SELECTED BY THE PREFLIGHT, NOT BY THE ACTION OR BY ORDER.
		//
		// TWO forms post `REPAIR_ACTION` -- the shared pair's and this one --
		// so `strstr()` on the action finds the wrong one, which is how this
		// assertion first failed against a correct fix. Selecting by the
		// preflight says what the case is actually about, and stays right if a
		// third form is added on either side.
		$forms = array_values(
			array_filter(
				preg_split( '#(?=<form\b)#', $view ) ?: array(),
				static function ( $block ) {
					return false !== strpos( $block, 'ffc-identity-check' );
				}
			)
		);

		$this->assertCount( 1, $forms, 'Exactly one form carries the preflight; the reveal is written for that one.' );

		$form = (string) strstr( $forms[0], '</form>', true );

		$this->assertStringContainsString( 'IdentityResolutionPage::REPAIR_ACTION', $form );
		$this->assertStringContainsString( 'data-ffc-ack="ffc-ack-', $form, 'The Check button must name the box it reveals.' );
		$this->assertStringContainsString( '$ffc_identity_ack(', $form );
		$this->assertMatchesRegularExpression(
			'/\$ffc_identity_ack\(\s*\(string\) \( \$ffc_identity_row\[\'subject\'\] \?\? \'\' \),\s*true\s*\)/',
			$form,
			'The form with a preflight must ask for the box unconditionally.'
		);

		$this->assertStringContainsString(
			"data-ffc-required-off",
			$view,
			'The hidden box ships the marker rather than `required` (#1117).'
		);
	}

	/**
	 * A `hidden` box that a stylesheet still displays is not hidden.
	 *
	 * `hidden` works through a user-agent `display: none`, which any author
	 * declaration outranks -- and this component declares `display: flex`. So
	 * the rule is load-bearing rather than defensive, and its absence would
	 * show every operator an acknowledgement for a correction needing none.
	 */
	public function test_the_hidden_box_is_hidden_against_its_own_display_rule(): void {
		$css = (string) file_get_contents( __DIR__ . '/../../assets/css/ffc-admin.css' );

		$this->assertStringContainsString( '.ffc-page-identities .ffc-identity-ack {', $css );
		$this->assertMatchesRegularExpression(
			'/\.ffc-identity-ack\[hidden\]\s*\{\s*display:\s*none;/',
			$css,
			'Without this the `hidden` attribute loses to the component\'s own `display: flex`.'
		);
	}
}
