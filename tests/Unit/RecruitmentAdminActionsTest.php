<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdminActions;

/**
 * Characterization tests for RecruitmentAdminActions::dispatch() — the
 * `?action=…` row-action dispatcher extracted from RecruitmentAdminPage.
 * Each branch validates a nonce, mutates through a repository/service, then
 * redirects back to its canonical tab (and exits). We stub wp_safe_redirect
 * to throw so the trailing `exit;` is never reached, letting us assert both
 * the mutation and the redirect target.
 *
 * The repositories and RecruitmentDeleteService are the real ones, over a
 * `$wpdb` double that answers the reads from the rows each test stores and
 * records every delete; an unexpected query fails the test. The activity
 * log stays off because `get_option()` returns no settings, so the logger
 * call in the delete service writes nothing.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdminActions
 */
class RecruitmentAdminActionsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/**
	 * Prepared statements by the token prepare() returned: [sql, args].
	 *
	 * @var array<string, array{0: string, 1: array<int, mixed>}>
	 */
	private array $prepared = array();

	/** @var array<int, int> Classification rows referencing each reason id. */
	private array $reason_refs = array();

	/** @var array<int, object> Candidate rows by id. */
	private array $candidates = array();

	/**
	 * Notice rows by id, as the reader resolves them.
	 *
	 * @var array<int, object>
	 */
	private array $notices = array();

	/**
	 * Transaction statements run, in order.
	 *
	 * @var list<string>
	 */
	private array $statements = array();

	/** @var array<int, int> Classification count per candidate id. */
	private array $candidate_classifications = array();

	/** @var array<int, object> Adjutancy rows by id. */
	private array $adjutancies = array();

	/** @var array<int, list<int>> Attached notice ids per adjutancy id. */
	private array $adjutancy_notices = array();

	/** @var array<int, int> Classification count per adjutancy id. */
	private array $adjutancy_classifications = array();

	/**
	 * Every `$wpdb->delete()`, as [table, where].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $deletes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) $v );
		// dispatch() now gates every write on the manage cap (via Utils).
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static fn( $args ) => 'admin.php?' . http_build_query( (array) $args )
		);
		// Make the terminal redirect observable without killing the process:
		// throw a marker exception carrying the target URL, skipping `exit;`.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) {
				throw new \RuntimeException( 'REDIRECT:' . $url );
			}
		);
		// No settings: the activity log is disabled, so the delete service's
		// logger call writes nothing whatever ran before this test.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->prepared                  = array();
		$this->reason_refs               = array();
		$this->candidates                = array();
		$this->notices                   = array();
		$this->statements                = array();
		$this->candidate_classifications = array();
		$this->adjutancies               = array();
		$this->adjutancy_notices         = array();
		$this->adjutancy_classifications = array();
		$this->deletes                   = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$token                    = 'PREPARED#' . count( $this->prepared );
				$this->prepared[ $token ] = array( (string) $sql, $args );
				return $token;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( fn( $q ) => $this->answer_var( (string) $q ) );
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $q ) {
				$this->statements[] = (string) $q;
				return 1;
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn( $q ) => $this->answer_row( (string) $q ) );
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing( fn( $q ) => $this->answer_col( (string) $q ) );
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->deletes[] = array( (string) $table, (array) $where );
				return 1;
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		unset( $_GET['notice_id'], $_GET['adjutancy_id'], $_GET['reason_id'], $_GET['candidate_id'] );
		parent::tearDown();
	}

	/**
	 * The statement and bound arguments behind a prepare() token.
	 *
	 * @param string $token Token prepare() returned.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement( string $token ): array {
		if ( ! isset( $this->prepared[ $token ] ) ) {
			$this->fail( 'Query run without prepare(): ' . $token );
		}
		return $this->prepared[ $token ];
	}

	private function answer_var( string $token ) {
		[ $sql, $args ] = $this->statement( $token );
		if ( false !== strpos( $sql, 'INNER JOIN %i cl ON cl.id = c.classification_id' ) ) {
			return '0';
		}
		$table          = (string) $args[0];
		$id             = (int) $args[1];
		if ( 'SELECT COUNT(*) FROM %i WHERE preview_reason_id = %d' === $sql && 'wp_ffc_recruitment_classification' === $table ) {
			return (string) ( $this->reason_refs[ $id ] ?? 0 );
		}
		if ( 'SELECT COUNT(*) FROM %i WHERE candidate_id = %d' === $sql && 'wp_ffc_recruitment_classification' === $table ) {
			return (string) ( $this->candidate_classifications[ $id ] ?? 0 );
		}
		if ( 'SELECT COUNT(*) FROM %i WHERE adjutancy_id = %d' === $sql && 'wp_ffc_recruitment_classification' === $table ) {
			return (string) ( $this->adjutancy_classifications[ $id ] ?? 0 );
		}
		$this->fail( 'Unexpected get_var(): ' . $sql );
	}

	private function answer_row( string $token ) {
		[ $sql, $args ] = $this->statement( $token );
		$table          = (string) $args[0];
		$id             = (int) $args[1];
		if ( 'SELECT * FROM %i WHERE id = %d' === $sql && 'wp_ffc_recruitment_candidate' === $table ) {
			return $this->candidates[ $id ] ?? null;
		}
		if ( 'SELECT * FROM %i WHERE id = %d' === $sql && 'wp_ffc_recruitment_notice' === $table ) {
			return $this->notices[ $id ] ?? null;
		}
		if ( 'SELECT * FROM %i WHERE id = %d' === $sql && 'wp_ffc_recruitment_adjutancy' === $table ) {
			return $this->adjutancies[ $id ] ?? null;
		}
		$this->fail( 'Unexpected get_row(): ' . $sql . ' on ' . $table );
	}

	private function answer_col( string $token ) {
		[ $sql, $args ] = $this->statement( $token );
		if ( 'SELECT notice_id FROM %i WHERE adjutancy_id = %d' === $sql && 'wp_ffc_recruitment_notice_adjutancy' === $args[0] ) {
			return array_map( 'strval', $this->adjutancy_notices[ (int) $args[1] ] ?? array() );
		}
		$this->fail( 'Unexpected get_col(): ' . $sql );
	}

	/** Run dispatch() and return the redirect URL captured from the marker exception. */
	private function dispatchCapture( string $action ): string {
		try {
			RecruitmentAdminActions::dispatch( $action );
		} catch ( \RuntimeException $e ) {
			$msg = $e->getMessage();
			if ( 0 === strpos( $msg, 'REDIRECT:' ) ) {
				return substr( $msg, strlen( 'REDIRECT:' ) );
			}
			throw $e;
		}
		return '';
	}

	public function test_delete_notice_deletes_a_draft_with_its_rows_and_redirects(): void {
		$_GET['notice_id'] = '42';
		$this->notices[42] = (object) array(
			'id'     => '42',
			'code'   => 'ED-42',
			'status' => 'draft',
		);

		$url = $this->dispatchCapture( 'delete-notice' );

		$this->assertSame(
			array(
				array( 'wp_ffc_recruitment_classification', array( 'notice_id' => 42 ) ),
				array( 'wp_ffc_recruitment_notice_adjutancy', array( 'notice_id' => 42 ) ),
				array( 'wp_ffc_recruitment_notice', array( 'id' => 42 ) ),
			),
			$this->deletes
		);
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->statements, 'One transaction (#2245).' );
		$this->assertStringContainsString( 'tab=notices', $url );
		$this->assertStringContainsString( 'page=ffc-recruitment', $url );
	}

	/**
	 * @dataProvider provider_published_statuses
	 */
	public function test_delete_notice_refuses_a_published_notice( string $status ): void {
		$_GET['notice_id'] = '42';
		$this->notices[42] = (object) array(
			'id'     => '42',
			'code'   => 'ED-42',
			'status' => $status,
		);

		$url = $this->dispatchCapture( 'delete-notice' );

		$this->assertSame( array(), $this->deletes, 'Only a draft is deleted (#2245).' );
		$this->assertSame( array(), $this->statements );
		$this->assertStringContainsString( 'tab=notices', $url );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provider_published_statuses(): array {
		return array(
			'preliminary' => array( 'preliminary' ),
			'definitive'  => array( 'definitive' ),
			'closed'      => array( 'closed' ),
		);
	}

	public function test_dispatch_is_noop_without_manage_cap(): void {
		// 3-state: a read-only viewer (no ffc_manage_recruitment) must not be
		// able to trigger a destructive dispatch action via a crafted URL.
		Functions\when( 'current_user_can' )->justReturn( false );
		$_GET['notice_id'] = '42';

		$url = $this->dispatchCapture( 'delete-notice' );

		$this->assertSame( array(), $this->deletes, 'Nothing is deleted without the cap.' );
		$this->assertSame( '', $url );
	}

	public function test_delete_notice_skips_delete_when_id_is_zero_but_still_redirects(): void {
		$_GET['notice_id'] = '0';

		$url = $this->dispatchCapture( 'delete-notice' );

		$this->assertSame( array(), $this->deletes );
		$this->assertStringContainsString( 'tab=notices', $url );
	}

	public function test_delete_adjutancy_delegates_to_delete_service_and_redirects(): void {
		$_GET['adjutancy_id'] = '7';
		// An unreferenced adjutancy, so the delete service's gate lets it go.
		$this->adjutancies[7] = (object) array(
			'id'   => 7,
			'slug' => 'mat',
		);

		$url = $this->dispatchCapture( 'delete-adjutancy' );

		$this->assertSame(
			array( array( 'wp_ffc_recruitment_adjutancy', array( 'id' => 7 ) ) ),
			$this->deletes
		);
		$this->assertStringContainsString( 'tab=adjutancies', $url );
		$this->assertStringContainsString( 'ffc_msg=adjutancy-deleted', $url );
	}

	public function test_delete_adjutancy_in_use_redirects_with_the_reason(): void {
		$_GET['adjutancy_id']            = '7';
		$this->adjutancies[7]            = (object) array(
			'id'   => 7,
			'slug' => 'mat',
		);
		$this->adjutancy_classifications[7] = 2;

		$url = $this->dispatchCapture( 'delete-adjutancy' );

		$this->assertSame( array(), $this->deletes, 'An adjutancy in use is not deleted.' );
		$this->assertStringContainsString( 'ffc_msg=adjutancy-in-use', $url );
	}

	public function test_delete_reason_deletes_only_when_unreferenced(): void {
		$_GET['reason_id'] = '5';

		$url = $this->dispatchCapture( 'delete-reason' );

		$this->assertSame(
			array( array( 'wp_ffc_recruitment_reason', array( 'id' => 5 ) ) ),
			$this->deletes
		);
		$this->assertStringContainsString( 'tab=reasons', $url );
		$this->assertStringContainsString( 'ffc_msg=reason-deleted', $url );
	}

	public function test_delete_reason_blocked_when_still_referenced(): void {
		$_GET['reason_id']    = '5';
		$this->reason_refs[5] = 3;

		$url = $this->dispatchCapture( 'delete-reason' );

		$this->assertSame( array(), $this->deletes, 'A referenced reason is not deleted.' );
		$this->assertStringContainsString( 'tab=reasons', $url );
		$this->assertStringContainsString( 'ffc_msg=reason-in-use', $url );
	}

	public function test_delete_candidate_delegates_to_delete_service_and_redirects(): void {
		$_GET['candidate_id'] = '9';
		// A candidate with no classifications, so the delete service lets it go.
		$this->candidates[9] = (object) array(
			'id'   => 9,
			'name' => 'Jane Doe',
		);

		$url = $this->dispatchCapture( 'delete-candidate' );

		$this->assertSame(
			array( array( 'wp_ffc_recruitment_candidate', array( 'id' => 9 ) ) ),
			$this->deletes
		);
		$this->assertStringContainsString( 'tab=candidates', $url );
	}

	public function test_unknown_action_is_a_noop_without_redirect(): void {
		// No matching case → method returns normally, no redirect thrown.
		$this->assertSame( '', $this->dispatchCapture( 'totally-unknown' ) );
		$this->assertSame( array(), $this->deletes );
	}
}
