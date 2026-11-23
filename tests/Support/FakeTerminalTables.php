<?php
/**
 * In-memory `ffc_audience_terminals` and `ffc_audience_terminal_pairings`
 * behind a `$wpdb` double.
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Answers exactly the statements `AudienceTerminalReader` and
 * `AudienceTerminalWriter` issue, so pairing is tested against tables that
 * keep what they are given (#2744). A statement it does not recognise
 * throws: an unknown query must never read as an empty result.
 */
final class FakeTerminalTables extends \wpdb {

	/** @var int */
	public $insert_id = 0;

	/**
	 * Terminal rows by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $terminals = array();

	/**
	 * Pairing rows by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $pairings = array();

	/**
	 * What the booking list query answers (the rows `AudienceBookingReader::get_all()` reads).
	 *
	 * @var list<object>
	 */
	public array $booking_list = array();

	/**
	 * Check-in window rows, for the schedule's "is it open now" question.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $windows = array();

	/**
	 * Prepared statements by handle.
	 *
	 * @var array<string, array{0: string, 1: list<mixed>}>
	 */
	private array $prepared = array();

	/** @var int */
	private int $next = 1;

	/**
	 * A double with the test prefix.
	 */
	public function __construct() {
		$this->prefix = 'wp_';
	}

	/**
	 * Keep a statement and its arguments; hand back a handle.
	 *
	 * @param string $query   Query.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		$handle                    = 'prepared#' . count( $this->prepared );
		$this->prepared[ $handle ] = array( $query, $args );
		return $handle;
	}

	/**
	 * @param string $handle Prepared statement.
	 * @param string $output Unused.
	 * @return array<string, mixed>|null
	 */
	public function get_row( $handle = null, $output = OBJECT, $y = 0 ) {
		list( $query, $args ) = $this->statement( $handle );
		if ( false !== strpos( $query, 'WHERE code_hash = %s' ) ) {
			return $this->find( $this->pairings, 'code_hash', $args[1] );
		}
		if ( false !== strpos( $query, 'WHERE token_hash = %s' ) ) {
			return $this->find( $this->terminals, 'token_hash', $args[1] );
		}
		if ( false !== strpos( $query, 'SELECT * FROM %i WHERE id = %d LIMIT 1' ) ) {
			return $this->terminals[ (int) $args[1] ] ?? null;
		}
		// A booking the test did not put in the object cache does not exist.
		if ( false !== strpos( $query, 'INNER JOIN %i e ON b.environment_id = e.id' ) ) {
			return null;
		}
		throw new \LogicException( 'Unknown query: ' . $query );
	}

	/**
	 * @param string $handle Prepared statement.
	 * @param string $output Unused.
	 * @return list<array<string, mixed>>
	 */
	public function get_results( $handle = null, $output = OBJECT ) {
		list( $query, $args ) = $this->statement( $handle );
		if ( false !== strpos( $query, 'WHERE revoked_at IS NULL AND expires_at > %d' ) ) {
			$by   = false !== strpos( $query, 'AND paired_by = %d' ) ? (int) $args[2] : null;
			$rows = array_filter(
				$this->terminals,
				static fn( array $r ): bool => null === $r['revoked_at'] && (int) $r['expires_at'] > (int) $args[1] && ( null === $by || (int) $r['paired_by'] === $by )
			);
			usort( $rows, static fn( array $a, array $b ): int => array( $b['paired_at'], $b['id'] ) <=> array( $a['paired_at'], $a['id'] ) );
			return array_values( $rows );
		}
		if ( false !== strpos( $query, 'INNER JOIN %i e ON b.environment_id = e.id' ) ) {
			return $this->booking_list;
		}
		if ( false !== strpos( $query, 'WHERE booking_id = %d AND window_date = %s AND opened_at <= %d' ) ) {
			$rows = array_filter(
				$this->windows,
				static fn( array $w ): bool => (int) $w['booking_id'] === (int) $args[1] && $w['window_date'] === $args[2] && (int) $w['opened_at'] <= (int) $args[3]
			);
			return array_reverse( array_values( $rows ) );
		}
		throw new \LogicException( 'Unknown query: ' . $query );
	}

