<?php
/**
 * CertificateSignatureRepository
 *
 * Reads and writes `ffc_certificate_signatures` (#2639). The record a
 * signature covers names the holder, so it is stored encrypted; the hash and
 * the signatures are not personal data.
 *
 * @package FreeFormCertificate\Submissions
 * @since 6.45.0
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against ffc_certificate_signatures, the plugin's own table, which WordPress exposes no API for. A row is read once per verification and never cached.

namespace FreeFormCertificate\Submissions;

use FreeFormCertificate\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One certificate's signed records.
 *
 * @phpstan-type SignatureRow array{id: numeric-string|int, submission_id: numeric-string|int, key_id: string, record_encrypted: string, record_hash: string, signature_full: string, signature_compact: string, signed_at: numeric-string|int, superseded_at: numeric-string|int|null}
 * @phpstan-type KeyCountRow array{key_id: string, total: numeric-string|int}
 */
final class CertificateSignatureRepository {

	/**
	 * The record a certificate is signed with now, or null when it has none.
	 *
	 * @param int $submission_id Submission id.
	 * @return array{id: int, submission_id: int, key_id: string, record: string, record_hash: string, signature_full: string, signature_compact: string, signed_at: int}|null
	 */
	public static function current( int $submission_id ): ?array {
		global $wpdb;

		/**
		 * The row as `ARRAY_A` returns it.
		 *
		 * @var SignatureRow|null $row
		 */
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE submission_id = %d AND superseded_at IS NULL ORDER BY id DESC LIMIT 1',
				CertificateSignatureActivator::table(),
				$submission_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		$stored = $row['record_encrypted'];
		$record = 0 === strpos( $stored, Encryption::V2_PREFIX ) ? (string) Encryption::decrypt( $stored ) : $stored;

		return array(
			'id'                => (int) $row['id'],
			'submission_id'     => (int) $row['submission_id'],
			'key_id'            => $row['key_id'],
			'record'            => $record,
			'record_hash'       => $row['record_hash'],
			'signature_full'    => $row['signature_full'],
			'signature_compact' => $row['signature_compact'],
			'signed_at'         => (int) $row['signed_at'],
		);
	}

	/**
	 * Store a newly signed record, superseding the one before it.
	 *
	 * @param int    $submission_id     Submission id.
	 * @param string $key_id            Signing key id.
	 * @param string $record            The record string.
	 * @param string $record_hash       Its hash.
	 * @param string $signature_full    Signature of the full payload.
	 * @param string $signature_compact Signature of the compact payload.
	 * @return bool Whether the row was written.
	 */
	public static function store( int $submission_id, string $key_id, string $record, string $record_hash, string $signature_full, string $signature_compact ): bool {
		global $wpdb;

		$now = time();
		self::supersede( $submission_id, $now );

		$encrypted = Encryption::encrypt( $record );

		return false !== $wpdb->insert(
			CertificateSignatureActivator::table(),
			array(
				'submission_id'     => $submission_id,
				'key_id'            => $key_id,
				'record_encrypted'  => null === $encrypted ? $record : $encrypted,
				'record_hash'       => $record_hash,
				'signature_full'    => $signature_full,
				'signature_compact' => $signature_compact,
				'signed_at'         => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Mark a certificate's current record superseded.
	 *
	 * @param int $submission_id Submission id.
	 * @param int $at            Unix time.
	 */
	public static function supersede( int $submission_id, int $at ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET superseded_at = %d WHERE submission_id = %d AND superseded_at IS NULL',
				CertificateSignatureActivator::table(),
				$at,
				$submission_id
			)
		);
	}

	/**
	 * Delete every record of a certificate that no longer exists.
	 *
	 * @param int $submission_id Submission id.
	 */
	public static function delete_for( int $submission_id ): void {
		global $wpdb;

		$wpdb->delete( CertificateSignatureActivator::table(), array( 'submission_id' => $submission_id ), array( '%d' ) );
	}

	/**
	 * How many of a form's certificates carry a current signed record.
	 *
	 * @param int $form_id Form id.
	 */
	public static function count_for_form( int $form_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i g INNER JOIN %i s ON s.id = g.submission_id WHERE g.superseded_at IS NULL AND s.form_id = %d',
				CertificateSignatureActivator::table(),
				$wpdb->prefix . 'ffc_submissions',
				$form_id
			)
		);
	}

	/**
	 * A form's certificates with a current signed record, past a cursor, in
	 * id order.
	 *
	 * @param int $form_id Form id.
	 * @param int $after   Only submission ids greater than this.
	 * @param int $limit   How many.
	 * @return list<int> Submission ids.
	 */
	public static function signed_for_form( int $form_id, int $after, int $limit ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT g.submission_id FROM %i g INNER JOIN %i s ON s.id = g.submission_id WHERE g.superseded_at IS NULL AND s.form_id = %d AND g.submission_id > %d ORDER BY g.submission_id ASC LIMIT %d',
				CertificateSignatureActivator::table(),
				$wpdb->prefix . 'ffc_submissions',
				$form_id,
				$after,
				$limit
			)
		);
		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * The most recently signed current record among a form's certificates,
	 * or null when none is signed.
	 *
	 * @param int $form_id Form id.
	 * @return array{id: int, submission_id: int, key_id: string, record: string, record_hash: string, signature_full: string, signature_compact: string, signed_at: int}|null
	 */
	public static function latest_for_form( int $form_id ): ?array {
		global $wpdb;
		$submission_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT g.submission_id FROM %i g INNER JOIN %i s ON s.id = g.submission_id WHERE g.superseded_at IS NULL AND s.form_id = %d ORDER BY g.id DESC LIMIT 1',
				CertificateSignatureActivator::table(),
				$wpdb->prefix . 'ffc_submissions',
				$form_id
			)
		);
		return $submission_id > 0 ? self::current( $submission_id ) : null;
	}

	/**
	 * How many certificates carry a current signed record, by key.
	 *
	 * @return array<string, int>
	 */
	public static function count_by_key(): array {
		global $wpdb;

		/**
		 * The rows as `ARRAY_A` returns them.
		 *
		 * @var list<KeyCountRow>|null $rows
		 */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT key_id, COUNT(*) AS total FROM %i WHERE superseded_at IS NULL GROUP BY key_id',
				CertificateSignatureActivator::table()
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ $row['key_id'] ] = (int) $row['total'];
		}
		return $out;
	}
}
