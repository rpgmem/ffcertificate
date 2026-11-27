<?php
/**
 * CertificateTerminalReadingRepository
 *
 * Reads and writes `ffc_certificate_terminal_readings` (#2891): one row per
 * certificate form and person a terminal read, holding the result the
 * person got and the certificate it issued. No personal data is stored here
 * beyond the account id; the certificate holds the rest.
 *
 * @package FreeFormCertificate\Frontend
 * @since 6.52.0
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against ffc_certificate_terminal_readings, the plugin's own table, which WordPress exposes no API for. A row is read once per reading and never cached.

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The terminal readings of certificate forms.
 *
 * @phpstan-type ReadingRow array{id: int, form_id: int, user_id: int|null, terminal_id: int, read_at: int, result: string, reason: string, missing: list<string>, submission_id: int|null, updated_at: int}
 * @phpstan-type ReadingPageRow array{id: int, form_id: int, user_id: int|null, terminal_id: int, read_at: int, result: string, reason: string, missing: list<string>, submission_id: int|null, updated_at: int, auth_code: string}
 */
final class CertificateTerminalReadingRepository {

	/** The certificate was issued, now or before. */
	public const ISSUED = 'issued';

	/** Attendance was recorded; the certificate waits on data the profile lacks. */
	public const AWAITING_DATA = 'awaiting_data';

	/** The reading was refused. */
	public const REFUSED = 'refused';

	/**
	 * How strong a result is: a stronger one replaces a weaker one, never the
	 * other way round, so the row keeps the best a person got.
	 */
	private const RANK = array(
		self::REFUSED       => 0,
		self::AWAITING_DATA => 1,
		self::ISSUED        => 2,
	);

