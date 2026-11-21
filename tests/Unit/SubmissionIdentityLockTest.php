<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use FreeFormCertificate\Frontend\Submission\SubmissionIdentityLock;
use FreeFormCertificate\Frontend\Submission\SubmissionRejected;
use FreeFormCertificate\Tests\Support\FakeCredentials;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Two submissions of one person to one form run one after the other (#2695).
 *
 * The `$wpdb` double plays MySQL's named locks: a name held by one request
 * answers `0` to another (the wait ran out), and is free again once released.
 *
 * @covers \FreeFormCertificate\Frontend\Submission\SubmissionIdentityLock
 */
class SubmissionIdentityLockTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, true> Names currently held. */
	private array $held = array();

	/** @var list<string> Every query the double ran. */
	private array $queries = array();

	/** @var mixed The `$wpdb` before the test. */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( FakeCredentials::WP_SALT );

		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$wpdb              = Mockery::mock( 'wpdb' );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( string $sql, ...$args ) => vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql ), $args )
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( string $sql ) {
				$this->queries[] = $sql;
				if ( 1 === preg_match( "/^SELECT GET_LOCK\\('([^']+)', \\d+\\)$/", $sql, $m ) ) {
					if ( isset( $this->held[ $m[1] ] ) ) {
						return '0';
					}
					$this->held[ $m[1] ] = true;
					return '1';
				}
				if ( 1 === preg_match( "/^SELECT RELEASE_LOCK\\('([^']+)'\\)$/", $sql, $m ) ) {
					unset( $this->held[ $m[1] ] );
					return '1';
				}
				$this->fail( 'Unexpected query: ' . $sql );
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A context for one form and one CPF.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $cpf     CPF as typed.
	 * @param string $email   E-mail.
	 * @return SubmissionContext
	 */
	private function ctx( int $form_id, string $cpf, string $email = 'person@example.test' ): SubmissionContext {
		$ctx             = new SubmissionContext();
		$ctx->form_id    = $form_id;
		$ctx->val_cpf    = $cpf;
		$ctx->user_email = $email;
		return $ctx;
	}

	public function test_a_second_submission_of_the_same_person_waits_for_the_first(): void {
		$first  = new SubmissionIdentityLock();
		$second = new SubmissionIdentityLock();
		$first->apply( $this->ctx( 7, Identifiers::CPF_A ) );

		try {
			$second->apply( $this->ctx( 7, Identifiers::CPF_A ) );
			$this->fail( 'The second submission was not held back.' );
		} catch ( SubmissionRejected $rejected ) {
			$this->assertStringContainsString( 'still being processed', (string) $rejected->get_payload()['message'] );
		}

		$first->release();
		$second->apply( $this->ctx( 7, Identifiers::CPF_A ) );
		$this->assertCount( 1, $this->held, 'The second submission takes the lock once the first lets go.' );
	}

	public function test_the_cpf_is_normalised_so_a_formatted_copy_is_the_same_person(): void {
		$formatted = substr( Identifiers::CPF_A, 0, 3 ) . '.' . substr( Identifiers::CPF_A, 3, 3 ) . '.' . substr( Identifiers::CPF_A, 6, 3 ) . '-' . substr( Identifiers::CPF_A, 9 );
		$this->assertSame(
			SubmissionIdentityLock::lock_name( 7, 'cpf:' . \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( Identifiers::CPF_A ) ),
			SubmissionIdentityLock::lock_name( 7, 'cpf:' . \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $formatted ) )
		);
	}

	public function test_other_forms_and_other_people_do_not_wait(): void {
		( new SubmissionIdentityLock() )->apply( $this->ctx( 7, Identifiers::CPF_A ) );
		( new SubmissionIdentityLock() )->apply( $this->ctx( 8, Identifiers::CPF_A ) );
		( new SubmissionIdentityLock() )->apply( $this->ctx( 7, '', 'other@example.test' ) );

		$this->assertCount( 3, $this->held );
	}

	public function test_without_a_cpf_the_email_is_the_identity(): void {
		( new SubmissionIdentityLock() )->apply( $this->ctx( 7, '', 'Person@Example.test ' ) );

		$this->expectException( SubmissionRejected::class );
		( new SubmissionIdentityLock() )->apply( $this->ctx( 7, '', 'person@example.test' ) );
	}

	public function test_no_identity_takes_no_lock(): void {
		( new SubmissionIdentityLock() )->apply( $this->ctx( 7, '', '' ) );

		$this->assertSame( array(), $this->queries );
	}

	public function test_the_lock_name_carries_no_personal_data_and_fits_mysql(): void {
		$lock = new SubmissionIdentityLock();
		$lock->apply( $this->ctx( 7, Identifiers::CPF_A ) );

		$name = (string) array_key_first( $this->held );
		$this->assertLessThanOrEqual( 64, strlen( $name ) );
		$this->assertStringNotContainsString( Identifiers::CPF_A, $name );
		$this->assertStringStartsWith( 'ffc_submit_', $name );
	}

	public function test_release_without_a_lock_runs_no_query(): void {
		( new SubmissionIdentityLock() )->release();

		$this->assertSame( array(), $this->queries );
	}

	/**
	 * The orchestrator takes the lock before the first check that reads
	 * stored rows and lets it go on both exits.
	 */
	public function test_the_pipeline_holds_the_lock_around_the_reading_checks(): void {
		$source = (string) php_strip_whitespace( dirname( __DIR__, 2 ) . '/includes/frontend/class-ffc-form-processor.php' );

		$lock    = strpos( $source, '$lock->apply( $ctx )' );
		$rate    = strpos( $source, 'new Submission\\RateLimitGuard()' );
		$persist = strpos( $source, 'new Submission\\SubmissionPersister(' );
		$this->assertIsInt( $lock );
		$this->assertIsInt( $rate );
		$this->assertIsInt( $persist );
		$this->assertLessThan( $rate, $lock, 'The lock is taken before the rate limit reads stored attempts.' );
		$this->assertSame( 2, substr_count( $source, '$lock->release()' ), 'The lock is released on the rejection and on the success path.' );
	}

	public function test_a_server_without_named_locks_lets_the_submission_through(): void {
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		$GLOBALS['wpdb'] = $wpdb;

		$lock = new SubmissionIdentityLock();
		$lock->apply( $this->ctx( 7, Identifiers::CPF_A ) );
		$lock->release();
	}
}