	/**
	 * @param string               $table   Table.
	 * @param array<string, mixed> $data    Row.
	 * @param array<int, string>   $formats Unused.
	 * @return int|false
	 */
	public function insert( $table, $data, $format = null ) {
		// The activity log flushes its buffer here once it is full; the test
		// reads the entries from the buffer, not from this table.
		if ( 'wp_ffc_activity_log' === $table ) {
			return 1;
		}
		if ( 'wp_ffc_audience_terminal_pairings' === $table ) {
			if ( null !== $this->find( $this->pairings, 'code_hash', $data['code_hash'] ) ) {
				return false;
			}
			$id                    = $this->next++;
			$this->insert_id       = $id;
			$this->pairings[ $id ] = array_merge(
				array(
					'id'          => $id,
					'used_at'     => null,
					'terminal_id' => null,
				),
				$data
			);
			return 1;
		}
		if ( 'wp_ffc_audience_terminals' === $table ) {
			$id                     = $this->next++;
			$this->insert_id        = $id;
			$this->terminals[ $id ] = array_merge(
				array(
					'id'           => $id,
					'removed'      => null,
					'last_seen_at' => null,
					'revoked_at'   => null,
					'revoked_by'   => null,
				),
				$data
			);
			return 1;
		}
		throw new \LogicException( 'Unknown table: ' . $table );
	}

	/**
	 * @param string $handle Prepared statement.
	 * @return int|false
	 */
	public function query( $handle ) {
		list( $query, $args ) = $this->statement( $handle );
		if ( 0 === strpos( $query, 'UPDATE %i SET used_at = %d WHERE id = %d AND used_at IS NULL AND expires_at >= %d' ) ) {
			$row = $this->pairings[ (int) $args[2] ] ?? null;
			if ( null === $row || null !== $row['used_at'] || (int) $row['expires_at'] < (int) $args[3] ) {
				return 0;
			}
			$this->pairings[ (int) $args[2] ]['used_at'] = (int) $args[1];
			return 1;
		}
		if ( 0 === strpos( $query, 'UPDATE %i SET terminal_id = %d WHERE id = %d' ) ) {
			$this->pairings[ (int) $args[2] ]['terminal_id'] = (int) $args[1];
			return 1;
		}
		if ( 0 === strpos( $query, 'UPDATE %i SET revoked_at = %d, revoked_by = %d WHERE id = %d AND revoked_at IS NULL' ) ) {
			$row = $this->terminals[ (int) $args[3] ] ?? null;
			if ( null === $row || null !== $row['revoked_at'] ) {
				return 0;
			}
			$this->terminals[ (int) $args[3] ]['revoked_at'] = (int) $args[1];
			$this->terminals[ (int) $args[3] ]['revoked_by'] = (int) $args[2];
			return 1;
		}
		if ( 0 === strpos( $query, 'UPDATE %i SET bookings = %s, removed = %s, expires_at = %d WHERE id = %d AND revoked_at IS NULL' ) ) {
			$row = $this->terminals[ (int) $args[4] ] ?? null;
			if ( null === $row || null !== $row['revoked_at'] ) {
				return 0;
			}
			$this->terminals[ (int) $args[4] ]['bookings']   = $args[1];
			$this->terminals[ (int) $args[4] ]['removed']    = $args[2];
			$this->terminals[ (int) $args[4] ]['expires_at'] = (int) $args[3];
			return 1;
		}
		if ( 0 === strpos( $query, 'UPDATE %i SET last_seen_at = %d WHERE id = %d' ) ) {
			$this->terminals[ (int) $args[2] ]['last_seen_at'] = (int) $args[1];
			return 1;
		}
		if ( 0 === strpos( $query, 'DELETE FROM %i WHERE expires_at < %d' ) ) {
			$before         = count( $this->pairings );
			$this->pairings = array_filter( $this->pairings, static fn( array $r ): bool => (int) $r['expires_at'] >= (int) $args[1] );
			return $before - count( $this->pairings );
		}
		throw new \LogicException( 'Unknown query: ' . $query );
	}

	/**
	 * The first row whose column equals a value.
	 *
	 * @param array<int, array<string, mixed>> $rows   Rows.
	 * @param string                           $column Column.
	 * @param mixed                            $value  Value.
	 * @return array<string, mixed>|null
	 */
	private function find( array $rows, string $column, $value ): ?array {
		foreach ( $rows as $row ) {
			if ( $row[ $column ] === $value ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * A prepared statement by its handle.
	 *
	 * @param string $handle Handle.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function statement( $handle ): array {
		if ( ! isset( $this->prepared[ $handle ] ) ) {
			throw new \LogicException( 'Not a prepared statement: ' . $handle );
		}
		return $this->prepared[ $handle ];
	}
}
