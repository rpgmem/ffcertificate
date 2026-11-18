<?php
/**
 * An in-memory `ffc_certificate_signatures` behind a `$wpdb` double.
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Answers exactly the statements `CertificateSignatureRepository` issues, so
 * the signer is tested against a table that keeps what it is given (#2639).
 * A statement it does not recognise throws: an unknown query must never read
 * as an empty result.
 */
final class FakeSignatureTable {

	/** @var string */
	public $prefix = 'wp_';

	/**
	 * Stored rows by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = array();

	/** @var int */
	private int $next = 1;

	/**
	 * Keep the query and its arguments together, as `$wpdb->prepare()` would
	 * keep them in one string.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Arguments.
	 * @return array{0: string, 1: list<mixed>}
	 */
	public function prepare( string $query, ...$args ): array {
		return array( $query, $args );
	}

	/**
	 * @param array{0: string, 1: list<mixed>} $prepared Prepared statement.
	 * @param string                            $output   Unused.
	 * @return array<string, mixed>|null
	 */
	public function get_row( array $prepared, string $output = 'OBJECT' ): ?array {
		list( $query, $args ) = $prepared;
		if ( false === strpos( $query, 'superseded_at IS NULL ORDER BY id DESC' ) ) {
			throw new \LogicException( 'Unknown query: ' . $query );
		}
		$found = null;
		foreach ( $this->rows as $row ) {
			if ( (int) $row['submission_id'] === (int) $args[1] && null === $row['superseded_at'] ) {
				$found = $row;
			}
		}
		return $found;
	}

	/**
	 * @param array{0: string, 1: list<mixed>} $prepared Prepared statement.
	 * @param string                            $output   Unused.
	 * @return list<array<string, mixed>>
	 */
	public function get_results( array $prepared, string $output = 'OBJECT' ): array {
		list( $query ) = $prepared;
		if ( false === strpos( $query, 'GROUP BY key_id' ) ) {
			throw new \LogicException( 'Unknown query: ' . $query );
		}
		$counts = array();
		foreach ( $this->rows as $row ) {
			if ( null === $row['superseded_at'] ) {
				$counts[ $row['key_id'] ] = ( $counts[ $row['key_id'] ] ?? 0 ) + 1;
			}
		}
		$out = array();
		foreach ( $counts as $key => $total ) {
			$out[] = array( 'key_id' => $key, 'total' => $total );
		}
		return $out;
	}

	/**
	 * @param string               $table   Table.
	 * @param array<string, mixed> $data    Row.
	 * @param mixed                $formats Unused.
	 */
	public function insert( string $table, array $data, $formats = null ): int {
		$data['id']               = $this->next++;
		$data['superseded_at']    = null;
		$this->rows[ $data['id'] ] = $data;
		return 1;
	}

	/**
	 * @param array{0: string, 1: list<mixed>} $prepared Prepared statement.
	 */
	public function query( array $prepared ): int {
		list( $query, $args ) = $prepared;
		if ( 0 !== strpos( $query, 'UPDATE %i SET superseded_at' ) ) {
			throw new \LogicException( 'Unknown query: ' . $query );
		}
		$changed = 0;
		foreach ( $this->rows as $id => $row ) {
			if ( (int) $row['submission_id'] === (int) $args[2] && null === $row['superseded_at'] ) {
				$this->rows[ $id ]['superseded_at'] = (int) $args[1];
				++$changed;
			}
		}
		return $changed;
	}

	/**
	 * @param string               $table   Table.
	 * @param array<string, mixed> $where   Condition.
	 * @param mixed                $formats Unused.
	 */
	public function delete( string $table, array $where, $formats = null ): int {
		$before     = count( $this->rows );
		$this->rows = array_filter( $this->rows, static fn( array $row ): bool => (int) $row['submission_id'] !== (int) $where['submission_id'] );
		return $before - count( $this->rows );
	}
}
