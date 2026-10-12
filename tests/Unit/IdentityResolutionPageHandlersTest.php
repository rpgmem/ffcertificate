<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityQueuePanels;
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Maintenance\IdentityAcceptance;
use FreeFormCertificate\Maintenance\IdentityAdoption;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;
use FreeFormCertificate\Maintenance\IdentityMerge;
use FreeFormCertificate\Maintenance\IdentityOrphanQuery;
use FreeFormCertificate\Maintenance\IdentityQueue;
use FreeFormCertificate\Maintenance\IdentityRecordNames;
use FreeFormCertificate\Maintenance\IdentityRelink;
use FreeFormCertificate\Maintenance\IdentityRepair;
use FreeFormCertificate\Maintenance\IdentitySplit;
use FreeFormCertificate\Maintenance\IdentityWorklist;

/**
 * The identity-resolution screen's POST handlers, driven end to end.
 *
 * `IdentityResolutionPageTest` reads most of the handlers as source; this
 * class RUNS them, through the screen's own seams, so the refusals, the
 * outcome each one leaves for the next render and the redirect are measured
 * rather than read. `wp_safe_redirect()` throws a sentinel, which is what
 * stops a handler before the `exit` that follows it.
 *
 * @covers \FreeFormCertificate\Admin\IdentityResolutionPage
 */
class IdentityResolutionPageHandlersTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Capabilities the current visitor holds.
	 *
	 * @var array<int, string>
	 */
	private array $caps = array();

	/**
	 * Transients written during the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Nonce actions verified during the test.
	 *
	 * @var array<int, string>
	 */
	private array $nonces = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_POST = array();
		$_GET  = array();

		$this->caps       = array( IdentityResolutionPage::CAPABILITY );
		$this->transients = array();
		$this->nonces     = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : '';
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return in_array( $cap, $this->caps, true );
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function ( $message ) {
				throw new \RuntimeException( 'wp_die: ' . $message );
			}
		);
		Functions\when( 'check_admin_referer' )->alias(
			function ( $action ) {
				$this->nonces[] = $action;
				return 1;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) {
				throw new IdentityPageRedirected( (string) $url );
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A page whose every write seam is a double the test hands in.
	 *
	 * @param array<string, object> $seams Seam name => double.
	 * @return IdentityResolutionPage
	 */
	private function page( array $seams = array() ): IdentityResolutionPage {
		return new class( $seams ) extends IdentityResolutionPage {

			/** @var array<string, object> */
			private array $seams;

			/**
			 * @param array<string, object> $seams Doubles by seam name.
			 */
			public function __construct( array $seams ) {
				$this->seams = $seams;
			}

			/**
			 * @param string $name Seam.
			 * @return object
			 */
			private function seam( string $name ) {
				if ( ! isset( $this->seams[ $name ] ) ) {
					throw new \LogicException( 'Seam not expected in this test: ' . $name );
				}
				return $this->seams[ $name ];
			}

			protected function worklists(): IdentityWorklist {
				// Absent, the REAL worklist is built over this page's own
				// `conflicts()` and `acceptances()` -- the chain production uses.
				return isset( $this->seams['worklists'] ) ? $this->seams['worklists'] : parent::worklists();
			}

			protected function namer(): IdentityRecordNames {
				return $this->seam( 'namer' );
			}

			protected function acceptances(): IdentityAcceptance {
				return $this->seam( 'acceptances' );
			}

			protected function repairs(): IdentityRepair {
				return $this->seam( 'repairs' );
			}

			protected function movements(): IdentityRelink {
				return $this->seam( 'movements' );
			}

			protected function separations(): IdentitySplit {
				return $this->seam( 'separations' );
			}

			protected function mergers(): IdentityMerge {
				return $this->seam( 'mergers' );
			}

			protected function adoptions(): IdentityAdoption {
				return $this->seam( 'adoptions' );
			}

			protected function orphans(): IdentityOrphanQuery {
				return $this->seam( 'orphans' );
			}

			protected function conflicts(): IdentityConflictQuery {
				return $this->seam( 'conflicts' );
			}
		};
	}

	/**
	 * Run a handler and return where it redirected.
	 *
	 * @param callable $handler The handler.
	 * @return string
	 */
	private function redirect_of( callable $handler ): string {
		try {
			$handler();
		} catch ( IdentityPageRedirected $redirect ) {
			return $redirect->url;
		}

		$this->fail( 'The handler was expected to redirect.' );
	}

	/**
	 * The outcome left for the next render.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(): array {
		$key = IdentityResolutionPage::OUTCOME_TRANSIENT . '7';
		$this->assertArrayHasKey( $key, $this->transients, 'Every handler must leave an outcome for the next render.' );

		return $this->transients[ $key ];
	}

	/**
	 * The handlers that refuse a visitor without their capability.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function handler_provider(): array {
		return array(
			'accept'      => array( 'handle_accept' ),
			'withdraw'    => array( 'handle_withdraw' ),
			'rescan'      => array( 'handle_rescan' ),
			'repair'      => array( 'handle_repair' ),
			'consolidate' => array( 'handle_consolidate' ),
			'relink'      => array( 'handle_relink' ),
			'split'       => array( 'handle_split' ),
			'merge'       => array( 'handle_merge' ),
			'adopt'       => array( 'handle_adopt' ),
		);
	}

	/**
	 * @dataProvider handler_provider
	 */
	public function test_every_handler_refuses_a_visitor_without_its_capability( string $handler ): void {
		$this->caps = array();

		try {
			$this->page()->$handler();
			$this->fail( 'A visitor holding nothing must be refused.' );
		} catch ( \RuntimeException $died ) {
			$this->assertStringStartsWith( 'wp_die: ', $died->getMessage() );
		}

		$this->assertSame( array(), $this->nonces, 'The refusal must come before the nonce is read.' );
		$this->assertSame( array(), $this->transients, 'A refused request must leave no outcome behind.' );
	}

	public function test_accept_stores_a_validated_record_and_reports_success(): void {
		$_POST = array(
			'ffc_subject' => 'abc',
			'ffc_check'   => IdentityQueue::CHECK_DIGITS,
			'ffc_field'   => 'cpf',
			'ffc_tier'    => IdentityQueue::TIER_ISOLATED,
			'ffc_reason'  => 'Retired, record kept as is.',
			'ffc_key'     => 'isolated-abc',
		);

		$acceptances = Mockery::mock( IdentityAcceptance::class );
		$acceptances->shouldReceive( 'accept' )
			->once()
			->with( IdentityQueue::CHECK_DIGITS, 'cpf', 'abc', IdentityQueue::TIER_ISOLATED, 'Retired, record kept as is.', 7 )
			->andReturn( true );

		$worklists = Mockery::mock( IdentityWorklist::class );
		$worklists->shouldReceive( 'resolved' )->once()->with( 7, 'isolated-abc' );

		$url = $this->redirect_of(
			array( $this->page( array( 'acceptances' => $acceptances, 'worklists' => $worklists ) ), 'handle_accept' )
		);

		$this->assertSame( array( IdentityResolutionPage::ACCEPT_NONCE . 'abc' ), $this->nonces );
		$this->assertSame( 'success', $this->outcome()['type'] );
		$this->assertStringContainsString( 'page=' . IdentityResolutionPage::MENU_SLUG, $url );
	}

	public function test_accept_refuses_an_unknown_check_and_tier_before_the_store(): void {
		$_POST = array(
			'ffc_subject' => 'abc',
			'ffc_check'   => 'not_a_check',
			'ffc_field'   => 'iban',
			'ffc_tier'    => 'nowhere',
		);

		$error       = new \WP_Error( 'ffc_identity_accept_invalid', 'Unknown check.' );
		$acceptances = Mockery::mock( IdentityAcceptance::class );
		$acceptances->shouldReceive( 'accept' )
			->once()
			->with( '', IdentityRepair::FIELD, 'abc', '', '', 7 )
			->andReturn( $error );

		$this->redirect_of( array( $this->page( array( 'acceptances' => $acceptances ) ), 'handle_accept' ) );

		$outcome = $this->outcome();
		$this->assertSame( 'error', $outcome['type'] );
		$this->assertSame( 'Unknown check.', $outcome['text'] );
		$this->assertSame( 'ffc_identity_accept_invalid', $outcome['code'] );
		$this->assertSame( 'abc', $outcome['subject'], 'The finding travels with a refusal so the right form can grow its box.' );
	}

	public function test_withdraw_reads_the_queue_again_on_success(): void {
		$_POST = array(
			'ffc_subject' => 'h1',
			'ffc_check'   => IdentityQueue::CHECK_SHARED,
			'ffc_field'   => 'rf',
		);

		$acceptances = Mockery::mock( IdentityAcceptance::class );
		$acceptances->shouldReceive( 'withdraw' )
			->once()
			->with( IdentityQueue::CHECK_SHARED, 'rf', 'h1', 7 )
			->andReturn( true );

		$worklists = Mockery::mock( IdentityWorklist::class );
		$worklists->shouldReceive( 'take' )->once()->with( 7, IdentityResolutionPage::LIMIT )->andReturn( array() );

		$this->redirect_of( array( $this->page( array( 'acceptances' => $acceptances, 'worklists' => $worklists ) ), 'handle_withdraw' ) );

		$this->assertSame( array( IdentityResolutionPage::WITHDRAW_NONCE . 'h1' ), $this->nonces );
		$this->assertSame( 'success', $this->outcome()['type'] );
	}

	public function test_withdraw_leaves_the_queue_alone_when_refused(): void {
		$_POST = array( 'ffc_subject' => 'h1' );

		$acceptances = Mockery::mock( IdentityAcceptance::class );
		$acceptances->shouldReceive( 'withdraw' )->once()->andReturn( new \WP_Error( 'ffc_identity_not_accepted', 'Nothing to withdraw.' ) );

		$worklists = Mockery::mock( IdentityWorklist::class );
		$worklists->shouldNotReceive( 'take' );

		$this->redirect_of( array( $this->page( array( 'acceptances' => $acceptances, 'worklists' => $worklists ) ), 'handle_withdraw' ) );

		$this->assertSame( 'Nothing to withdraw.', $this->outcome()['text'] );
	}

	public function test_rescan_takes_the_list_again_and_says_so(): void {
		$worklists = Mockery::mock( IdentityWorklist::class );
		$worklists->shouldReceive( 'take' )->once()->with( 7, IdentityResolutionPage::LIMIT )->andReturn( array() );

		$this->redirect_of( array( $this->page( array( 'worklists' => $worklists ) ), 'handle_rescan' ) );

		$this->assertSame( array( IdentityResolutionPage::RESCAN_NONCE ), $this->nonces );
		$this->assertSame(
			array(
				'type' => 'success',
				'text' => 'The queue was read again.',
			),
			$this->outcome()
		);
	}

	public function test_repair_passes_scope_and_acknowledgement_and_lands_on_the_next_finding(): void {
		$_POST = array(
			'ffc_subject'       => 'h9',
			'ffc_rf'            => '5181780',
			'ffc_field'         => 'rf',
			'ffc_account_scope' => '42',
			'ffc_acknowledged'  => '1',
			'ffc_next'          => 'isolated-h10',
			'ffc_tier'          => IdentityQueue::TIER_ISOLATED,
		);

		$repairs = Mockery::mock( IdentityRepair::class );
		$repairs->shouldReceive( 'repair' )
			->once()
			->with( 'h9', '5181780', 7, 'rf', 42, true )
			->andReturn( array( 'rows' => 2 ) );

		$url = $this->redirect_of( array( $this->page( array( 'repairs' => $repairs ) ), 'handle_repair' ) );

		$this->assertSame( array( IdentityResolutionPage::REPAIR_NONCE . 'h9' ), $this->nonces );
		$this->assertSame( 'success', $this->outcome()['type'] );
		$this->assertStringContainsString(
			http_build_query( array( IdentityQueuePanels::ARG_AT => array( IdentityQueue::TIER_ISOLATED => 'isolated-h10' ) ) ),
			$url,
			'A correction lands on the finding the form named as next.'
		);
	}

	public function test_repair_with_an_unknown_tier_lands_at_the_top_and_carries_the_refusal(): void {
		$_POST = array(
			'ffc_subject' => 'h9',
			'ffc_rf'      => '1',
			'ffc_next'    => 'isolated-h10',
			'ffc_tier'    => 'elsewhere',
		);

		$repairs = Mockery::mock( IdentityRepair::class );
		$repairs->shouldReceive( 'repair' )
			->once()
			->with( 'h9', '1', 7, IdentityRepair::FIELD, 0, false )
			->andReturn( new \WP_Error( 'ffc_identity_repair_taken', 'Already another account\'s.' ) );

		$url = $this->redirect_of( array( $this->page( array( 'repairs' => $repairs ) ), 'handle_repair' ) );

		$this->assertStringNotContainsString( IdentityQueuePanels::ARG_AT, $url );
		$outcome = $this->outcome();
		$this->assertSame( 'error', $outcome['type'] );
		$this->assertSame( 'ffc_identity_repair_taken', $outcome['code'] );
		$this->assertSame( 'h9', $outcome['subject'] );
	}

	public function test_consolidate_passes_both_hashes_and_the_acknowledgement(): void {
		$_POST = array(
			'ffc_subject'      => 'wrong',
			'ffc_target'       => 'sound',
			'ffc_field'        => 'cpf',
			'ffc_acknowledged' => 'yes',
			'ffc_key'          => 'mechanical-wrong',
		);

		$repairs = Mockery::mock( IdentityRepair::class );
		$repairs->shouldReceive( 'consolidate' )
			->once()
			->with( 'wrong', 'sound', 7, 'cpf', true )
			->andReturn( array() );

		$worklists = Mockery::mock( IdentityWorklist::class );
		$worklists->shouldReceive( 'resolved' )->once()->with( 7, 'mechanical-wrong' );

		$this->redirect_of( array( $this->page( array( 'repairs' => $repairs, 'worklists' => $worklists ) ), 'handle_consolidate' ) );

		$this->assertSame( array( IdentityResolutionPage::CONSOLIDATE_NONCE . 'wrong' ), $this->nonces );
		$this->assertSame( 'success', $this->outcome()['type'] );
	}

	public function test_relink_refuses_an_unacknowledged_mailbox_move(): void {
		$_POST = array(
			'ffc_subject' => 'h1',
			'ffc_key'     => IdentityQueue::TIER_MAILBOX . IdentityQueue::KEY_SEPARATOR . 'h1',
			'ffc_account' => '9',
		);

		$movements = Mockery::mock( IdentityRelink::class );
		$movements->shouldNotReceive( 'relink' );

		$this->redirect_of( array( $this->page( array( 'movements' => $movements ) ), 'handle_relink' ) );

		$outcome = $this->outcome();
		$this->assertSame( 'ffc_identity_relink_unacknowledged', $outcome['code'] );
	}

	public function test_relink_moves_an_acknowledged_mailbox_finding(): void {
		$_POST = array(
			'ffc_subject'      => 'h1',
			'ffc_key'          => IdentityQueue::TIER_MAILBOX . IdentityQueue::KEY_SEPARATOR . 'h1',
			'ffc_account'      => '9',
			'ffc_acknowledged' => '1',
		);

		$movements = Mockery::mock( IdentityRelink::class );
		$movements->shouldReceive( 'relink' )->once()->with( 'h1', 9, 7, IdentityRepair::FIELD )->andReturn( array() );

		$worklists = Mockery::mock( IdentityWorklist::class );
		$worklists->shouldReceive( 'resolved' )->once();

		$this->redirect_of( array( $this->page( array( 'movements' => $movements, 'worklists' => $worklists ) ), 'handle_relink' ) );

		$this->assertSame( 'success', $this->outcome()['type'] );
	}

	public function test_relink_from_another_panel_needs_no_acknowledgement(): void {
		$_POST = array(
			'ffc_subject' => 'h1',
			'ffc_key'     => IdentityQueue::TIER_SHARED . IdentityQueue::KEY_SEPARATOR . 'h1',
			'ffc_account' => '3',
			'ffc_field'   => 'cpf',
		);

		$movements = Mockery::mock( IdentityRelink::class );
		$movements->shouldReceive( 'relink' )
			->once()
			->with( 'h1', 3, 7, 'cpf' )
			->andReturn( new \WP_Error( 'ffc_identity_relink_no_account', 'No such account.' ) );

		$this->redirect_of( array( $this->page( array( 'movements' => $movements ) ), 'handle_relink' ) );

		$this->assertSame( array( IdentityResolutionPage::RELINK_NONCE . 'h1' ), $this->nonces );
		$this->assertSame( 'No such account.', $this->outcome()['text'] );
	}

	public function test_split_is_gated_on_its_own_capability(): void {
		$this->caps = array( IdentityResolutionPage::SPLIT_CAPABILITY );
		$_POST      = array(
			'ffc_subject' => 'h2',
			'ffc_email'   => 'new@example.test',
		);

		$separations = Mockery::mock( IdentitySplit::class );
		$separations->shouldReceive( 'split' )->once()->with( 'h2', 'new@example.test', 7, IdentityRepair::FIELD )->andReturn( array( 'user_id' => 11 ) );

		$this->redirect_of( array( $this->page( array( 'separations' => $separations ) ), 'handle_split' ) );

		$this->assertSame( array( IdentityResolutionPage::SPLIT_NONCE . 'h2' ), $this->nonces );
		$this->assertSame( 'success', $this->outcome()['type'] );
	}

	public function test_split_refuses_the_screen_capability_alone(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: ' );

		$this->page()->handle_split();
	}

	public function test_merge_refuses_without_the_acknowledgement(): void {
		$this->caps = array( IdentityResolutionPage::MERGE_CAPABILITY );
		$_POST      = array(
			'ffc_subject' => 'pair',
			'ffc_keep'    => '1',
			'ffc_a'       => '1',
			'ffc_b'       => '2',
		);

		$mergers = Mockery::mock( IdentityMerge::class );
		$mergers->shouldNotReceive( 'merge' );

		$this->redirect_of( array( $this->page( array( 'mergers' => $mergers ) ), 'handle_merge' ) );

		$this->assertSame( array( IdentityResolutionPage::MERGE_NONCE . 'pair' ), $this->nonces );
		$this->assertSame( 'ffc_identity_merge_unacknowledged', $this->outcome()['code'] );
	}

	public function test_merge_refuses_a_survivor_outside_the_pair(): void {
		$this->caps = array( IdentityResolutionPage::MERGE_CAPABILITY );
		$_POST      = array(
			'ffc_subject' => 'pair',
			'ffc_ack'     => '1',
			'ffc_keep'    => '3',
			'ffc_a'       => '1',
			'ffc_b'       => '2',
		);

		$mergers = Mockery::mock( IdentityMerge::class );
		$mergers->shouldNotReceive( 'merge' );

		$this->redirect_of( array( $this->page( array( 'mergers' => $mergers ) ), 'handle_merge' ) );

		$this->assertSame( 'ffc_identity_merge_not_in_pair', $this->outcome()['code'] );
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public function survivor_provider(): array {
		return array(
			'keep the first'  => array( '1', 2 ),
			'keep the second' => array( '2', 1 ),
		);
	}

	/**
	 * @dataProvider survivor_provider
	 */
	public function test_merge_folds_the_other_login_into_the_survivor( string $keep, int $folded ): void {
		$this->caps = array( IdentityResolutionPage::MERGE_CAPABILITY );
		$_POST      = array(
			'ffc_subject' => 'pair',
			'ffc_ack'     => '1',
			'ffc_keep'    => $keep,
			'ffc_a'       => '1',
			'ffc_b'       => '2',
		);

		$mergers = Mockery::mock( IdentityMerge::class );
		$mergers->shouldReceive( 'merge' )->once()->with( (int) $keep, $folded, 7 )->andReturn( array() );

		$this->redirect_of( array( $this->page( array( 'mergers' => $mergers ) ), 'handle_merge' ) );

		$this->assertSame( 'success', $this->outcome()['type'] );
	}

	public function test_adopt_passes_what_the_operator_typed(): void {
		$this->caps = array( IdentityResolutionPage::SPLIT_CAPABILITY );
		$_POST      = array(
			'ffc_subject' => 'orph',
			'ffc_cpf'     => '51817842080',
			'ffc_rf'      => '5181780',
			'ffc_email'   => 'someone@example.test',
		);

		$adoptions = Mockery::mock( IdentityAdoption::class );
		$adoptions->shouldReceive( 'adopt' )
			->once()
			->with( '51817842080', '5181780', 'someone@example.test', 7 )
			->andReturn( new \WP_Error( 'ffc_identity_adopt_invalid', 'Invalid CPF.' ) );

		$this->redirect_of( array( $this->page( array( 'adoptions' => $adoptions ) ), 'handle_adopt' ) );

		$this->assertSame( array( IdentityResolutionPage::ADOPT_NONCE . 'orph' ), $this->nonces );
		$this->assertSame( 'Invalid CPF.', $this->outcome()['text'] );
	}

	public function test_resolved_since_is_zero_for_a_queue_never_taken(): void {
		Functions\expect( 'wp_date' )->never();

		$this->assertSame( 0, $this->page()->resolved_since( 0 ) );
	}

	public function test_resolved_since_is_zero_when_the_bound_cannot_be_formatted(): void {
		Functions\when( 'wp_date' )->justReturn( false );

		$this->assertSame( 0, $this->page()->resolved_since( 1700000000 ) );
	}

	public function test_resolved_since_counts_each_verb_for_this_operator_from_the_bound(): void {
		global $wpdb;
		$previous = $wpdb;

		Functions\when( 'wp_date' )->justReturn( '2026-10-01 09:00:00' );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		$prepared = array();
		$wpdb     = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) use ( &$prepared ) {
				$prepared[] = vsprintf( str_replace( array( '%i', '%d' ), array( '%s', '%s' ), $sql ), $args );
				return end( $prepared );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->times( count( IdentityResolutionPage::RESOLVED_ACTIONS ) )->andReturn( '2' );

		try {
			$done = $this->page()->resolved_since( 1700000000 );
		} finally {
			$wpdb = $previous;
		}

		$this->assertSame( 2 * count( IdentityResolutionPage::RESOLVED_ACTIONS ), $done );
		foreach ( IdentityResolutionPage::RESOLVED_ACTIONS as $action ) {
			$this->assertContains( 'action = ' . $action, $prepared, 'Each resolving verb is counted on its own.' );
		}
		$this->assertContains( 'user_id = 7', $prepared, 'Only this operator\'s work shrinks this operator\'s list.' );
		$this->assertContains( 'created_at >= 2026-10-01 09:00:00', $prepared );
	}

	public function test_queue_records_what_the_held_list_says_about_itself(): void {
		$worklists = Mockery::mock( IdentityWorklist::class );
		$worklists->shouldReceive( 'get' )->once()->with( 7, IdentityResolutionPage::LIMIT )->andReturn(
			array(
				IdentityWorklist::ITEMS     => array( array( 'k' => 1 ) ),
				IdentityWorklist::COVERAGE  => array( 'rf_hash' => array( 'stores' => 2 ) ),
				IdentityWorklist::TRUNCATED => array( IdentityQueue::CHECK_DIGITS ),
				IdentityWorklist::TAKEN_AT  => 1234,
			)
		);

		$page = $this->page( array( 'worklists' => $worklists ) );

		$this->assertSame( array( array( 'k' => 1 ) ), $page->queue() );
		$this->assertSame( array( 'rf_hash' => array( 'stores' => 2 ) ), $page->coverage() );
		$this->assertSame( array( IdentityQueue::CHECK_DIGITS ), $page->truncated() );
		$this->assertSame( 1234, $page->taken_at() );
	}

	/**
	 * Stub what the view prints through, rendering markup verbatim.
	 */
	private function stub_view_output(): void {
		foreach ( array( 'esc_html', 'esc_attr', 'esc_url', 'esc_textarea', 'wp_kses_post' ) as $fn ) {
			Functions\when( $fn )->returnArg( 1 );
		}
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'esc_html_e' )->echoArg( 1 );
		Functions\when( 'esc_attr_e' )->echoArg( 1 );
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				return 1 === (int) $number ? $single : $plural;
			}
		);
		Functions\when( 'number_format_i18n' )->alias(
			static function ( $number ) {
				return (string) $number;
			}
		);
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_nonce_url' )->alias(
			static function ( $url, $action ) {
				return $url . '&_wpnonce=' . $action;
			}
		);
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message ) {
				echo '<div class="notice">' . $message . '</div>';
			}
		);
		Functions\when( 'wp_sprintf_l' )->alias(
			static function ( $pattern, $list ) {
				return implode( ', ', (array) $list );
			}
		);
	}

	/**
	 * A worklist that holds the given list.
	 *
	 * @param array<int, array<string, mixed>> $items What the list holds.
	 * @return IdentityWorklist
	 */
	private function held( array $items ): IdentityWorklist {
		$worklists = Mockery::mock( IdentityWorklist::class );
		$worklists->shouldReceive( 'get' )->andReturn(
			array(
				IdentityWorklist::ITEMS     => $items,
				IdentityWorklist::COVERAGE  => array(),
				IdentityWorklist::TRUNCATED => array(),
				IdentityWorklist::TAKEN_AT  => 0,
			)
		);

		return $worklists;
	}

	public function test_render_page_prints_the_last_outcome_once_and_hides_orphans_without_the_split_capability(): void {
		$this->stub_view_output();
		$this->transients[ IdentityResolutionPage::OUTCOME_TRANSIENT . '7' ] = array(
			'type' => 'success',
			'text' => 'Corrected-outcome-sentence',
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );

		$orphans = Mockery::mock( IdentityOrphanQuery::class );
		$orphans->shouldNotReceive( 'orphans' );
		$orphans->shouldNotReceive( 'capped' );

		$accepted = Mockery::mock( IdentityAcceptance::class );
		$accepted->shouldReceive( 'all' )->once()->andReturn( array() );

		$page = $this->page(
			array(
				'worklists'   => $this->held( array() ),
				'orphans'     => $orphans,
				'acceptances' => $accepted,
			)
		);

		ob_start();
		try {
			$page->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertStringContainsString( 'ffc-page-identities', $html );
		$this->assertStringContainsString( 'Corrected-outcome-sentence', $html, 'The outcome a handler left is shown on the next render.' );
		$this->assertArrayNotHasKey( IdentityResolutionPage::OUTCOME_TRANSIENT . '7', $this->transients, 'The outcome is read once.' );
	}

	/**
	 * Render one check-digit finding through the real worklist and tiering.
	 *
	 * @param array<int, string> $caps Capabilities the operator holds.
	 * @return string The page.
	 */
	private function render_one_finding( array $caps ): string {
		$this->stub_view_output();
		$this->caps = $caps;
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_option' )->justReturn( array( 'validate_rf_check_digit' => 1 ) );
		Functions\when( 'wp_date' )->justReturn( '2026-10-01' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_userdata' )->justReturn( false );

		$query = Mockery::mock( IdentityConflictQuery::class );
		$query->shouldReceive( 'multiple_identities' )->andReturn( array() );
		$query->shouldReceive( 'shared_identities' )->andReturn( array() );
		$query->shouldReceive( 'check_digit_failures_of_both' )->andReturn(
			array(
				array(
					IdentityConflictQuery::COLUMN_RELATED  => '7',
					IdentityConflictQuery::COLUMN_ROW_IDS  => 'submissions:4,9',
					IdentityConflictQuery::ALIAS_ROW_COUNT => 2,
				),
			)
		);
		$query->shouldReceive( 'account_facts' )->andReturn( array() );
		$query->shouldReceive( 'scan_coverage' )->andReturn(
			array(
				'rf_hash' => array(
					'stores'     => 3,
					'examined'   => 1,
					'unreadable' => 0,
				),
			)
		);

		$accepted = Mockery::mock( IdentityAcceptance::class );
		$accepted->shouldReceive( 'all' )->andReturn( array() );
		$accepted->shouldReceive( 'accepted_hashes' )->andReturn( array() );
		$accepted->shouldReceive( 'any_accepted' )->andReturn( false );
		$accepted->shouldReceive( 'accepted_tier' )->andReturn( null );
		$accepted->shouldReceive( 'record' )->andReturn( null );

		$namer = Mockery::mock( IdentityRecordNames::class );
		$namer->shouldReceive( 'for_hash' )->andReturn(
			array(
				'names'    => array( 'Maria Example' ),
				'capped'   => false,
				'readable' => true,
			)
		);

		$orphans = Mockery::mock( IdentityOrphanQuery::class );
		$orphans->shouldReceive( 'orphans' )->andReturn( array() );
		$orphans->shouldReceive( 'capped' )->andReturn( false );

		$page = $this->page(
			array(
				'conflicts'   => $query,
				'acceptances' => $accepted,
				'namer'       => $namer,
				'orphans'     => $orphans,
			)
		);

		// The resolved-since counter reads the activity log.
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);
		global $wpdb;
		$previous     = $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

		ob_start();
		try {
			$page->render_page();
		} finally {
			$html = (string) ob_get_clean();
			$wpdb = $previous;
		}

		return $html;
	}

	public function test_render_page_offers_the_export_to_a_holder_of_its_capability(): void {
		$html = $this->render_one_finding(
			array(
				IdentityResolutionPage::CAPABILITY,
				IdentityResolutionPage::SPLIT_CAPABILITY,
				IdentityResolutionPage::MERGE_CAPABILITY,
				'ffc_manage_settings_dangerzone',
			)
		);

		$this->assertStringContainsString( 'ffc_submission_audit=export', $html, 'A holder of the danger-zone capability is offered the CSV.' );
		$this->assertStringContainsString( 'Maria Example', $html, 'The drawn finding names who it is about.' );
	}

	public function test_render_page_withholds_the_export_from_an_operator_without_its_capability(): void {
		$html = $this->render_one_finding( array( IdentityResolutionPage::CAPABILITY ) );

		$this->assertStringContainsString( 'ffc-identity-counts', $html, 'The counter strip renders for a non-empty queue.' );
		$this->assertStringNotContainsString( 'ffc_submission_audit', $html, 'An offered control that answers wp_die is worse than none.' );
	}

	public function test_the_cursors_and_listed_tiers_are_read_from_the_request_through_an_allowlist(): void {
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : '';
			}
		);
		$_GET = array(
			IdentityQueuePanels::ARG_AT   => array(
				IdentityQueue::TIER_SHARED => ' shared-h1 ',
				'unknown'                  => 'x',
				IdentityQueue::TIER_DECISION => array( 'not scalar' ),
			),
			IdentityQueuePanels::ARG_LIST => IdentityQueue::TIER_MAILBOX . ',bogus,' . IdentityQueue::TIER_ISOLATED,
		);

		$ref     = new \ReflectionClass( IdentityResolutionPage::class );
		$cursors = $ref->getMethod( 'cursors' );
		$cursors->setAccessible( true );
		$listed = $ref->getMethod( 'listed' );
		$listed->setAccessible( true );

		$this->assertSame( array( IdentityQueue::TIER_SHARED => 'shared-h1' ), $cursors->invoke( null ) );
		$this->assertSame( array( IdentityQueue::TIER_ISOLATED, IdentityQueue::TIER_MAILBOX ), $listed->invoke( null ) );

		$_GET = array();
		$this->assertSame( array(), $listed->invoke( null ), 'No list argument lists nothing.' );
	}

	public function test_the_default_seams_build_their_services(): void {
		$page = new IdentityResolutionPage();
		$ref  = new \ReflectionClass( IdentityResolutionPage::class );

		$expected = array(
			'conflicts'   => IdentityConflictQuery::class,
			'acceptances' => IdentityAcceptance::class,
			'repairs'     => IdentityRepair::class,
			'mergers'     => IdentityMerge::class,
			'separations' => IdentitySplit::class,
			'movements'   => IdentityRelink::class,
			'orphans'     => IdentityOrphanQuery::class,
			'adoptions'   => IdentityAdoption::class,
			'namer'       => IdentityRecordNames::class,
		);

		foreach ( $expected as $method => $class ) {
			$seam = $ref->getMethod( $method );
			$seam->setAccessible( true );
			$this->assertInstanceOf( $class, $seam->invoke( $page ), $method . '() must build its own service.' );
		}
	}
}

/**
 * Thrown where `wp_safe_redirect()` would send the browser away.
 *
 * Carries the URL so a test can read where the handler pointed.
 */
class IdentityPageRedirected extends \RuntimeException {

	/**
	 * @var string
	 */
	public string $url;

	/**
	 * @param string $url Redirect target.
	 */
	public function __construct( string $url ) {
		parent::__construct( 'redirected' );
		$this->url = $url;
	}
}
