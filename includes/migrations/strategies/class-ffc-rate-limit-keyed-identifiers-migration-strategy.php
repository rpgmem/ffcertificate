<?php
/**
 * RateLimitKeyedIdentifiersMigrationStrategy
 *
 * Until #2343 the rate-limit counter and log tables identified an e-mail or
 * a CPF/RF by its unkeyed sha256. A CPF has about a billion values, so that
 * hash is reversed by trying them all. New rows are keyed with the plugin's
 * hash secret; this strategy deletes the older ones.
 *
 * A hash cannot be re-keyed, since the value is not stored anywhere, so the
 * rows are deleted rather than rewritten. They are short-lived anyway: a
 * counter covers a day, a week or a month, and the log keeps 30 days by
 * default. Deleting them resets those counters once, which the keyed hash
 * already does on its own (an old key simply misses).
 *
 * The rows written before the change are told apart from the ones after by
 * id: `RateLimitActivator` records the last id of each table the first time
 * this release's schema runs. Termination does not depend on a cursor
 * (CLAUDE.md "Batched migrations"): a deleted row leaves the predicate, and
 * the batch counts the rows the DELETE removed.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\ArrayValue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Data statements against the plugin's own ffc_rate_limits / ffc_rate_limit_logs tables during a migration. WordPress exposes no API for them, and there is nothing to cache on this path.
/**
 * Strategy that deletes rate-limit rows keyed by an unkeyed e-mail or CPF hash.
 */
class RateLimitKeyedIdentifiersMigrationStrategy implements MigrationStrategyInterface {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * The option the activator records the cutover in.
	 */
	private const CUTOVER_OPTION = 'ffc_rate_limit_keyed_cutover';

	/**
	 * The identifier types that are now keyed.
	 */
	private const TYPES = array( 'email', 'cpf' );

	/**
	 * Table suffix => the last id written before the change, for every table
	 * that exists.
	 *
	 * @return array<string, int>
	 */
	private function cutover(): array {
		global $wpdb;

		$stored = get_option( self::CUTOVER_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$tables = array();
		foreach ( array( 'ffc_rate_limits', 'ffc_rate_limit_logs' ) as $suffix ) {
			$last = ArrayValue::int( $stored, $suffix );
			if ( $last > 0 && self::table_exists( $wpdb->prefix . $suffix ) ) {
				$tables[ $suffix ] = $last;
			}
		}
		return $tables;
	}

	/**
	 * Rows still holding an unkeyed e-mail or CPF hash, per table.
	 *
	 * @return array<string, int>
	 */
	private function pending_by_table(): array {
		global $wpdb;

		$pending = array();
		foreach ( $this->cutover() as $suffix => $last ) {
			$pending[ $suffix ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE type IN (%s, %s) AND id <= %d',
					$wpdb->prefix . $suffix,
					self::TYPES[0],
					self::TYPES[1],
					$last
				)
			);
		}
		return $pending;
	}

	/**
	 * Calculate migration status.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		global $wpdb;

		$pending = array_sum( $this->pending_by_table() );
		$total   = 0;
		foreach ( array_keys( $this->cutover() ) as $suffix ) {
			$total += (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type IN (%s, %s)', $wpdb->prefix . $suffix, self::TYPES[0], self::TYPES[1] )
			);
		}
		$total    = max( $total, $pending );
		$migrated = $total - $pending;

		return array(
			'total'       => $total,
			'migrated'    => $migrated,
			'pending'     => $pending,
			'percent'     => $total > 0 ? round( ( $migrated / $total ) * 100, 2 ) : 100,
			'is_complete' => 0 === $pending,
		);
	}

	/**
	 * Execute one batch.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number     Batch number (unused -- the predicate drives progress).
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		global $wpdb;

		$batch_size = max( 1, ArrayValue::int( $migration_config, 'batch_size', 500 ) );
		$deleted    = 0;
		$errors     = array();

		foreach ( $this->cutover() as $suffix => $last ) {
			$rows = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE type IN (%s, %s) AND id <= %d LIMIT %d',
					$wpdb->prefix . $suffix,
					self::TYPES[0],
					self::TYPES[1],
					$last,
					$batch_size
				)
			);
			if ( false === $rows ) {
				$errors[] = $wpdb->last_error;
				continue;
			}
			$deleted += (int) $rows;
		}

		$pending = array_sum( $this->pending_by_table() );

		return array(
			'success'   => array() === $errors,
			'processed' => $deleted,
			// Only a batch that removed something may ask for another.
			'has_more'  => $deleted > 0 && $pending > 0,
			/* translators: %d: number of rate limit rows deleted. */
			'message'   => sprintf( __( 'Deleted %d rate limit rows identified by an unkeyed e-mail or CPF/RF hash.', 'ffcertificate' ), $deleted ),
			'errors'    => $errors,
		);
	}

	/**
	 * Whether the migration can run. Deleting needs no key, so it always can.
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
		return __( 'Rate Limit: Keyed Identifiers', 'ffcertificate' );
	}
}
