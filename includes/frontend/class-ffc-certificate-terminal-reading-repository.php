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
