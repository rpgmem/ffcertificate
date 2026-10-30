<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationSubmissionActions;

/**
 * Tests for what ReregistrationSubmissionActions does once its guards pass:
 * the write it delegates, the actor it records, and where it redirects. The
 * guard half lives in ReregistrationSubmissionActionsTest.
 *
 * The writer, the reader and the email handler are the real classes, over a
 * `$wpdb` double that answers the submission and campaign reads and records
 * every update; the reminder goes out through the plugin's email path to a
 * stubbed `wp_mail()`. Every handler ends in `exit` after its redirect; the
 * redirect throws to stand in for it.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationSubmissionActions
 */
class ReregistrationSubmissionActionsFlowTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<string> */
	private array $redirects = array();

	/** @var list<string> Nonce actions verified. */
	private array $nonces = array();

	/**
	 * Every `$wpdb->update()`, as `[ table, data, where ]`.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private array $updates = array();

	/**
	 * Recipients `wp_mail()` was called with.
	 *
	 * @var list<string>
	 */
	private array $mailed = array();

	/**
	 * Submission statuses, by id. 21 and 3 await review; 5 was never
	 * answered, which is the row a bulk "select all" must not approve.
	 *
	 * @var array<int, string>
	 */
	private array $statuses = array(
		21 => 'submitted',
		3  => 'submitted',
		5  => 'pending',
	);

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The campaign window is read on the site's clock (#2242); pin it
		// inside the fixtures' campaigns.
		Functions\when( 'current_time' )->alias(
			static function ( $type ) {
				return 'Y-m-d' === $type ? '2026-06-15' : ( 'mysql' === $type ? '2026-06-15 12:00:00' : 0 );
			}
		);


		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		$_GET  = array();
		$_POST = array();

		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 8 );
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// Emails enabled, activity log off, debug off: the defaults of an
		// install nobody configured.
		Functions\when( 'get_option' )->alias( static fn ( $key, $default = false ) => $default );

		$this->nonces = array();
		$nonces       = &$this->nonces;
		Functions\when( 'wp_verify_nonce' )->alias( static function ( $nonce, $action ) use ( &$nonces ) {
			$nonces[] = $action;
			return 'good' === $nonce;
		} );

		$this->redirects = array();
		$redirects       = &$this->redirects;
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $url ) use ( &$redirects ) {
			$redirects[] = $url;
			throw new \RuntimeException( 'redirected' );
		} );

		$this->mailed = array();
		$mailed       = &$this->mailed;
		Functions\when( 'wp_mail' )->alias( static function ( $to ) use ( &$mailed ) {
			$mailed[] = $to;
			return true;
		} );

		// Approval issues an authentication code (#2242).
		Functions\when( 'wp_rand' )->alias( static fn ( $min, $max ) => random_int( $min, $max ) );

		$this->updates   = array();
		$GLOBALS['wpdb'] = $this->database();
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A database holding campaign 4 (reminders on) and submission 3 of user 50,
	 * pending. Submission 9 does not exist.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->users  = 'wp_users';

		// Interpolates, so the reads below can dispatch on the query's intent.
		// A conditional UPDATE (#2242) is kept as its parsed parts instead,
		// and `query()` applies it against the status map.
		$pending = array();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) use ( &$pending ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				if ( 0 === strpos( (string) $sql, 'UPDATE %i SET' ) ) {
					$pending[] = array( (string) $sql, $values );
					return 'COND:' . ( count( $pending ) - 1 );
				}
				foreach ( $values as $v ) {
					$sql = (string) preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $marker ) use ( &$pending ) {
				list( $sql, $values ) = $pending[ (int) substr( (string) $marker, 5 ) ];
				$table                = array_shift( $values );
				$set                  = substr( $sql, strlen( 'UPDATE %i SET ' ), strpos( $sql, ' WHERE ' ) - strlen( 'UPDATE %i SET ' ) );
				$data                 = array();
				foreach ( explode( ', ', $set ) as $part ) {
					$column          = array_shift( $values );
					$data[ $column ] = '%i = NULL' === $part ? null : array_shift( $values );
				}
				$id      = (int) array_shift( $values );
				$allowed = $values;
				if ( ! in_array( $this->statuses[ $id ] ?? '', $allowed, true ) ) {
					return 0;
				}
				$this->statuses[ $id ] = (string) ( $data['status'] ?? $this->statuses[ $id ] );
				$this->updates[]       = array( $table, $data, array( 'id' => $id ) );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				if ( preg_match( '/^SELECT status FROM \\S+ WHERE id = (\\d+)/', (string) $sql, $m ) ) {
					return $this->statuses[ (int) $m[1] ] ?? null;
				}
				return null;
			}
		);

		$submission = (object) array(
			'id'                => 3,
			'reregistration_id' => 4,
			'user_id'           => '50',
			'status'            => 'pending',
		);
		$campaign   = (object) array(
			'id'                     => 4,
			'title'                  => 'Campaign',
			'email_reminder_enabled' => 1,
			'start_date'             => '2026-01-01',
			'end_date'               => '2099-12-31',
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			static function ( $sql ) use ( $submission, $campaign ) {
				if ( str_contains( $sql, 'wp_ffc_reregistration_submissions WHERE id = 3' ) ) {
					return $submission;
				}
				if ( str_contains( $sql, 'wp_ffc_reregistration_submissions' ) && str_contains( $sql, 'user_id = 50' ) ) {
					return $submission;
				}
				if ( str_contains( $sql, 'wp_ffc_reregistrations WHERE id = 4' ) ) {
					return $campaign;
				}
				return null;
			}
		);

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
		);

		return $wpdb;
	}

	/**
	 * The updates written to the submissions table, as `id => data`, in order.
	 *
	 * @return list<array{0: int, 1: array<string, mixed>}>
	 */
	private function submission_writes(): array {
		$out = array();
		foreach ( $this->updates as $update ) {
			if ( 'wp_ffc_reregistration_submissions' === $update[0] ) {
				$out[] = array( (int) $update[2]['id'], $update[1] );
			}
		}
		return $out;
	}

	private function runHandler( string $handler ): void {
		try {
			ReregistrationSubmissionActions::$handler();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
	}

	private function single( string $action ): void {
		$_GET = array(
			'action'   => $action,
			'sub_id'   => '21',
			'id'       => '4',
			'_wpnonce' => 'good',
		);
	}

	public function test_approve_records_the_reviewer_and_returns_to_the_list(): void {
		$this->single( 'approve' );

		$this->runHandler( 'handle_approve' );

		$writes = $this->submission_writes();
		$this->assertCount( 1, $writes );
		$this->assertSame( 21, $writes[0][0] );
		$this->assertSame( 'approved', $writes[0][1]['status'] );
		$this->assertSame( 8, $writes[0][1]['reviewed_by'] );
		$this->assertMatchesRegularExpression( '/^[A-Z0-9]{12}$/', (string) $writes[0][1]['auth_code'], 'Approval issues the code (#2242).' );
		$this->assertSame( array( 'approve_submission_21' ), $this->nonces );
		$this->assertSame( array( 'https://example.com/wp-admin/admin.php?page=ffc-reregistration&view=submissions&id=4&message=approved' ), $this->redirects );
	}

	public function test_reject_records_the_reviewer_and_returns_to_the_list(): void {
		$this->single( 'reject' );

		$this->runHandler( 'handle_reject' );

		$writes = $this->submission_writes();
		$this->assertCount( 1, $writes );
		$this->assertSame( 21, $writes[0][0] );
		$this->assertSame( 'rejected', $writes[0][1]['status'] );
		$this->assertSame( 8, $writes[0][1]['reviewed_by'] );
		$this->assertArrayHasKey( 'auth_code', $writes[0][1] );
		$this->assertNull( $writes[0][1]['auth_code'], 'Reject deletes the code (#2242).' );
		$this->assertSame( array( 'reject_submission_21' ), $this->nonces );
		$this->assertSame( array( 'https://example.com/wp-admin/admin.php?page=ffc-reregistration&view=submissions&id=4&message=rejected' ), $this->redirects );
	}

	public function test_return_to_draft_reopens_the_submission(): void {
		$this->single( 'return_to_draft' );

		$this->runHandler( 'handle_return_to_draft' );

		$writes = $this->submission_writes();
		$this->assertCount( 1, $writes );
		$this->assertSame( 21, $writes[0][0] );
		$this->assertSame( 'in_progress', $writes[0][1]['status'] );
		$this->assertArrayHasKey( 'reviewed_by', $writes[0][1] );
		$this->assertNull( $writes[0][1]['reviewed_by'] );
		$this->assertSame( array( 'return_to_draft_submission_21' ), $this->nonces );
		$this->assertSame( array( 'https://example.com/wp-admin/admin.php?page=ffc-reregistration&view=submissions&id=4&message=returned_to_draft' ), $this->redirects );
	}

	private function bulk( string $action, array $ids ): void {
		$_POST = array(
			'ffc_action'        => 'bulk_submissions',
			'reregistration_id' => '4',
			'ffc_bulk_nonce'    => 'good',
			'bulk_action'       => $action,
			'submission_ids'    => $ids,
		);
	}

	public function test_bulk_approve_approves_only_the_selected_rows_awaiting_review(): void {
		// 5 was never answered: a "select all" must leave it alone and say so
		// (#2242).
		$this->bulk( 'approve', array( '3', '5', '21' ) );

		$this->runHandler( 'handle_bulk' );

		$writes = $this->submission_writes();
		$this->assertSame( array( 3, 21 ), array_column( $writes, 0 ) );
		foreach ( $writes as $write ) {
			$this->assertSame( 'approved', $write[1]['status'] );
			$this->assertSame( 8, $write[1]['reviewed_by'] );
		}
		$this->assertSame( 'pending', $this->statuses[5] );
		$this->assertSame( array( 'bulk_submissions_4' ), $this->nonces );
		$this->assertStringEndsWith( '&message=bulk_approved&count=2&skipped=1', $this->redirects[0] );
	}

	public function test_bulk_return_to_draft_reopens_every_selected_id(): void {
		$this->bulk( 'return_to_draft', array( '3' ) );

		$this->runHandler( 'handle_bulk' );

		$writes = $this->submission_writes();
		$this->assertSame( array( 3 ), array_column( $writes, 0 ) );
		$this->assertSame( 'in_progress', $writes[0][1]['status'] );
		$this->assertStringEndsWith( '&message=bulk_returned_to_draft', $this->redirects[0] );
	}

	public function test_bulk_reminder_reaches_the_owners_of_the_submissions_that_exist(): void {
		$this->bulk( 'send_reminder', array( '3', '9' ) );
		Functions\when( 'get_userdata' )->alias(
			static fn ( $id ) => 50 === (int) $id
				? (object) array(
					'display_name' => 'Owner',
					'user_email'   => 'owner@example.com',
				)
				: false
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn ( $format, $ts = null ) => gmdate( (string) $format, (int) $ts ) );

		$this->runHandler( 'handle_bulk' );

		// Only submission 3 exists, so only its owner is reminded -- and the
		// row that was reminded is the one stamped.
		$this->assertSame( array( 'owner@example.com' ), $this->mailed );
		$writes = $this->submission_writes();
		$this->assertSame( array( 3 ), array_column( $writes, 0 ) );
		$this->assertArrayHasKey( 'reminder_sent_at', $writes[0][1] );
		$this->assertSame(
			array( 'https://example.com/wp-admin/admin.php?page=ffc-reregistration&view=submissions&id=4&message=reminders_sent&count=1' ),
			$this->redirects
		);
	}

	public function test_bulk_with_an_unknown_action_does_nothing(): void {
		$this->bulk( 'delete_everything', array( '3' ) );

		$this->runHandler( 'handle_bulk' );

		$this->assertSame( array(), $this->updates );
		$this->assertSame( array( 'bulk_submissions_4' ), $this->nonces );
		$this->assertSame( array(), $this->redirects );
	}
}
