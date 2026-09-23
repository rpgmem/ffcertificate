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
use FreeFormCertificate\Maintenance\IdentityWorklist;
use RuntimeException;
use WP_Error;

/**
 * What a write does to the held list (#1397).
 *
 * THE RULE IS ONE SENTENCE AND BOTH HALVES MATTER.
 *
 * A write that worked takes its finding out of the queue; a write that was
 * refused leaves it there. Getting the first half wrong makes an operator
 * resolve the same finding twice and doubt the counter. Getting the SECOND
 * half wrong is worse and quieter: a refused correction disappears from the
 * list as though it had been handled, and the queue reaches zero with the
 * work still undone — which is the failure this whole screen is built against.
 *
 * @covers \FreeFormCertificate\Admin\IdentityResolutionPage
 */
class IdentityWorklistWiringTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Keys this run was told were resolved.
	 *
	 * @var array<int, string>
	 */
	private array $dropped = array();

	/**
	 * What the stubbed repair answers with.
	 *
	 * @var array<string, mixed>|WP_Error
	 */
	private $repair_result = array();

	/**
	 * Stand up the WordPress functions the handler reaches.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Admin\IdentityResolutionPage' );

		$this->dropped       = array();
		$this->repair_result = array( 'rows' => 3 );

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.org/wp-admin/' );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return is_array( $value ) || is_object( $value ) ? '' : trim( (string) $value );
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
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
	 * A page whose repair and worklist are both doubles.
	 *
	 * @return IdentityResolutionPage
	 */
	private function page(): IdentityResolutionPage {
		$repair = Mockery::mock( IdentityRepair::class );
		$repair->shouldReceive( 'consolidate' )->andReturnUsing( fn() => $this->repair_result );

		$worklist = Mockery::mock( IdentityWorklist::class );
		$worklist->shouldReceive( 'resolved' )->andReturnUsing(
			function ( $user_id, $key ) {
				$this->dropped[] = $user_id . ':' . $key;
			}
		);

		return new class( $repair, $worklist ) extends IdentityResolutionPage {

			/** @var IdentityRepair */
			private $repair;

			/** @var IdentityWorklist */
			private $worklist;

			/**
			 * @param IdentityRepair   $repair   Stand-in for the write.
			 * @param IdentityWorklist $worklist Stand-in for the held list.
			 */
			public function __construct( $repair, $worklist ) {
				$this->repair   = $repair;
				$this->worklist = $worklist;
			}

			/**
			 * @return IdentityRepair
			 */
			protected function repairs(): IdentityRepair {
				return $this->repair;
			}

			/**
			 * @return IdentityWorklist
			 */
			protected function worklists(): IdentityWorklist {
				return $this->worklist;
			}
		};
	}

	/**
	 * Run the handler and let the redirect end it.
	 *
	 * @return void
	 */
	private function submit(): void {
		try {
			$this->page()->handle_consolidate();
		} catch ( RuntimeException $e ) {
			if ( 'ffc_test_redirected' !== $e->getMessage() ) {
				throw $e;
			}
		}
	}

	/**
	 * A write that worked drops exactly the finding it resolved.
	 */
	public function test_a_successful_write_drops_its_finding(): void {
		$_POST = array(
			'ffc_key'     => 'mechanical|rf_hash|398',
			'ffc_subject' => 'wrongHash',
			'ffc_target'  => 'rightHash',
			'ffc_field'   => 'rf',
		);

		$this->submit();

		$this->assertSame( array( '42:mechanical|rf_hash|398' ), $this->dropped );
	}

	/**
	 * A REFUSED WRITE LEAVES THE FINDING IN THE QUEUE.
	 *
	 * The one that is quiet when it is wrong: nothing was changed, so the work
	 * is still there, and a list that forgot it would be worked to a zero that
	 * is not true.
	 */
	public function test_a_refused_write_leaves_its_finding_in_the_queue(): void {
		$this->repair_result = new WP_Error( 'ffc_identity_repair_collision', 'that value is another account\'s' );

		$_POST = array(
			'ffc_key'     => 'mechanical|rf_hash|398',
			'ffc_subject' => 'wrongHash',
			'ffc_target'  => 'rightHash',
			'ffc_field'   => 'rf',
		);

		$this->submit();

		$this->assertSame( array(), $this->dropped );
	}

	/**
	 * A post carrying no key resolves nothing, rather than guessing.
	 *
	 * The form that omits it is a form this screen has not taught yet; the
	 * write still happens and the list is merely one read out of date, which
	 * the next `Read the queue again` fixes.
	 */
	public function test_a_write_with_no_key_drops_nothing(): void {
		$_POST = array(
			'ffc_subject' => 'wrongHash',
			'ffc_target'  => 'rightHash',
			'ffc_field'   => 'rf',
		);

		$this->submit();

		$this->assertSame( array(), $this->dropped );
	}
}
