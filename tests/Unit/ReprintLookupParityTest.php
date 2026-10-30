<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Repositories\SubmissionReader;
use FreeFormCertificate\Tests\Support\Identifiers;
use FreeFormCertificate\Tests\Support\LegacyReprintLookup;

/**
 * The single duplicate lookup answers what the three it replaced answered
 * (#2241).
 *
 * The reprint check, the quiz attempt lookup and the bulk issue's refusal
 * each asked "does this form already have a submission for this person?",
 * two of them in their own `$wpdb` code. `SubmissionReader::findForReprint()`
 * now answers for all of them, once per submission. Every scenario below
 * runs through the code as it stood ({@see LegacyReprintLookup}) and through
 * the Reader, against one in-memory table, and the two must return the same
 * row — so a scenario proves the answer, not the SQL.
 *
 * One difference is decided rather than accidental, and has its own test:
 * an install with encryption no longer reads a ticket by a LIKE over `data`.
 *
 * @covers \FreeFormCertificate\Repositories\SubmissionReader
 */
class ReprintLookupParityTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The table, as rows of column => value.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $rows = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $sql, ...$args ) => array(
				'sql'  => $sql,
				'args' => $args,
			)
		);
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v );
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn( $q ) => $this->select_latest( $q['sql'], $q['args'] ) );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( fn( $q ) => (string) $this->count_rows( $q['sql'], $q['args'] ) );

		$cpf_a  = SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::CPF_A );
		$rf_a   = SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::RF_A );
		$cpf_c  = SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::CPF_C );
		$ticket = SensitiveFieldRegistry::hash_identifier( 'ticket', 'TK100' );

		$this->rows = array(
			$this->row( 1, 7, array( 'cpf_hash' => $cpf_a ) ),
			$this->row( 2, 7, array( 'cpf_hash' => $cpf_a ) ),
			$this->row( 3, 7, array( 'rf_hash' => $rf_a ) ),
			$this->row( 4, 8, array( 'cpf_hash' => $cpf_a ) ),
			$this->row( 5, 7, array( 'ticket_hash' => $ticket ) ),
			$this->row( 6, 7, array( 'data' => '{"ticket":"TK200","name":"Ana"}' ) ),
			$this->row(
				7,
				7,
				array(
					'cpf_hash' => $cpf_c,
					'status'   => 'trash',
				)
			),
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Scenarios both sides must agree on: form, CPF/RF as typed, ticket, encryption.
	 *
	 * @return array<string, array{int, string, string, bool}>
	 */
	public function scenarios(): array {
		$masked = substr( Identifiers::CPF_A, 0, 3 ) . '.' . substr( Identifiers::CPF_A, 3, 3 ) . '.' . substr( Identifiers::CPF_A, 6, 3 ) . '-' . substr( Identifiers::CPF_A, 9, 2 );
		return array(
			'CPF, latest of two'           => array( 7, Identifiers::CPF_A, '', true ),
			'CPF typed with its mask'      => array( 7, $masked, '', true ),
			'CPF on another form'          => array( 8, Identifiers::CPF_A, '', true ),
			'RF'                           => array( 7, Identifiers::RF_A, '', true ),
			'CPF with no submission'       => array( 7, Identifiers::CPF_B, '', true ),
			'CPF of a revoked certificate' => array( 7, Identifiers::CPF_C, '', true ),
			'ticket by its hash'           => array( 7, '', 'TK100', true ),
			'ticket wins over CPF'         => array( 7, Identifiers::CPF_A, 'TK100', true ),
			'unknown ticket'               => array( 7, '', 'TK999', true ),
			'ticket without encryption'    => array( 7, '', 'TK200', false ),
			'CPF without encryption'       => array( 7, Identifiers::CPF_A, '', false ),
			'nothing typed'                => array( 7, '', '', true ),
		);
	}

	/**
	 * @dataProvider scenarios
	 */
	public function test_the_reprint_lookup_finds_what_the_old_one_found( int $form_id, string $cpf, string $ticket, bool $encrypted ): void {
		$this->assertSame(
			$this->id_of( LegacyReprintLookup::reprint( $form_id, $cpf, $ticket, $encrypted ) ),
			$this->id_of( ( new SubmissionReader() )->findForReprint( $form_id, $cpf, $ticket, $encrypted ) )
		);
	}

	/**
	 * The quiz never looks up by ticket, so its scenarios are the CPF/RF ones.
	 *
	 * @dataProvider scenarios
	 */
	public function test_the_quiz_lookup_finds_what_the_old_one_found( int $form_id, string $cpf, string $ticket, bool $encrypted ): void {
		unset( $ticket );
		$this->assertSame(
			$this->id_of( LegacyReprintLookup::quiz( $form_id, $cpf, $encrypted ) ),
			$this->id_of( ( new SubmissionReader() )->findForReprint( $form_id, $cpf, '', $encrypted ) )
		);
	}

	/**
	 * The bulk issue refuses a person the form already issued to by counting
	 * hashes; the lookup must say "found" exactly when that count is not zero.
	 *
	 * @dataProvider scenarios
	 */
	public function test_the_lookup_agrees_with_the_bulk_issue_counts( int $form_id, string $cpf, string $ticket, bool $encrypted ): void {
		unset( $ticket );
		if ( ! $encrypted || '' === $cpf ) {
			$this->assertNull( ( new SubmissionReader() )->findForReprint( $form_id, $cpf, '', $encrypted ), 'No hash, nothing to find.' );
			return;
		}
		$hash   = (string) SensitiveFieldRegistry::hash_identifier( 'cpf', $cpf );
		$reader = new SubmissionReader();
		$issued = $reader->countByFormAndCpfHash( $form_id, $hash ) + $reader->countByFormAndRfHash( $form_id, $hash ) > 0;

		$this->assertSame( $issued, null !== $reader->findForReprint( $form_id, $cpf, '', true ) );
	}

	public function test_an_encrypted_install_no_longer_reads_a_ticket_from_plaintext_data(): void {
		// The decided difference (#2241): with a key every ticket is stored as
		// its hash, so the scan over `data` only ever reached rows written
		// before encryption. The old lookup found this one; the new does not.
		$this->assertSame( 6, $this->id_of( LegacyReprintLookup::reprint( 7, '', 'TK200', true ) ) );
		$this->assertNull( ( new SubmissionReader() )->findForReprint( 7, '', 'TK200', true ) );
	}

	public function test_the_scenarios_reach_rows(): void {
		// A table the fake cannot read would make every scenario agree on null.
		$found = 0;
		foreach ( $this->scenarios() as $args ) {
			$found += null !== LegacyReprintLookup::reprint( ...$args ) ? 1 : 0;
		}
		$this->assertSame( 8, $found );
	}

	/**
	 * A stored row.
	 *
	 * @param int                  $id      Row ID.
	 * @param int                  $form_id Form ID.
	 * @param array<string, mixed> $columns Columns that differ from the defaults.
	 * @return array<string, mixed>
	 */
	private function row( int $id, int $form_id, array $columns ): array {
		return array_merge(
			array(
				'id'              => (string) $id,
				'form_id'         => (string) $form_id,
				'status'          => 'publish',
				'submission_date' => '1750000000',
				'data'            => null,
				'cpf_hash'        => null,
				'rf_hash'         => null,
				'ticket_hash'     => null,
			),
			$columns
		);
	}

	/**
	 * The rows a `WHERE form_id = %d AND <column> = %s|LIKE %s` query selects.
	 *
	 * Reads the one condition after `form_id` from the query, the column
	 * either literal or bound through `%i`.
	 *
	 * @param string            $sql  Query text.
	 * @param array<int, mixed> $args Bound values, the table first.
	 * @return list<array<string, mixed>>
	 */
	private function matching( string $sql, array $args ): array {
		$this->assertSame( 1, preg_match( '/WHERE form_id = %d AND (%i|\w+) (=|LIKE) %s/', $sql, $m ), 'A query shape the fake cannot read: ' . $sql );
		$form_id = (string) $args[1];
		$column  = '%i' === $m[1] ? (string) $args[2] : $m[1];
		$value   = (string) ( '%i' === $m[1] ? $args[3] : $args[2] );

		$hits = array();
		foreach ( $this->rows as $row ) {
			if ( $row['form_id'] !== $form_id || null === $row[ $column ] ) {
				continue;
			}
			$stored = (string) $row[ $column ];
			$match  = '=' === $m[2]
				? $stored === $value
				: 1 === preg_match( '/^' . str_replace( '%', '.*', preg_quote( $value, '/' ) ) . '$/s', $stored );
			if ( $match ) {
				$hits[] = $row;
			}
		}
		return $hits;
	}

	/**
	 * `ORDER BY id DESC|ASC LIMIT 1`, as an object the way `$wpdb` returns it.
	 *
	 * @param string            $sql  Query text.
	 * @param array<int, mixed> $args Bound values.
	 * @return object|null
	 */
	private function select_latest( string $sql, array $args ): ?object {
		$this->assertSame( 1, preg_match( '/ORDER BY id (DESC|ASC) LIMIT 1$/', $sql, $order ), 'A query shape the fake cannot read: ' . $sql );
		$hits = $this->matching( $sql, $args );
		$sign = 'DESC' === $order[1] ? -1 : 1;
		usort( $hits, static fn( $a, $b ) => $sign * ( (int) $a['id'] <=> (int) $b['id'] ) );
		return array() === $hits ? null : (object) $hits[0];
	}

	/**
	 * `SELECT COUNT(*)` over the same condition.
	 *
	 * @param string            $sql  Query text.
	 * @param array<int, mixed> $args Bound values.
	 * @return int
	 */
	private function count_rows( string $sql, array $args ): int {
		return count( $this->matching( $sql, $args ) );
	}

	/**
	 * The row's ID, or null.
	 *
	 * @param object|null $row Row.
	 * @return int|null
	 */
	private function id_of( ?object $row ): ?int {
		return null === $row ? null : (int) $row->id;
	}
}
