<?php
/**
 * CustomFieldAnswersMigrationStrategy
 *
 * Moves the answers stored under an audience's former copy of a field onto
 * the field every audience now inherits (#2502).
 *
 * The copies were folded on upgrade, and the ids they had are kept in
 * {@see CustomFieldAnswerMerge::MAP_OPTION}. Until this runs, a user's answers
 * are rewritten as they are read, so nothing is out of sight; this writes the
 * rewrite once per user and, when every user has been passed, deletes the map.
 *
 * Termination is a cursor over `wp_usermeta`, advanced whether or not a row
 * changed (#1378); `processed` counts the rows that changed.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Reregistration\CustomFieldAnswerMerge;
use FreeFormCertificate\Reregistration\CustomFieldReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strategy for moving custom-field answers onto the inherited field.
 */
class CustomFieldAnswersMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * Option holding the last `umeta_id` examined. Listed in `uninstall.php`.
	 */
	public const CURSOR_OPTION = 'ffc_custom_field_answers_cursor';

	/**
	 * Calculate migration status.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		unset( $migration_key, $migration_config );

		if ( empty( CustomFieldAnswerMerge::map() ) ) {
			return array(
				'total'       => 0,
				'migrated'    => 0,
				'pending'     => 0,
				'percent'     => 100.0,
				'is_complete' => true,
			);
		}

		$total    = $this->count_after( 0 );
		$pending  = $this->count_after( $this->get_cursor() );
		$migrated = max( 0, $total - $pending );

		return array(
			'total'       => $total,
			'migrated'    => $migrated,
			'pending'     => $pending,
			'percent'     => $total > 0 ? round( ( $migrated / $total ) * 100, 2 ) : 100.0,
			'is_complete' => 0 === $pending,
		);
	}

	/**
	 * Rewrite one batch of users' answers.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number     Unused: the cursor carries the position.
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		unset( $migration_key, $batch_number );

		if ( empty( CustomFieldAnswerMerge::map() ) ) {
			return array(
				'success'   => true,
				'processed' => 0,
				'has_more'  => false,
				'message'   => __( 'No answers wait to be moved.', 'ffcertificate' ),
				'errors'    => array(),
			);
		}

		$batch_size = max( 1, ArrayValue::int( $migration_config, 'batch_size', 200 ) );
		$rows       = $this->next_rows( $this->get_cursor(), $batch_size );
		$changed    = 0;

		foreach ( $rows as $row ) {
			$data = maybe_unserialize( $row['meta_value'] );
			if ( is_array( $data ) ) {
				/**
				 * Keys are `field_<id>` strings, written by the field writer only.
				 *
				 * @var array<string, mixed> $data
				 */
				$result = CustomFieldAnswerMerge::converge( $row['user_id'], $data );
				if ( $result['changed'] && false !== update_user_meta( $row['user_id'], CustomFieldReader::USER_META_KEY, $result['data'] ) ) {
					++$changed;
				}
			}
			// Advanced whether or not the row changed (#1378).
			update_option( self::CURSOR_OPTION, $row['umeta_id'], false );
		}

		$has_more = count( $rows ) === $batch_size && $this->count_after( $this->get_cursor() ) > 0;
		if ( ! $has_more ) {
			// Every user was passed: the answers read as they are stored.
			CustomFieldAnswerMerge::clear();
			delete_option( self::CURSOR_OPTION );
		}

		return array(
			'success'   => true,
			'processed' => $changed,
			'has_more'  => $has_more,
			'message'   => sprintf(
				/* translators: 1: users examined, 2: users whose answers moved */
				__( 'Examined %1$d users and moved the answers of %2$d onto the inherited fields.', 'ffcertificate' ),
				count( $rows ),
				$changed
			),
			'errors'    => array(),
		);
	}

	/**
	 * Whether the migration can run now.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool
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
		return __( 'Reregistration: Move Answers to Inherited Fields', 'ffcertificate' );
	}

	/**
	 * Meta rows holding custom-field answers after a `umeta_id`.
	 *
	 * @param int $after Cursor.
	 * @return int
	 */
	private function count_after( int $after ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A keyset read of the custom-field meta during a migration: get_users() cannot page by umeta_id, and a cached count would hide the progress.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE meta_key = %s AND umeta_id > %d',
				$wpdb->usermeta,
				CustomFieldReader::USER_META_KEY,
				$after
			)
		);
	}

	/**
	 * The next meta rows holding custom-field answers.
	 *
	 * @param int $after Cursor.
	 * @param int $limit Batch size.
	 * @return list<array{umeta_id: int, user_id: int, meta_value: string}>
	 */
	private function next_rows( int $after, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A keyset read of the custom-field meta during a migration: get_users() cannot page by umeta_id, and a cached count would hide the progress.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT umeta_id, user_id, meta_value FROM %i WHERE meta_key = %s AND umeta_id > %d ORDER BY umeta_id ASC LIMIT %d',
				$wpdb->usermeta,
				CustomFieldReader::USER_META_KEY,
				$after,
				$limit
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'umeta_id'   => is_numeric( $row['umeta_id'] ?? null ) ? (int) $row['umeta_id'] : 0,
				'user_id'    => is_numeric( $row['user_id'] ?? null ) ? (int) $row['user_id'] : 0,
				'meta_value' => is_string( $row['meta_value'] ?? null ) ? $row['meta_value'] : '',
			);
		}
		return $out;
	}

	/**
	 * The last `umeta_id` examined.
	 *
	 * @return int
	 */
	private function get_cursor(): int {
		$cursor = get_option( self::CURSOR_OPTION, 0 );
		return is_numeric( $cursor ) ? (int) $cursor : 0;
	}
}
