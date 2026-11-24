<?php
/**
 * In-memory `ffc_audience_presence_devices` and `ffc_audience_presence_requests`
 * behind a `$wpdb` double.
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Answers exactly the statements `AudiencePresenceReader` and
 * `AudiencePresenceWriter` issue, so the enrolment rules are tested against
 * tables that keep what they are given (#2741). A statement it does not
 * recognise throws: an unknown query must never read as an empty result.
 *
 * `prepare()` returns a string handle, as the real one returns a string, and
 * keeps the arguments beside it, so code that tests the prepared statement's
 * type runs as it would against WordPress.
 */
final class FakePresenceTables extends \wpdb {

	/** @var int */
	public $insert_id = 0;

	/**
	 * Device rows by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $devices = array();

	/**
	 * Request rows by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $requests = array();

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
		if ( false !== strpos( $query, 'WHERE key_id = %s' ) ) {
			foreach ( $this->devices as $row ) {
				if ( $row['key_id'] === $args[1] ) {
					return $row;
				}
			}
			return null;
		}
		if ( false !== strpos( $query, 'SELECT user_id, activated_at, revoked_at FROM %i WHERE device_id = %s AND user_id <> %d' ) ) {
			list( , $device, $user, $since ) = $args;
			$found                           = null;
			foreach ( $this->devices as $row ) {
				$touched = null === $row['revoked_at'] || (int) $row['revoked_at'] >= $since || (int) $row['activated_at'] >= $since;
				if ( $row['device_id'] === $device && (int) $row['user_id'] !== (int) $user && null === $row['released_at'] && $touched && ( null === $found || (int) $row['activated_at'] > (int) $found['activated_at'] ) ) {
					$found = $row;
				}
			}
			return $found;
		}
		if ( 'SELECT * FROM %i WHERE id = %d LIMIT 1' === $query ) {
			$rows = 'wp_ffc_audience_presence_requests' === $args[0] ? $this->requests : $this->devices;
			return $rows[ (int) $args[1] ] ?? null;
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
		if ( false !== strpos( $query, 'WHERE user_id = %d ORDER BY activated_at DESC' ) ) {
			$rows = array_values( array_filter( $this->devices, static fn( array $r ): bool => (int) $r['user_id'] === (int) $args[1] ) );
			usort( $rows, static fn( array $a, array $b ): int => array( $b['activated_at'], $b['id'] ) <=> array( $a['activated_at'], $a['id'] ) );
			return $rows;
		}
		if ( false !== strpos( $query, 'WHERE revoked_at IS NULL ORDER BY activated_at DESC, id DESC LIMIT %d OFFSET %d' ) ) {
			$rows = array_values( array_filter( $this->devices, static fn( array $r ): bool => null === $r['revoked_at'] ) );
			usort( $rows, static fn( array $a, array $b ): int => array( $b['activated_at'], $b['id'] ) <=> array( $a['activated_at'], $a['id'] ) );
			return array_slice( $rows, (int) $args[2], (int) $args[1] );
		}
		if ( false !== strpos( $query, 'WHERE revoked_at IS NULL AND user_id IN (' ) ) {
			$offset = (int) array_pop( $args );
			$limit  = (int) array_pop( $args );
			$ids    = array_map( 'intval', array_slice( $args, 1 ) );
			$rows   = array_values( array_filter( $this->devices, static fn( array $r ): bool => null === $r['revoked_at'] && in_array( (int) $r['user_id'], $ids, true ) ) );
			usort( $rows, static fn( array $a, array $b ): int => array( $b['activated_at'], $b['id'] ) <=> array( $a['activated_at'], $a['id'] ) );
			return array_slice( $rows, $offset, $limit );
		}
		if ( false !== strpos( $query, 'WHERE status = %s ORDER BY created_at ASC' ) ) {
			$rows = array_values( array_filter( $this->requests, static fn( array $r ): bool => $r['status'] === $args[1] ) );
			usort( $rows, static fn( array $a, array $b ): int => array( $a['created_at'], $a['id'] ) <=> array( $b['created_at'], $b['id'] ) );
			return $rows;
		}
		if ( false !== strpos( $query, 'WHERE revoked_at IS NULL AND signals_hash IS NOT NULL ORDER BY' ) ) {
			$rows = array_values( array_filter( $this->devices, static fn( array $r ): bool => null === $r['revoked_at'] && null !== $r['signals_hash'] ) );
			usort( $rows, static fn( array $a, array $b ): int => $a['activated_at'] <=> $b['activated_at'] );
			return $rows;
		}
		throw new \LogicException( 'Unknown query: ' . $query );
	}

	/**
	 * @param string $handle Prepared statement.
	 * @return mixed
	 */
	public function get_var( $handle = null, $x = 0, $y = 0 ) {
		list( $query, $args ) = $this->statement( $handle );
		if ( false !== strpos( $query, 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND is_change = 1' ) ) {
			$count = 0;
			foreach ( $this->devices as $row ) {
				if ( (int) $row['user_id'] === (int) $args[1] && 1 === (int) $row['is_change'] && (int) $row['activated_at'] >= (int) $args[2] ) {
					++$count;
				}
			}
			return (string) $count;
		}
		if ( 0 === strpos( $query, 'SELECT COUNT(*) FROM %i WHERE revoked_at IS NULL AND user_id IN (' ) ) {
			$ids = array_map( 'intval', array_slice( $args, 1 ) );
			return (string) count( array_filter( $this->devices, static fn( array $r ): bool => null === $r['revoked_at'] && in_array( (int) $r['user_id'], $ids, true ) ) );
		}
		if ( 'SELECT COUNT(*) FROM %i WHERE revoked_at IS NULL' === $query ) {
			return (string) count( array_filter( $this->devices, static fn( array $r ): bool => null === $r['revoked_at'] ) );
		}
		if ( 'SELECT COUNT(*) FROM %i WHERE is_change = 1 AND activated_at >= %d' === $query ) {
			return (string) count( array_filter( $this->devices, static fn( array $r ): bool => 1 === (int) $r['is_change'] && (int) $r['activated_at'] >= (int) $args[1] ) );
		}
		if ( false !== strpos( $query, 'SELECT id FROM %i WHERE user_id = %d AND device_id = %s AND reason = %s AND status = %s' ) ) {
			$found = null;
			foreach ( $this->requests as $row ) {
				if ( (int) $row['user_id'] === (int) $args[1] && $row['device_id'] === $args[2] && $row['reason'] === $args[3] && $row['status'] === $args[4] ) {
					$found = (string) $row['id'];
				}
			}
			return $found;
		}
		throw new \LogicException( 'Unknown query: ' . $query );
	}

	/**
	 * @param string $handle Prepared statement.
	 * @return list<string>
	 */
	public function get_col( $handle = null, $x = 0 ) {
		list( $query, $args ) = $this->statement( $handle );
		$out                  = array();
		if ( false !== strpos( $query, 'SELECT device_id FROM %i WHERE signals_hash = %s' ) ) {
			foreach ( $this->devices as $row ) {
				if ( $row['signals_hash'] === $args[1] && (int) $row['user_id'] !== (int) $args[2] && null === $row['revoked_at'] ) {
					$out[] = (string) $row['device_id'];
				}
			}
			return $out;
		}
		if ( false !== strpos( $query, 'SELECT key_id FROM %i WHERE revoked_at IS NOT NULL' ) ) {
			foreach ( $this->devices as $row ) {
				if ( null !== $row['revoked_at'] && (int) $row['revoked_at'] >= (int) $args[1] ) {
					$out[] = (string) $row['key_id'];
				}
			}
			return $out;
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
		$id              = $this->next++;
		$this->insert_id = $id;
		if ( 'wp_ffc_audience_presence_devices' === $table ) {
			$this->devices[ $id ] = array_merge(
				array(
					'id'             => $id,
					'signals_hash'   => null,
					'revoked_at'     => null,
					'revoked_reason' => null,
					'revoked_by'     => null,
					'released_at'    => null,
					'released_by'    => null,
					'attested_at'    => null,
					'signals_dismissed_at' => null,
				),
				$data
			);
			return 1;
		}
		if ( 'wp_ffc_audience_presence_requests' === $table ) {
			$this->requests[ $id ] = array_merge(
				array(
					'id'         => $id,
					'decided_at' => null,
					'decided_by' => null,
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
		if ( 0 === strpos( $query, 'UPDATE %i SET revoked_at = %d, revoked_reason = %s, revoked_by = ' ) ) {
			$by = 5 === count( $args ) ? (int) $args[3] : null;
			$id = (int) end( $args );
			if ( ! isset( $this->devices[ $id ] ) || null !== $this->devices[ $id ]['revoked_at'] ) {
				return 0;
			}
			$this->devices[ $id ]['revoked_at']     = (int) $args[1];
			$this->devices[ $id ]['revoked_reason'] = (string) $args[2];
			$this->devices[ $id ]['revoked_by']     = $by;
			return 1;
		}
		if ( 'UPDATE %i SET attested_at = %d WHERE id = %d' === $query ) {
			$id = (int) $args[2];
			if ( ! isset( $this->devices[ $id ] ) ) {
				return 0;
			}
			$this->devices[ $id ]['attested_at'] = (int) $args[1];
			return 1;
		}
		if ( 0 === strpos( $query, 'UPDATE %i SET released_at = %d, released_by = %d WHERE device_id = %s AND user_id <> %d' ) ) {
			$count = 0;
			foreach ( $this->devices as $id => $row ) {
				if ( $row['device_id'] === $args[3] && (int) $row['user_id'] !== (int) $args[4] && null === $row['released_at'] ) {
					$this->devices[ $id ]['released_at'] = (int) $args[1];
					$this->devices[ $id ]['released_by'] = (int) $args[2];
					++$count;
				}
			}
			return $count;
		}
		if ( 0 === strpos( $query, 'UPDATE %i SET status = %s, decided_at = %d, decided_by = %d WHERE id = %d AND status = %s' ) ) {
			$id = (int) $args[4];
			if ( ! isset( $this->requests[ $id ] ) || $this->requests[ $id ]['status'] !== $args[5] ) {
				return 0;
			}
			$this->requests[ $id ]['status']     = (string) $args[1];
			$this->requests[ $id ]['decided_at'] = (int) $args[2];
			$this->requests[ $id ]['decided_by'] = (int) $args[3];
			return 1;
		}
		if ( 0 === strpos( $query, 'UPDATE %i SET signals_dismissed_at = %d WHERE signals_hash = %s' ) ) {
			$count = 0;
			foreach ( $this->devices as $id => $row ) {
				if ( $row['signals_hash'] === $args[2] && null === $row['revoked_at'] && null === $row['signals_dismissed_at'] ) {
					$this->devices[ $id ]['signals_dismissed_at'] = (int) $args[1];
					++$count;
				}
			}
			return $count;
		}
		if ( 0 === strpos( $query, 'UPDATE %i SET status = %s WHERE id = %d AND status = %s' ) ) {
			$id = (int) $args[2];
			if ( ! isset( $this->requests[ $id ] ) || $this->requests[ $id ]['status'] !== $args[3] ) {
				return 0;
			}
			$this->requests[ $id ]['status'] = (string) $args[1];
			return 1;
		}
		throw new \LogicException( 'Unknown query: ' . $query );
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
