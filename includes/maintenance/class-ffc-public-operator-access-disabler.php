<?php
/**
 * PublicOperatorAccessDisabler
 *
 * Maintenance tool that switches off "Public Operator Access" (the public CSV
 * download / operator console feature) on published forms whose collection
 * period ended more than the grace window ago.
 *
 * "Disable" means flipping the five `_ffc_csv_public_*_enabled` flags to `'0'`.
 * The configuration meta (`_ffc_csv_public_hash`, `_limit`, `_count`,
 * `_cpf_mode`, `_cpf_whitelist`) is intentionally left untouched so the access
 * can be re-enabled later without reconfiguring — only the *behaviour* is
 * turned off.
 *
 * "Old" reuses {@see Geofence::has_form_expired_by_days()} — the single source
 * of truth for "is this form over" — so the definition of expired matches the
 * obsolete-shortcode cleaner.
 *
 * Honours `dry_run`: a dry run reports which forms would be disabled without
 * writing any meta.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.7.x
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Security\Geofence;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Disables Public Operator Access on expired forms.
 */
class PublicOperatorAccessDisabler implements MaintenanceToolInterface {

	/**
	 * Default grace window (days) when none is configured.
	 */
	const DEFAULT_DAYS = 90;

	/**
	 * Max forms detailed in the report; extra forms are counted but not listed.
	 */
	const REPORT_LIMIT = 50;

	/**
	 * The enable flags switched off (master + four sub-features). Config meta
	 * is deliberately NOT in this list — disabling preserves it.
	 *
	 * @var array<int, string>
	 */
	const ENABLE_FLAGS = array(
		'_ffc_csv_public_enabled',
		'_ffc_csv_public_download_enabled',
		'_ffc_csv_public_preview_enabled',
		'_ffc_csv_public_start_early_enabled',
		'_ffc_csv_public_extend_end_enabled',
	);

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'public_access_disabler';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Disable Public Operator Access on old forms', 'ffcertificate' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Switch off Public Operator Access (and its sub-features) on published forms whose collection period ended more than the grace window ago. The access token and other configuration are preserved, so it can be re-enabled later.', 'ffcertificate' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_actionable(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array{days:int, dry_run:bool}
	 */
	public function get_default_options(): array {
		return array(
			'days'    => self::DEFAULT_DAYS,
			'dry_run' => true,
		);
	}

	/**
	 * Run the tool (or a dry run) and return a structured report.
	 *
	 * @param array{days?:int, dry_run?:bool} $options Run options.
	 * @return array{
	 *     dry_run: bool,
	 *     days: int,
	 *     candidates: int,
	 *     disabled: int,
	 *     truncated: bool,
	 *     affected: array<int, array{form_id:int, title:string}>
	 * }
	 */
	public function run( array $options ): array {
		$dry_run = ! empty( $options['dry_run'] );
		$days    = isset( $options['days'] ) ? max( 0, (int) $options['days'] ) : self::DEFAULT_DAYS;

		$form_ids = $this->find_candidate_form_ids( $days );

		$report = array(
			'dry_run'    => $dry_run,
			'days'       => $days,
			'candidates' => count( $form_ids ),
			'disabled'   => 0,
			'truncated'  => false,
			'affected'   => array(),
		);

		foreach ( $form_ids as $form_id ) {
			if ( ! $dry_run ) {
				$this->disable_public_access( $form_id );
				++$report['disabled'];
			}

			if ( count( $report['affected'] ) < self::REPORT_LIMIT ) {
				$post                 = get_post( $form_id );
				$report['affected'][] = array(
					'form_id' => $form_id,
					'title'   => $post ? (string) $post->post_title : '',
				);
			}
		}

		$report['truncated'] = $report['candidates'] > self::REPORT_LIMIT;

		return $report;
	}

	/**
	 * Published `ffc_form` IDs that have Public Operator Access enabled AND
	 * whose collection period ended more than `$days` ago.
	 *
	 * @param int $days Grace window in days (>= 0).
	 * @return array<int, int> Form IDs.
	 */
	public function find_candidate_form_ids( int $days ): array {
		if ( $days < 0 ) {
			$days = 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'ffc_form',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off admin maintenance action, not a hot path.
					array(
						'key'   => '_ffc_csv_public_enabled',
						'value' => '1',
					),
				),
			)
		);

		/**
		 * Post IDs returned by the query.
		 *
		 * @var array<int, int> $post_ids
		 */
		$post_ids = $query->posts;
		$matched  = array();
		foreach ( $post_ids as $form_id ) {
			$form_id = (int) $form_id;
			if ( Geofence::has_form_expired_by_days( $form_id, $days ) ) {
				$matched[] = $form_id;
			}
		}

		return $matched;
	}

	/**
	 * Switch off every enable flag for a form, preserving its config meta.
	 *
	 * @param int $form_id Target form.
	 * @return void
	 */
	public function disable_public_access( int $form_id ): void {
		foreach ( self::ENABLE_FLAGS as $flag ) {
			update_post_meta( $form_id, $flag, '0' );
		}
	}
}
