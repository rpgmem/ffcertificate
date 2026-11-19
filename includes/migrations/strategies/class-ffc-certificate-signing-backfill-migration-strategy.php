<?php
/**
 * Certificate signing backfill migration strategy.
 *
 * Signs the certificates a form issued before it chose a signed QR mode, or
 * before the site had a signing key (#2639).
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Submissions\CertificateSignatureActivator;
use FreeFormCertificate\Submissions\CertificateSigner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs already-issued certificates of the forms on a signed QR mode.
 *
 * WHO IT TOUCHES. A certificate is signed when it is issued, and only when its
 * form is on Compact or Full and a key exists. So every certificate issued
 * before the form switched, or before the first key, has no signed record, and
 * its verification page shows no integrity line. This card signs those, and
 * only those: the published certificates of forms on a signed mode with no
 * current signed record. A form on Link only is never touched, which is what
 * keeps the card opt-in: the administrator chose the mode per form, and the
 * card only catches the past up with that choice.
 *
 * WHAT A RETROACTIVE SIGNATURE SAYS. The record is built from what the
 * database holds now, exactly as an edit re-signs it, so the signature attests
 * "this site holds this certificate as it reads today", not "it read so on the
 * day it was issued". The issue date inside the record is still the original
 * one. A PDF already downloaded keeps the plain link in its QR code; the next
 * download prints the signed one.
 *
 * WHY A CURSOR (#1378). A certificate that cannot be signed (its row cannot be
 * read, the store fails) stays in the predicate, so a cursor-free card would
 * select it on every batch and never stop. The cursor advances over every
 * certificate EXAMINED and the batch reports what it SIGNED, so termination
 * does not depend on signing succeeding. The cursor is tied to the set of
 * forms on a signed mode: switching one more form on restarts the walk, which
 * is also how a certificate that failed gets another try.
 */
class CertificateSigningBackfillMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * Where the cursor and the form set it belongs to live; listed in
	 * `uninstall.php`.
	 *
	 * @var string
	 */
	public const STATE_OPTION = 'ffc_certificate_signing_backfill_state';

	/**
	 * How many certificates one batch examines. Each is read and decrypted.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 25;

	/**
	 * {@inheritDoc}
	 *
	 * Total is every published certificate of a form on a signed mode; pending
	 * is the unsigned ones past the cursor, so a certificate that failed and
	 * was passed counts as done here and is named in the batch message.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		$forms   = $this->signed_forms();
		$total   = array() === $forms ? 0 : $this->count_certificates( $forms, false, 0 );
		$pending = array() === $forms ? 0 : $this->count_certificates( $forms, true, $this->cursor( $forms ) );
		$done    = max( 0, $total - $pending );

		return array(
			'total'       => $total,
			'migrated'    => $done,
			'pending'     => $pending,
			'percent'     => round( $total > 0 ? ( $done / $total ) * 100 : 100, 2 ),
			'is_complete' => 0 === $pending,
		);
	}

	/**
	 * Sign one batch.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number     Ignored; the cursor is the position.
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		$ready = $this->can_run( $migration_key, $migration_config );
		if ( is_wp_error( $ready ) ) {
			return array(
				'success'   => false,
				'processed' => 0,
				'has_more'  => false,
				'message'   => $ready->get_error_message(),
			);
		}

		// A filter can put anything here; `(int) 'x'` is 0 and a batch of 0
		// would report completion having done nothing (#1060).
		$batch_size = isset( $migration_config['batch_size'] ) && is_numeric( $migration_config['batch_size'] )
			? max( 1, (int) $migration_config['batch_size'] )
			: self::BATCH_SIZE;

		$forms = $this->signed_forms();
		$ids   = array() === $forms ? array() : $this->next_unsigned( $forms, $this->cursor( $forms ), $batch_size );
		if ( array() === $ids ) {
			return array(
				'success'   => true,
				'processed' => 0,
				'has_more'  => false,
				'message'   => __( 'Every certificate of a form on a signed QR mode has been examined.', 'ffcertificate' ),
			);
		}

		$signed = 0;
		foreach ( $ids as $submission_id ) {
			if ( $this->sign( $submission_id ) ) {
				++$signed;
			}
			// Advanced whether or not the signature was stored: a certificate
			// that cannot be signed stays unsigned, and reading it again on
			// every batch is the loop this cursor exists to prevent.
			$this->save_cursor( $forms, $submission_id );
		}

		$failed = count( $ids ) - $signed;
		return array(
			'success'   => true,
			// What changed, never what was selected (#1378).
			'processed' => $signed,
			// From the cursor, not from `processed`: a batch whose every
			// certificate failed signed nothing and still moved past them, so
			// the runner must ask for the next one while any remain.
			'has_more'  => $this->count_certificates( $forms, true, end( $ids ) ) > 0,
			'message'   => 0 === $failed
				? sprintf(
					/* translators: %d: number of certificates signed. */
					_n( 'Signed %d certificate.', 'Signed %d certificates.', $signed, 'ffcertificate' ),
					$signed
				)
				: sprintf(
					/* translators: 1: number of certificates signed, 2: number that could not be signed. */
					__( 'Signed %1$d certificates; %2$d could not be signed and are listed in the activity log.', 'ffcertificate' ),
					$signed,
					$failed
				),
		);
	}

	/**
	 * Runnable only once a signing key exists.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|\WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		if ( ! SigningKeys::has_active() ) {
			// The action takes the operator to the card that generates the key
			// (#2656); the card shows it only to whoever may open that screen.
			return new \WP_Error(
				'no_signing_key',
				__( 'Generate a signing key first, under Settings → Certificates.', 'ffcertificate' ),
				array(
					'action' => array(
						'label' => __( 'Generate a signing key', 'ffcertificate' ),
						'url'   => admin_url( 'admin.php?page=ffc-settings&tab=certificates#ffc-integrity-signature' ),
						'cap'   => 'ffc_manage_settings_dangerzone',
						'icon'  => 'key',
					),
				)
			);
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'certificate_signing_backfill';
	}

	/**
	 * The ids of every form on a signed QR mode, ascending.
	 *
	 * The mode lives in a serialized meta array, which SQL cannot filter, and
	 * forms number in the dozens, so they are read here and passed to the
	 * query as a list.
	 *
	 * @return list<int>
	 */
	protected function signed_forms(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'ffc_form',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$out = array();
		foreach ( (array) $ids as $id ) {
			if ( CertificateSigner::MODE_LINK !== CertificateSigner::mode( (int) $id ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Sign one certificate. A seam, so a batch can be driven without a key.
	 *
	 * @param int $submission_id Submission id.
	 * @return bool Whether a signed record was stored.
	 */
	protected function sign( int $submission_id ): bool {
		return CertificateSigner::sign( $submission_id );
	}

	/**
	 * How many published certificates of these forms lie past the cursor,
	 * all of them or only the unsigned ones.
	 *
	 * @param array<int, int> $forms         Form ids, never empty.
	 * @param bool            $unsigned_only Only those with no current signed record.
	 * @param int             $after         Only ids greater than this.
	 * @return int
	 */
	protected function count_certificates( array $forms, bool $unsigned_only, int $after ): int {
		global $wpdb;

		list( $sql, $args ) = $this->population_sql( 'SELECT COUNT(*)', $forms, $unsigned_only, $after );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own tables, for which WordPress exposes no API; the statement is built from placeholders only (see population_sql()). A migration card must reflect the live rows, never a cache.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
	}

	/**
	 * The next unsigned published certificates past the cursor, in id order.
	 *
	 * @param array<int, int> $forms Form ids, never empty.
	 * @param int             $after Only ids greater than this.
	 * @param int             $limit How many.
	 * @return list<int>
	 */
	protected function next_unsigned( array $forms, int $after, int $limit ): array {
		global $wpdb;

		list( $sql, $args ) = $this->population_sql( 'SELECT s.id', $forms, true, $after );
		$sql               .= ' ORDER BY s.id ASC LIMIT %d';
		$args[]             = $limit;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own tables, for which WordPress exposes no API; the statement is built from placeholders only (see population_sql()). A migration card must reflect the live rows, never a cache.
		$found = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );

		$out = array();
		foreach ( (array) $found as $id ) {
			$out[] = (int) $id;
		}
		return $out;
	}

	/**
	 * The statement over the population, as placeholders and their values.
	 *
	 * @param string          $select        The SELECT clause.
	 * @param array<int, int> $forms         Form ids, never empty.
	 * @param bool            $unsigned_only Only those with no current signed record.
	 * @param int             $after         Only ids greater than this.
	 * @return array{0: string, 1: list<int|string>}
	 */
	private function population_sql( string $select, array $forms, bool $unsigned_only, int $after ): array {
		global $wpdb;

		$sql  = $select . ' FROM %i s WHERE s.status = %s AND s.id > %d AND s.form_id IN (' . implode( ',', array_fill( 0, count( $forms ), '%d' ) ) . ')';
		$args = array_merge( array( $wpdb->prefix . 'ffc_submissions', 'publish', $after ), $forms );
		if ( $unsigned_only ) {
			$sql   .= ' AND NOT EXISTS ( SELECT 1 FROM %i g WHERE g.submission_id = s.id AND g.superseded_at IS NULL )';
			$args[] = CertificateSignatureActivator::table();
		}
		return array( $sql, $args );
	}

	/**
	 * The cursor, or zero when the walk has not started or the set of forms
	 * on a signed mode changed since it did.
	 *
	 * @param array<int, int> $forms Form ids on a signed mode now.
	 * @return int
	 */
	private function cursor( array $forms ): int {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || ( $state['forms'] ?? null ) !== self::forms_key( $forms ) ) {
			return 0;
		}
		return is_numeric( $state['cursor'] ?? null ) ? (int) $state['cursor'] : 0;
	}

	/**
	 * Advance the cursor for this set of forms.
	 *
	 * @param array<int, int> $forms         Form ids on a signed mode now.
	 * @param int             $submission_id The certificate just examined.
	 * @return void
	 */
	private function save_cursor( array $forms, int $submission_id ): void {
		update_option(
			self::STATE_OPTION,
			array(
				'forms'  => self::forms_key( $forms ),
				'cursor' => $submission_id,
			),
			false
		);
	}

	/**
	 * The set of forms as a string the state can compare.
	 *
	 * @param array<int, int> $forms Form ids, ascending.
	 */
	private static function forms_key( array $forms ): string {
		return implode( ',', $forms );
	}
}
