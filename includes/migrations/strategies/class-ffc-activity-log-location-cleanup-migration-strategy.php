<?php
/**
 * ActivityLogLocationCleanupMigrationStrategy
 *
 * Until #2040 every geofence area check wrote the visitor's coordinates to
 * the activity log as an `ip_geolocation_debug` row: the participant's exact
 * GPS position on each audience check-in, and the position an IP resolved
 * to on each IP check, in clear. Those rows are debug output with no audit
 * value, so this strategy deletes them; the area check now logs the
 * decision only, and only while the geolocation debug toggle is on.
 *
 * Termination does not depend on a cursor: each batch deletes rows matching
 * the pending predicate, and a batch that deletes nothing reports nothing
 * left (#1378).
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\ArrayValue;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Data statements against the plugin's own ffc_activity_log table during a migration. WordPress exposes no API for it, and there is nothing to cache on this path.
/**
 * Strategy for deleting the activity-log rows that carry a visitor's position.
 */
class ActivityLogLocationCleanupMigrationStrategy implements MigrationStrategyInterface {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * The action the area check logged under.
	 */
	public const ACTION = 'ip_geolocation_debug';

	/**
	 * Activity log table.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ffc_activity_log';
	}

	/**
	 * Calculate migration status.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		$pending = $this->pending();

		return array(
			'total'       => $pending,
			'migrated'    => 0,
			'pending'     => $pending,
			'percent'     => 0 === $pending ? 100 : 0,
			'is_complete' => 0 === $pending,
		);
	}

	/**
	 * Delete one batch of rows.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number Batch number (unused: each batch deletes what it matches).
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		$batch_size = max( 1, ArrayValue::int( $migration_config, 'batch_size', 500 ) );

		if ( ! self::table_exists( $this->table ) ) {
			return array(
				'success'   => true,
				'processed' => 0,
				'has_more'  => false,
				'message'   => __( 'Activity log table is not available — nothing to migrate.', 'ffcertificate' ),
				'errors'    => array(),
			);
		}

		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE action = %s ORDER BY id ASC LIMIT %d',
				$this->table,
				self::ACTION,
				$batch_size
			)
		);

		if ( false === $deleted ) {
			return array(
				'success'   => false,
				'processed' => 0,
				'has_more'  => false,
				'message'   => __( 'Could not delete the geolocation debug entries.', 'ffcertificate' ),
				'errors'    => array( $wpdb->last_error ),
			);
		}

		// Counted by what was deleted, so a batch that deletes nothing ends
		// the run whatever the count query says (#1378).
		$deleted = (int) $deleted;

		return array(
			'success'   => true,
			'processed' => $deleted,
			'has_more'  => $deleted > 0 && $this->pending() > 0,
			/* translators: %d: number of activity log entries deleted. */
			'message'   => sprintf( __( 'Deleted %d geolocation debug entries.', 'ffcertificate' ), $deleted ),
			'errors'    => array(),
		);
	}

	/**
	 * Whether the migration can run now.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		if ( ! self::table_exists( $this->table ) ) {
			return new WP_Error(
				'activity_log_table_missing',
				__( 'Activity log table is missing — re-activate the plugin to create it.', 'ffcertificate' )
			);
		}
		return true;
	}

	/**
	 * Strategy name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Activity Log: Delete Logged Locations', 'ffcertificate' );
	}

	/**
	 * Rows still carrying a logged position.
	 *
	 * @return int
	 */
	private function pending(): int {
		if ( ! self::table_exists( $this->table ) ) {
			return 0;
		}

		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action = %s', $this->table, self::ACTION )
		);
	}
}
