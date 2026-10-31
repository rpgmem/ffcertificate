<?php
/**
 * Recruitment PCD-per-notice migration strategy.
 *
 * Copies the PCD flag each candidate carried onto every one of its notice
 * classifications (#2245).
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Recruitment\RecruitmentPcdHasher;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Data statements against the plugin's own ffc_recruitment_* tables during a migration. WordPress exposes no API for them, and there is nothing to cache on this path.
/**
 * Strategy that moves PCD from the candidate to its classifications.
 *
 * WHY IT MOVES
 *
 * PCD was stored once per candidate, so an import for another notice
 * overwrote it, and its hash was keyed on `wp_salt('auth')`, so rotating the
 * WordPress salts turned every flag into general. It now lives on each notice
 * classification, keyed on the plugin's own salt. Imports write the new
 * column; this card fills it for the rows written before.
 *
 * WHAT A FLAG THAT CANNOT BE READ BECOMES
 *
 * A candidate hash that matches neither domain (the salts were rotated
 * already) is recorded as general (decided in #2245): that is what every
 * screen has shown for it, so nothing visible changes. The card counts how
 * many, for review by hand.
 *
 * WHY IT TERMINATES
 *
 * A row is pending while its `pcd_hash` is NULL, and every row the batch
 * reads is written, general included. The id cursor moves past a row whose
 * write failed, so a run ends at the first batch that reads nothing, whatever
 * the writes did (#1378). That empty read rewinds the cursor, so the next run
 * retries what failed instead of reporting it pending forever.
 */
class RecruitmentPcdPerNoticeMigrationStrategy implements MigrationStrategyInterface {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Where the cursor and the unreadable count live. Listed in `uninstall.php`.
	 *
	 * @var string
	 */
	public const STATE_OPTION = 'ffc_recruitment_pcd_per_notice_state';

	/**
	 * Classifications table.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Candidates table.
	 *
	 * @var string
	 */
	private string $candidates;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table      = $wpdb->prefix . 'ffc_recruitment_classification';
		$this->candidates = $wpdb->prefix . 'ffc_recruitment_candidate';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		if ( ! self::table_exists( $this->table ) ) {
			return array(
				'total'       => 0,
				'migrated'    => 0,
				'pending'     => 0,
				'percent'     => 100,
				'is_complete' => true,
			);
		}

		global $wpdb;
		$total   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table ) );
		$pending = $this->count_pending();
		$done    = max( 0, $total - $pending );

		return array(
			'total'       => $total,
			'migrated'    => $done,
			'pending'     => $pending,
			'percent'     => $total > 0 ? round( ( $done / $total ) * 100, 2 ) : 100,
			'is_complete' => 0 === $pending,
		);
	}

	/**
	 * Execute one batch.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number     Ignored; the cursor is the position.
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		if ( ! self::table_exists( $this->table ) ) {
			return array(
				'success'   => true,
				'processed' => 0,
				'has_more'  => false,
				'message'   => __( 'The recruitment tables are not available — nothing to migrate.', 'ffcertificate' ),
				'errors'    => array(),
			);
		}

		global $wpdb;

		$batch_size = max( 1, ArrayValue::int( $migration_config, 'batch_size', 200 ) );
		$state      = $this->get_state();
		$cursor     = $state['cursor'];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.id, c.notice_id, c.candidate_id, c.adjutancy_id, cand.pcd_hash AS candidate_pcd_hash
				FROM %i c LEFT JOIN %i cand ON cand.id = c.candidate_id
				WHERE c.id > %d AND c.pcd_hash IS NULL ORDER BY c.id ASC LIMIT %d',
				$this->table,
				$this->candidates,
				$cursor,
				$batch_size
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$changed    = 0;
		$unreadable = 0;
		$errors     = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id           = ArrayValue::int( $row, 'id' );
			$cursor       = $id;
			$candidate_id = ArrayValue::int( $row, 'candidate_id' );
			$flag         = RecruitmentPcdHasher::verify( ArrayValue::string( $row, 'candidate_pcd_hash' ), $candidate_id );
			if ( null === $flag ) {
				++$unreadable;
			}
			$hash    = RecruitmentPcdHasher::compute_for_row( ArrayValue::int( $row, 'notice_id' ), $candidate_id, ArrayValue::int( $row, 'adjutancy_id' ), true === $flag );
			$updated = $wpdb->update( $this->table, array( 'pcd_hash' => $hash ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
			if ( false === $updated ) {
				$errors[] = (string) $wpdb->last_error;
				continue;
			}
			$changed += (int) $updated;
		}

		// A run ends on the read that finds nothing past the cursor; rewinding
		// it there is what lets the next run retry a failed write.
		$state['cursor']      = empty( $rows ) ? 0 : $cursor;
		$state['unreadable'] += $unreadable;
		update_option( self::STATE_OPTION, $state, false );

		$pending = $this->count_pending();

		return array(
			'success'   => empty( $errors ),
			'processed' => $changed,
			'has_more'  => ! empty( $rows ) && $pending > 0,
			'message'   => sprintf(
				/* translators: 1: classifications written, 2: classifications whose PCD could not be read so far */
				__( 'Recorded the PCD flag on %1$d classifications. %2$d candidate flags could not be read and were recorded as general; review them by hand.', 'ffcertificate' ),
				$changed,
				$state['unreadable']
			),
			'errors'    => $errors,
		);
	}

	/**
	 * Classifications still without a PCD hash.
	 *
	 * @return int
	 */
	private function count_pending(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pcd_hash IS NULL', $this->table ) );
	}

	/**
	 * Cursor and the running count of unreadable flags.
	 *
	 * @return array{cursor: int, unreadable: int}
	 */
	private function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'cursor'     => ArrayValue::int( $state, 'cursor' ),
			'unreadable' => ArrayValue::int( $state, 'unreadable' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		return true;
	}

	/**
	 * Strategy name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Recruitment: PCD per Notice', 'ffcertificate' );
	}
}
