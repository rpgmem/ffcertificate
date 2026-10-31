<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Recruitment\RecruitmentImportIdentityCheck;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * The comparison of an import's rows with the stored candidates (#2245).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentImportIdentityCheck
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::get_identities_by_hash
 */
class RecruitmentImportIdentityCheckTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<object> Stored candidates the double answers from. */
	private array $stored = array();

	/** @var int Identity queries the double answered. */
	private int $queries = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'remove_accents' )->alias(
			static fn( string $s ): string => strtr( $s, array( 'á' => 'a', 'Á' => 'A', 'ç' => 'c', 'Ç' => 'C', 'ã' => 'a', 'Ã' => 'A' ) )
		);

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( string $sql, array $args ): array => $args
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( array $args ): array {
				++$this->queries;
				$column = (string) $args[1];
				$wanted = array_slice( $args, 2 );
				return array_values(
					array_filter(
						$this->stored,
						static fn( object $c ): bool => in_array( (string) $c->{$column}, $wanted, true )
					)
				);
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A stored candidate.
	 *
	 * @param int         $id    ID.
	 * @param string|null $cpf   CPF.
	 * @param string|null $rf    RF.
	 * @param string|null $email E-mail.
	 * @param string      $name  Name.
	 */
	private function candidate( int $id, ?string $cpf, ?string $rf, ?string $email, string $name ): object {
		return (object) array(
			'id'         => (string) $id,
			'cpf_hash'   => null === $cpf ? null : SensitiveFieldRegistry::hash_identifier( 'cpf', $cpf ),
			'rf_hash'    => null === $rf ? null : SensitiveFieldRegistry::hash_identifier( 'rf', $rf ),
			'email_hash' => null === $email ? null : SensitiveFieldRegistry::hash_identifier( 'email', $email ),
			'name'       => $name,
		);
	}

	/**
	 * A row of the file.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 * @return array{line: int, cpf: string, rf: string, email: string, name: string}
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'line'  => 2,
				'cpf'   => Identifiers::CPF_A,
				'rf'    => '',
				'email' => 'ana@example.com',
				'name'  => 'Ana Conceição',
			),
			$overrides
		);
	}

	public function test_a_new_candidate_is_neither_a_conflict_nor_a_mismatch(): void {
		$report = RecruitmentImportIdentityCheck::check( array( $this->row() ) );

		$this->assertSame(
			array(
				'conflicts'  => array(),
				'mismatches' => array(),
			),
			$report
		);
	}

	public function test_case_accents_and_spacing_do_not_make_a_mismatch(): void {
		$this->stored = array( $this->candidate( 7, Identifiers::CPF_A, null, 'ana@example.com', 'ANA  CONCEICÃO' ) );

		$report = RecruitmentImportIdentityCheck::check( array( $this->row( array( 'name' => ' ana conceicão ' ) ) ) );

		$this->assertSame( array(), $report['mismatches'] );
	}

	public function test_every_differing_field_is_named(): void {
		$this->stored = array( $this->candidate( 7, Identifiers::CPF_A, Identifiers::RF_A, 'other@example.com', 'Beatriz' ) );

		$report = RecruitmentImportIdentityCheck::check(
			array( $this->row( array( 'rf' => Identifiers::RF_B ) ) )
		);

		$this->assertSame(
			array(
				array(
					'line'         => 2,
					'candidate_id' => 7,
					'fields'       => array( 'name', 'rf', 'email' ),
				),
			),
			$report['mismatches']
		);
	}

	public function test_a_field_the_candidate_lacks_is_not_a_mismatch(): void {
		$this->stored = array( $this->candidate( 7, Identifiers::CPF_A, null, null, 'Ana Conceição' ) );

		$report = RecruitmentImportIdentityCheck::check(
			array( $this->row( array( 'rf' => Identifiers::RF_A ) ) )
		);

		$this->assertSame( array(), $report['mismatches'], 'Filling in a missing RF or e-mail is not a change of identity.' );
	}

	public function test_a_match_by_rf_reports_a_differing_cpf(): void {
		$this->stored = array( $this->candidate( 8, Identifiers::CPF_B, Identifiers::RF_A, null, 'Ana Conceição' ) );

		$report = RecruitmentImportIdentityCheck::check(
			array( $this->row( array( 'rf' => Identifiers::RF_A ) ) )
		);

		$this->assertSame( array( 'cpf' ), $report['mismatches'][0]['fields'] );
		$this->assertSame( 8, $report['mismatches'][0]['candidate_id'] );
	}

	public function test_a_cpf_and_an_rf_of_two_candidates_is_a_conflict(): void {
		$this->stored = array(
			$this->candidate( 7, Identifiers::CPF_A, null, null, 'Ana Conceição' ),
			$this->candidate( 8, null, Identifiers::RF_A, null, 'Bia' ),
		);

		$report = RecruitmentImportIdentityCheck::check(
			array( $this->row( array( 'line' => 5, 'rf' => Identifiers::RF_A ) ) )
		);

		$this->assertSame( array( 'line=5: recruitment_csv_identity_conflict' ), $report['conflicts'] );
		$this->assertSame( array(), $report['mismatches'], 'A conflict is not also reported as a mismatch.' );
	}

	public function test_a_whole_file_costs_one_query_per_identifier_kind(): void {
		$rows = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$rows[] = $this->row(
				array(
					'line' => $i + 2,
					'cpf'  => str_pad( (string) $i, 11, '0', STR_PAD_LEFT ),
					'rf'   => str_pad( (string) $i, 7, '0', STR_PAD_LEFT ),
				)
			);
		}

		RecruitmentImportIdentityCheck::check( $rows );

		$this->assertSame( 2, $this->queries );
	}

	public function test_messages_name_fields_and_never_values(): void {
		$messages = RecruitmentImportIdentityCheck::messages(
			array(
				array(
					'line'         => 4,
					'candidate_id' => 12,
					'fields'       => array( 'name', 'email' ),
				),
			)
		);

		$this->assertSame( array( 'Line 4 differs from candidate #12 in: name, e-mail. The stored data is kept.' ), $messages );
	}
}
