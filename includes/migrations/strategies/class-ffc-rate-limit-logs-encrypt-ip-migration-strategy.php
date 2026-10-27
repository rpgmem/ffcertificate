<?php
/**
 * RateLimitLogsEncryptIpMigrationStrategy
 *
 * The rate-limit log wrote the request address in clear to `ip_address`,
 * and used the address itself as the `identifier` of `ip` rows, until
 * #2109. New rows keep the address only encrypted, in
 * `ip_address_encrypted`, and identify a caller by its address fingerprint.
 * This strategy brings older rows in line:
 *
 * - the plaintext address is encrypted into `ip_address_encrypted` (when
 *   that column is still empty) and `ip_address` is NULLed;
 * - the identifier of an `ip`, `blacklist` or `whitelist` row, which was
 *   the address or its unkeyed sha256, becomes the address fingerprint, so
 *   the statistics keep grouping old and new rows of one caller together.
 *
 * Termination does not depend on a cursor (CLAUDE.md "Batched migrations"):
 * a row is selected while it holds a plaintext address in either column,
 * and its write removes both -- `ip_address` becomes NULL and the
 * identifier becomes a fingerprint, which is hex and never looks like an
 * address -- so a processed row leaves the predicate. The batch counts the
 * rows the UPDATE changed. An address that cannot be encrypted is cleared
 * all the same: dropping it is the safe outcome, a plaintext IP left behind
 * is not.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\IpAddress;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Data statements against the plugin's own ffc_rate_limit_logs table during a migration. WordPress exposes no API for it, and there is nothing to cache on this path.
/**
 * Strategy that removes plaintext addresses from older rate-limit log rows.
 */
class RateLimitLogsEncryptIpMigrationStrategy implements MigrationStrategyInterface {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Row types whose identifier is the request address.
	 */
	private const ADDRESS_TYPES = array( 'ip', 'blacklist', 'whitelist' );

	/**
	 * Rate-limit log table.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ffc_rate_limit_logs';
	}

	/**
	 * Whether the table carries both columns this strategy reads and writes.
	 *
	 * @return bool
	 */
	private function schema_ready(): bool {
		return self::table_exists( $this->table )
			&& self::column_exists( $this->table, 'ip_address' )
			&& self::column_exists( $this->table, 'ip_address_encrypted' );
	}

	/**
	 * Calculate migration status.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		if ( ! $this->schema_ready() ) {
			return array(
				'total'       => 0,
				'migrated'    => 0,
				'pending'     => 0,
				'percent'     => 100,
				'is_complete' => true,
			);
		}

		global $wpdb;

		// Pending: an address still in `ip_address`, or the identifier of an
		// `ip` row (an address carries a dot or a colon, a fingerprint is hex).
		list( $dot, $colon ) = $this->address_patterns();

		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE (ip_address IS NOT NULL AND ip_address <> '') OR (type = 'ip' AND (identifier LIKE %s OR identifier LIKE %s))",
				$this->table,
				$dot,
				$colon
			)
		);
		$total   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table ) );

		$migrated = max( 0, $total - $pending );
		$percent  = $total > 0 ? ( $migrated / $total ) * 100 : 100;

		return array(
			'total'       => $total,
			'migrated'    => $migrated,
			'pending'     => $pending,
			'percent'     => round( $percent, 2 ),
			'is_complete' => 0 === $pending,
		);
	}

	/**
	 * Execute one batch.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number Batch number (unused -- the predicate drives progress).
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		$batch_size = max( 1, ArrayValue::int( $migration_config, 'batch_size', 200 ) );

		if ( ! $this->schema_ready() ) {
			return array(
				'success'   => true,
				'processed' => 0,
				'has_more'  => false,
				'message'   => __( 'Rate limit log table is not available — nothing to migrate.', 'ffcertificate' ),
				'errors'    => array(),
			);
		}

		global $wpdb;

		list( $dot, $colon ) = $this->address_patterns();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, identifier, ip_address, ip_address_encrypted FROM %i WHERE (ip_address IS NOT NULL AND ip_address <> '') OR (type = 'ip' AND (identifier LIKE %s OR identifier LIKE %s)) ORDER BY id ASC LIMIT %d",
				$this->table,
				$dot,
				$colon,
				$batch_size
			),
			ARRAY_A
		);

		$changed = 0;
		$errors  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$update  = $this->cleaned( $row );
			$updated = $wpdb->update(
				$this->table,
				$update,
				array( 'id' => ArrayValue::int( $row, 'id' ) ),
				array_fill( 0, count( $update ), '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				$errors[] = $wpdb->last_error;
				continue;
			}
			$changed += (int) $updated;
		}

		$status = $this->calculate_status( $migration_key, $migration_config );

		return array(
			'success'   => empty( $errors ),
			'processed' => $changed,
			// Only a batch that changed something may ask for another: one
			// that changed nothing would select the same rows forever.
			'has_more'  => $changed > 0 && $status['pending'] > 0,
			/* translators: %d: number of rate limit log rows whose address was encrypted. */
			'message'   => sprintf( __( 'Removed the plaintext address from %d rate limit log rows.', 'ffcertificate' ), $changed ),
			'errors'    => $errors,
		);
	}

	/**
	 * LIKE patterns for an identifier that is an address: it carries a dot
	 * or a colon, where a fingerprint or a hash is hex.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function address_patterns(): array {
		global $wpdb;
		return array( '%' . $wpdb->esc_like( '.' ) . '%', '%' . $wpdb->esc_like( ':' ) . '%' );
	}

	/**
	 * The columns to write for one row: the plaintext address cleared,
	 * encrypted when there is no ciphertext yet, and an address identifier
	 * replaced by the fingerprint of that address.
	 *
	 * @param array<mixed> $row Row as selected.
	 * @return array<string, string|null>
	 */
	private function cleaned( array $row ): array {
		$type       = ArrayValue::string( $row, 'type' );
		$identifier = ArrayValue::string( $row, 'identifier' );
		$address    = ArrayValue::string( $row, 'ip_address' );
		if ( '' === $address && 'ip' === $type ) {
			$address = $identifier;
		}

		$update = array( 'ip_address' => null );

		if ( '' !== $address && '' === ArrayValue::string( $row, 'ip_address_encrypted' ) ) {
			$update['ip_address_encrypted'] = Encryption::is_configured() ? Encryption::encrypt( $address ) : null;
		}

		if ( '' !== $address && in_array( $type, self::ADDRESS_TYPES, true ) ) {
			$update['identifier'] = IpAddress::fingerprint( $address );
		}

		return $update;
	}

	/**
	 * Check whether the migration can run.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		if ( ! Encryption::is_configured() ) {
			return new WP_Error(
				'encryption_not_configured',
				__( 'Encryption keys are not configured, so the addresses cannot be encrypted.', 'ffcertificate' )
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
		return __( 'Rate Limit Log: Encrypt Addresses', 'ffcertificate' );
	}
}