	/**
	 * A person's reading of a form, or null.
	 *
	 * @param int $form_id Form id.
	 * @param int $user_id Account id.
	 * @return ReadingRow|null
	 */
	public static function for_person( int $form_id, int $user_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE form_id = %d AND user_id = %d LIMIT 1',
				CertificateTerminalActivator::table(),
				$form_id,
				$user_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * How many people got each result on a form.
	 *
	 * @param int $form_id Form id.
	 * @return array{issued: int, awaiting_data: int, refused: int}
	 */
	public static function counts( int $form_id ): array {
		global $wpdb;

		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT result, COUNT(*) AS total FROM %i WHERE form_id = %d GROUP BY result',
				CertificateTerminalActivator::table(),
				$form_id
			),
			ARRAY_A
		);
		$counts = array(
			self::ISSUED        => 0,
			self::AWAITING_DATA => 0,
			self::REFUSED       => 0,
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result = is_array( $row ) ? \FreeFormCertificate\Core\ArrayValue::string( $row, 'result' ) : '';
			if ( isset( $counts[ $result ] ) ) {
				$counts[ $result ] = \FreeFormCertificate\Core\ArrayValue::int( $row, 'total' );
			}
		}
		return $counts;
	}

	/**
	 * One reading by its row id, or null.
	 *
	 * @param int $id Row id.
	 * @return ReadingRow|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$row = $id > 0 ? $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', CertificateTerminalActivator::table(), $id ),
			ARRAY_A
		) : null;
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * One page of a form's readings, latest first, each with the validation
	 * code of the certificate it issued (#2892).
	 *
	 * @param int            $form_id  Form id.
	 * @param string         $result   One of the result constants, or '' for every result.
	 * @param list<int>|null $user_ids Only these accounts, or null for everyone.
	 * @param int            $limit    Rows per page.
	 * @param int            $offset   Rows to skip.
	 * @return list<ReadingPageRow>
	 */
	public static function page( int $form_id, string $result, ?array $user_ids, int $limit, int $offset ): array {
		global $wpdb;

		if ( array() === $user_ids ) {
			return array();
		}
		$where = self::where( $form_id, $result, $user_ids );
		$sql   = 'SELECT r.*, s.auth_code FROM %i r LEFT JOIN %i s ON s.id = r.submission_id WHERE ' . $where['sql'] . ' ORDER BY r.read_at DESC, r.id DESC LIMIT %d OFFSET %d';
		$args  = array_merge(
			array( CertificateTerminalActivator::table(), \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table() ),
			$where['args'],
			array( max( 1, $limit ), max( 0, $offset ) )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only placeholders and literal column names (where()); $args carries one value per placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$page = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) ) {
				$page[] = self::shape( $row ) + array( 'auth_code' => \FreeFormCertificate\Core\ArrayValue::string( $row, 'auth_code' ) );
			}
		}
		return $page;
	}

	/**
	 * How many readings {@see self::page()} pages through.
	 *
	 * @param int            $form_id  Form id.
	 * @param string         $result   One of the result constants, or ''.
	 * @param list<int>|null $user_ids Only these accounts, or null for everyone.
	 * @return int
	 */
	public static function count_matching( int $form_id, string $result, ?array $user_ids ): int {
		global $wpdb;

		if ( array() === $user_ids ) {
			return 0;
		}
		$where = self::where( $form_id, $result, $user_ids );
		$sql   = 'SELECT COUNT(*) FROM %i r WHERE ' . $where['sql'];
		$args  = array_merge( array( CertificateTerminalActivator::table() ), $where['args'] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only placeholders and literal column names (where()); $args carries one value per placeholder.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * The filter shared by {@see self::page()} and {@see self::count_matching()}.
	 *
	 * @param int            $form_id  Form id.
	 * @param string         $result   One of the result constants, or ''.
	 * @param list<int>|null $user_ids Only these accounts, or null; never empty.
	 * @return array{sql: string, args: list<int|string>}
	 */
	private static function where( int $form_id, string $result, ?array $user_ids ): array {
		$sql  = 'r.form_id = %d';
		$args = array( $form_id );
		if ( isset( self::RANK[ $result ] ) ) {
			$sql   .= ' AND r.result = %s';
			$args[] = $result;
		}
		if ( null !== $user_ids ) {
			$sql .= ' AND r.user_id IN (' . implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) ) . ')';
			foreach ( $user_ids as $user_id ) {
				$args[] = $user_id;
			}
		}
		return array(
			'sql'  => $sql,
			'args' => $args,
		);
	}

	/**
	 * Record what a reading gave a person.
	 *
	 * A first reading inserts the row. A later one replaces it only when its
	 * result is at least as strong, so a refusal never overwrites an issued
	 * certificate.
	 *
	 * @param int      $form_id       Form id.
	 * @param int      $user_id       Account id.
	 * @param int      $terminal_id   Terminal that read it.
	 * @param int      $read_at       Moment of the reading.
	 * @param string   $result        One of the result constants.
	 * @param string   $reason        Reason code of a refusal, or ''.
	 * @param array    $missing       Field names the profile could not fill.
	 * @param int|null $submission_id Certificate it issued, or null.
	 * @param int      $now           Unix time.
	 * @phpstan-param list<string> $missing
	 * @return bool Whether a row was written.
	 */
	public static function record( int $form_id, int $user_id, int $terminal_id, int $read_at, string $result, string $reason, array $missing, ?int $submission_id, int $now ): bool {
		global $wpdb;

		$existing = self::for_person( $form_id, $user_id );
		$fields   = array(
			'terminal_id'   => $terminal_id,
			'read_at'       => $read_at,
			'result'        => $result,
			'reason'        => substr( $reason, 0, 64 ),
			'missing'       => array() === $missing ? null : (string) wp_json_encode( $missing ),
			'submission_id' => $submission_id,
			'updated_at'    => $now,
		);
		$formats  = array( '%d', '%d', '%s', '%s', '%s', '%d', '%d' );

		if ( null === $existing ) {
			$inserted = $wpdb->insert(
				CertificateTerminalActivator::table(),
				array(
					'form_id' => $form_id,
					'user_id' => $user_id,
				) + $fields,
				array_merge( array( '%d', '%d' ), $formats )
			);
			return false !== $inserted;
		}

		if ( ( self::RANK[ $result ] ?? 0 ) < ( self::RANK[ $existing['result'] ] ?? 0 ) ) {
			return false;
		}
		$updated = $wpdb->update( CertificateTerminalActivator::table(), $fields, array( 'id' => $existing['id'] ), $formats, array( '%d' ) );
		return false !== $updated;
	}

	/**
	 * Type one raw row.
	 *
	 * @param array<array-key, mixed> $row Raw row.
	 * @return ReadingRow
	 */
	private static function shape( array $row ): array {
		$missing = json_decode( is_string( $row['missing'] ?? null ) ? $row['missing'] : '', true );
		return array(
			'id'            => \FreeFormCertificate\Core\ArrayValue::int( $row, 'id' ),
			'form_id'       => \FreeFormCertificate\Core\ArrayValue::int( $row, 'form_id' ),
			'user_id'       => null === ( $row['user_id'] ?? null ) ? null : \FreeFormCertificate\Core\ArrayValue::int( $row, 'user_id' ),
			'terminal_id'   => \FreeFormCertificate\Core\ArrayValue::int( $row, 'terminal_id' ),
			'read_at'       => \FreeFormCertificate\Core\ArrayValue::int( $row, 'read_at' ),
			'result'        => \FreeFormCertificate\Core\ArrayValue::string( $row, 'result' ),
			'reason'        => \FreeFormCertificate\Core\ArrayValue::string( $row, 'reason' ),
			'missing'       => is_array( $missing ) ? array_values( array_filter( $missing, 'is_string' ) ) : array(),
			'submission_id' => null === ( $row['submission_id'] ?? null ) ? null : \FreeFormCertificate\Core\ArrayValue::int( $row, 'submission_id' ),
			'updated_at'    => \FreeFormCertificate\Core\ArrayValue::int( $row, 'updated_at' ),
		);
	}
}
