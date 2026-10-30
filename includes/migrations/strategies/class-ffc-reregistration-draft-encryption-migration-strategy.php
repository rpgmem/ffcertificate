<?php
/**
 * Reregistration draft encryption migration strategy.
 *
 * Encrypts the sensitive values reregistration drafts stored in clear before
 * the draft save encrypted them (#2242).
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Reregistration\CustomFieldReader;
use FreeFormCertificate\Reregistration\ReregistrationDataProcessor;
use FreeFormCertificate\Reregistration\ReregistrationRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Data statements against the plugin's own ffc_reregistration_submissions table during a migration. WordPress exposes no API for it, and there is nothing to cache on this path.
/**
 * Strategy that encrypts plaintext sensitive values in stored submissions.
 *
 * WHERE THE PLAINTEXT IS
 *
 * The draft save wrote the form's values straight into `data`, while only the
 * final submit encrypted the fields marked sensitive. So CPF, RF, RG and birth
 * date sat in clear in every draft, in every draft the daily run later
 * expired, and in every submission an operator returned to draft and the
 * member saved again. Which values are sensitive is a property of the
 * campaign's fields, not of the row, so the predicate cannot be written in
 * SQL: each row is read and decided in PHP.
 *
 * WHY IT KEEPS A CURSOR
 *
 * A row that holds nothing to encrypt is examined, left alone, and would be
 * selected again forever without one (#1378). The cursor advances over every
 * row EXAMINED, and the batch counts the rows it actually CHANGED. "Complete"
 * means every stored submission was examined; a row created since runs the
 * new save path, which never writes a sensitive value in clear.
 *
 * @phpstan-import-type CustomFieldRow from \FreeFormCertificate\Reregistration\CustomFieldReader
 */
class ReregistrationDraftEncryptionMigrationStrategy implements MigrationStrategyInterface {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Where the cursor lives. Listed in `uninstall.php`.
	 *
	 * @var string
	 */
	public const STATE_OPTION = 'ffc_rereg_draft_encryption_state';

	/**
	 * Submissions table.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Field definitions per campaign, memoised for one batch.
	 *
	 * @var array<int, list<CustomFieldRow>>
	 */
	private array $fields = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ffc_reregistration_submissions';
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

		$total    = $this->count_after( 0 );
		$pending  = $this->count_after( $this->get_cursor() );
		$examined = max( 0, $total - $pending );
		$percent  = $total > 0 ? ( $examined / $total ) * 100 : 100;

		return array(
			'total'       => $total,
			'migrated'    => $examined,
			'pending'     => $pending,
			'percent'     => round( $percent, 2 ),
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
				'message'   => __( 'The reregistration table is not available — nothing to migrate.', 'ffcertificate' ),
				'errors'    => array(),
			);
		}

		global $wpdb;

		$batch_size = max( 1, ArrayValue::int( $migration_config, 'batch_size', 100 ) );
		$cursor     = $this->get_cursor();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, reregistration_id, data FROM %i WHERE id > %d AND data IS NOT NULL ORDER BY id ASC LIMIT %d',
				$this->table,
				$cursor,
				$batch_size
			),
			ARRAY_A
		);

		/**
		 * Rows as `$wpdb` hands them back, checked against the SELECT above.
		 *
		 * @var list<array{id: numeric-string, reregistration_id: numeric-string, data: string}> $rows
		 */
		$rows    = is_array( $rows ) ? $rows : array();
		$changed = 0;
		$errors  = array();

		foreach ( $rows as $row ) {
			$id     = ArrayValue::int( $row, 'id' );
			$cursor = $id;

			$data = $this->encrypt_row( ArrayValue::int( $row, 'reregistration_id' ), ArrayValue::string( $row, 'data' ) );
			if ( null === $data ) {
				continue;
			}

			$updated = $wpdb->update( $this->table, array( 'data' => $data ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
			if ( false === $updated ) {
				$errors[] = $wpdb->last_error;
				continue;
			}

			wp_cache_delete( 'id_' . $id, 'ffc_rereg_submissions' );
			$changed += (int) $updated;
		}

		$this->set_cursor( $cursor );

		$pending = $this->count_after( $cursor );

		return array(
			'success'   => empty( $errors ),
			'processed' => $changed,
			// The cursor moves over every row read, so a batch that read rows
			// always makes progress, whether or not it changed any.
			'has_more'  => ! empty( $rows ) && $pending > 0,
			/* translators: 1: number of submissions changed, 2: number of submissions examined. */
			'message'   => sprintf( __( 'Encrypted the sensitive values of %1$d of %2$d reregistration submissions examined.', 'ffcertificate' ), $changed, count( $rows ) ),
			'errors'    => $errors,
		);
	}

	/**
	 * The row's `data` with its plaintext sensitive values encrypted, or null
	 * when there is nothing to change.
	 *
	 * @param int    $reregistration_id Campaign.
	 * @param string $raw               Stored `data` JSON.
	 * @return string|null
	 */
	public function encrypt_row( int $reregistration_id, string $raw ): ?string {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! is_array( $data['fields'] ?? null ) ) {
			return null;
		}

		$values = $data['fields'];
		$dirty  = false;

		foreach ( $this->fields_for( $reregistration_id ) as $field ) {
			if ( empty( $field->is_sensitive ) ) {
				continue;
			}

			$key = (string) $field->field_key;
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}

			$stored = ReregistrationDataProcessor::encrypt_value( $values[ $key ] );
			if ( $stored !== $values[ $key ] ) {
				$values[ $key ] = $stored;
				$dirty          = true;
			}
		}

		if ( ! $dirty ) {
			return null;
		}

		$data['fields'] = $values;
		$encoded        = wp_json_encode( $data );

		return false === $encoded ? null : $encoded;
	}

	/**
	 * Field definitions of a campaign, across its audiences.
	 *
	 * @param int $reregistration_id Campaign.
	 * @return list<CustomFieldRow>
	 */
	private function fields_for( int $reregistration_id ): array {
		if ( ! isset( $this->fields[ $reregistration_id ] ) ) {
			$this->fields[ $reregistration_id ] = CustomFieldReader::get_for_audiences(
				ReregistrationRepository::get_audience_ids( $reregistration_id )
			);
		}

		return $this->fields[ $reregistration_id ];
	}

	/**
	 * Stored submissions after a cursor.
	 *
	 * @param int $cursor Last examined id.
	 * @return int
	 */
	private function count_after( int $cursor ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE id > %d AND data IS NOT NULL',
				$this->table,
				$cursor
			)
		);
	}

	/**
	 * The cursor, or zero when the walk has not started.
	 *
	 * @return int
	 */
	private function get_cursor(): int {
		$state  = get_option( self::STATE_OPTION, array() );
		$cursor = is_array( $state ) ? ( $state['cursor'] ?? null ) : null;

		return is_numeric( $cursor ) ? (int) $cursor : 0;
	}

	/**
	 * Advance the cursor.
	 *
	 * @param int $cursor Last examined id.
	 * @return void
	 */
	private function set_cursor( int $cursor ): void {
		update_option( self::STATE_OPTION, array( 'cursor' => $cursor ), false );
	}

	/**
	 * Check whether the migration can run.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		if ( ! Encryption::is_configured() ) {
			return new WP_Error(
				'encryption_not_configured',
				__( 'Encryption keys are not configured, so the values cannot be encrypted.', 'ffcertificate' )
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
		return __( 'Reregistration: Encrypt Draft Answers', 'ffcertificate' );
	}
}
