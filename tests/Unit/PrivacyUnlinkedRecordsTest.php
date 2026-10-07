<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Privacy\PrivacyExporters;
use FreeFormCertificate\Privacy\PrivacySubject;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The privacy tools reach records with no account, and the reregistration and
 * recruitment stores (#1574).
 *
 * @covers \FreeFormCertificate\Privacy\PrivacySubject
 * @covers \FreeFormCertificate\Privacy\PrivacyExporters
 */
class PrivacyUnlinkedRecordsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	/**
	 * Every prepare() call's arguments, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $prepared = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb           = Mockery::mock( 'wpdb' );
		$wpdb->prefix   = 'wp_';
		$wpdb->posts    = 'wp_posts';
		$wpdb->usermeta = 'wp_usermeta';
		$this->wpdb     = $wpdb;

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function () {
				$args             = func_get_args();
				$this->prepared[] = $args;
				if ( false !== strpos( (string) $args[0], 'SHOW TABLES LIKE' ) ) {
					return 'SHOW TABLES LIKE ' . $args[1];
				}
				return (string) $args[0];
			}
		);
		// Every table exists.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static fn( $sql ) => 0 === strpos( (string) $sql, 'SHOW TABLES LIKE ' ) ? substr( (string) $sql, 17 ) : null
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( $format, (int) $ts ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function account( int $id ): void {
		$user     = new \stdClass();
		$user->ID = $id;
		Functions\when( 'get_user_by' )->justReturn( $user );
	}

	// ------------------------------------------------------------------
	// PrivacySubject
	// ------------------------------------------------------------------

	public function test_a_subject_without_an_account_is_still_searchable_by_hash(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$subject = PrivacySubject::from_email( 'Someone@Example.com ' );

		$this->assertSame( 0, $subject->user_id );
		$this->assertSame( SensitiveFieldRegistry::hash_identifier( 'email', 'someone@example.com' ), $subject->email_hash, 'The hash uses the canonical form the stores were written with.' );
		$this->assertFalse( $subject->is_empty() );
	}

	public function test_a_subject_with_nothing_to_search_by_is_empty(): void {
		$this->assertTrue( ( new PrivacySubject( 0, null ) )->is_empty() );
		$this->assertTrue( ( new PrivacySubject( -5, '' ) )->is_empty() );
		$this->assertFalse( ( new PrivacySubject( 3, null ) )->is_empty() );
	}

	public function test_the_predicate_binds_both_branches_and_never_matches_a_missing_one(): void {
		( new PrivacySubject( 0, null ) )->owns_row( 't' );
		$args = end( $this->prepared );

		$this->assertStringContainsString( 't.user_id = %d', $args[0] );
		$this->assertStringContainsString( 't.user_id IS NULL OR t.user_id = 0', $args[0], 'A legacy 0 counts as unlinked.' );
		$this->assertSame( -1, $args[1], 'No account: an ID no row holds.' );
		$this->assertSame( '-', $args[2], 'No hash: a value no hex hash equals.' );
	}

	public function test_the_alias_can_only_be_an_identifier(): void {
		( new PrivacySubject( 7, 'abc' ) )->owns_row( 'x) OR 1=1 --' );
		$args = end( $this->prepared );

		$this->assertStringStartsWith( '(t.user_id', $args[0] );
		$this->assertStringNotContainsString( 'OR 1=1', $args[0] );
		$this->assertSame( 7, $args[1] );
	}

	// ------------------------------------------------------------------
	// export_reregistration()
	// ------------------------------------------------------------------

	public function test_reregistration_needs_an_account(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		$this->wpdb->shouldReceive( 'get_results' )->never();

		$result = PrivacyExporters::export_reregistration( 'nobody@example.com' );

		$this->assertSame( array( 'data' => array(), 'done' => true ), $result );
	}

	public function test_reregistration_answers_are_exported_decrypted(): void {
		$this->account( 9 );
		$cpf = Encryption::encrypt( '52998224725' );
		$this->assertNotNull( $cpf );

		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'id'           => '4',
					'status'       => 'approved',
					'submitted_at' => '1700000000',
					'campaign'     => 'Census 2026',
					'data'         => (string) json_encode(
						array(
							'fields' => array(
								'cpf'           => $cpf,
								'nome_completo' => 'Maria da Silva',
								'idiomas'       => array( 'pt', 'en' ),
							),
						)
					),
				),
			)
		);

		$result = PrivacyExporters::export_reregistration( 'maria@example.com' );
		$values = array_column( $result['data'][0]['data'], 'value', 'name' );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 'Census 2026', $values['Campaign'] );
		$this->assertSame( '52998224725', $values['cpf'], 'An encrypted answer is exported readable.' );
		$this->assertSame( 'Maria da Silva', $values['nome_completo'] );
		$this->assertSame( 'pt, en', $values['idiomas'] );
		$this->assertSame( 9, $this->prepared[ count( $this->prepared ) - 1 ][3], 'Rows are filtered by the account.' );
	}

	// ------------------------------------------------------------------
	// export_recruitment()
	// ------------------------------------------------------------------

	public function test_recruitment_finds_an_unlinked_candidate_by_hash_and_lists_classifications(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		$email = Encryption::encrypt( 'cand@example.com' );

		$this->wpdb->shouldReceive( 'get_results' )->twice()->andReturnUsing(
			static function ( $sql ) use ( $email ) {
				if ( false !== strpos( (string) $sql, 'c.list_type' ) ) {
					return array(
						array(
							'list_type' => 'general',
							'score'     => '87.5000',
							'notice'    => 'EDITAL-01',
							'adjutancy' => 'Teacher',
						),
					);
				}
				return array(
					array(
						'id'              => '12',
						'name'            => 'Ana',
						'email_encrypted' => $email,
						'cpf_encrypted'   => null,
						'rf_encrypted'    => null,
						'phone'           => '11 99999-0000',
					),
				);
			}
		);

		$result = PrivacyExporters::export_recruitment( 'cand@example.com' );
		$rows   = $result['data'][0]['data'];
		$values = array_column( $rows, 'value', 'name' );

		$this->assertSame( 'cand@example.com', $values['Email'] );
		$this->assertSame( '', $values['CPF'] );
		$this->assertContains( 'EDITAL-01 — Teacher (general, score 87.5000)', array_column( $rows, 'value' ) );

		$owns = null;
		foreach ( $this->prepared as $args ) {
			if ( 0 === strpos( (string) $args[0], '(t.user_id = %d' ) ) {
				$owns = $args;
			}
		}
		$this->assertNotNull( $owns );
		$this->assertSame( SensitiveFieldRegistry::hash_identifier( 'email', 'cand@example.com' ), $owns[2] );
	}

	public function test_recruitment_is_a_single_page(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		$this->wpdb->shouldReceive( 'get_results' )->never();

		$this->assertSame( array( 'data' => array(), 'done' => true ), PrivacyExporters::export_recruitment( 'cand@example.com', 2 ) );
	}
}
