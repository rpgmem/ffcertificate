<?php
/**
 * IdentityAuditExportSource
 *
 * The link audit's findings as a downloadable CSV (#1295). Synchronous, so the
 * rows never touch disk.
 *
 * WHY THIS EXISTS
 *
 * The audit has reported `50+` for two of its checks since it was widened, and
 * `50+` is the one value where the list matters most and the screen shows
 * least. Its own closing line calls the findings "leads to investigate" -- but
 * a lead nobody can take out of the page is not a lead, and the eleven shared
 * identities #1313 measured in production have to be read one by one before
 * anyone can design a merge policy.
 *
 * IT CARRIES NO PII, DELIBERATELY
 *
 * Every row is ids, counts and a truncated hash. No CPF, RF, e-mail, name or
 * login: the audit answers "which accounts disagree", and an operator resolves
 * that in wp-admin, where the capability to see a person's data is already
 * gated. Putting decrypted identifiers in a file an operator downloads would
 * be a new PII-on-disk surface and a decision of its own -- the same reasoning
 * that kept the audience members EXPORT from gaining `cpf` / `rf` columns when
 * the import gained them (#1313).
 *
 * The hash is truncated to {@see self::HASH_PREFIX_CHARS} characters, which is
 * the plugin's own convention wherever a hash reaches a log. It is there to
 * GROUP rows -- two accounts sharing an identifier show the same prefix -- and
 * for nothing else; the full value is not reversible without the salt anyway,
 * and the prefix is not reversible at all.
 *
 * WHY SYNCHRONOUS, AND WHAT WOULD CHANGE THAT
 *
 * `CLAUDE.md` routes a bounded output to {@see SyncSourceInterface} and an
 * unknown-size one to the batched engine. This sits between: the population is
 * bounded by {@see self::EXPORT_LIMIT} per check because this class caps it,
 * not because the data is provably small. That cap is what keeps it
 * synchronous, and it buys the property the batched path cannot give here --
 * identity findings never land in a temp file.
 *
 * The cap is never silent. A check that reaches it emits a final row whose
 * `note` says so, so a partial list can never be read as a complete one -- the
 * same rule the schema gates state as "never count as clean what it did not
 * look at". An install that hits the cap is the trigger to move this to the
 * batched engine; do not build that on spec (the #788 / #902 / #993 criterion).
 *
 * @package FreeFormCertificate\Maintenance
 * @since   6.27.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Core\SyncSourceInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The link audit's findings as a synchronous export source.
 */
class IdentityAuditExportSource implements SyncSourceInterface {

	/**
	 * Rows per check. See the class note: this is a cap, not a proof of
	 * boundedness, and reaching it is reported rather than hidden.
	 *
	 * @var int
	 */
	public const EXPORT_LIMIT = 5000;

	/**
	 * Characters of the identifier hash kept, enough to group rows belonging
	 * to one identity without carrying the whole value.
	 *
	 * @var int
	 */
	public const HASH_PREFIX_CHARS = 16;

	/**
	 * The nonce action for the export link.
	 *
	 * @var string
	 */
	public const NONCE = 'ffc_submission_audit_export';

	/**
	 * Which value the grouped cross-store checks put in `subject`.
	 *
	 * `IdentityConflictQuery::grouped()` aliases whatever it grouped BY as
	 * `subject`, so the same column name means a hash in one check and a user
	 * id in the other. Mapping it here is what stops the CSV from putting a
	 * hash in the `user_id` column.
	 *
	 * @var array<string, string>
	 */
	private const SUBJECT_IS = array(
		'cross_store_shared_identities'   => 'hash',
		'cross_store_multiple_identities' => 'user_id',
	);

	/**
	 * The auditor, injectable so a test can drive the export without standing
	 * up four tables.
	 *
	 * @var MaintenanceToolInterface|null
	 */
	private ?MaintenanceToolInterface $tool;

	/**
	 * Constructor.
	 *
	 * @param MaintenanceToolInterface|null $tool Auditor (resolved from the registry when null).
	 */
	public function __construct( ?MaintenanceToolInterface $tool = null ) {
		$this->tool = $tool;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Same gate as the scan it exports: the Danger Zone capability plus the
	 * export's own nonce. The findings name accounts, so this is not a
	 * read-only screen anyone with `ffc_manage_settings` should stream.
	 *
	 * @return void
	 */
	public function authorize(): void {
		if ( ! Capabilities::current_user_can_admin_or( 'ffc_manage_settings_dangerzone' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this audit.', 'ffcertificate' ), 403 );
		}

		if ( ! wp_verify_nonce( RequestInput::get_get_string( '_wpnonce' ), self::NONCE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ), 403 );
		}

		nocache_headers();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function filename(): string {
		return 'ffc-identity-audit-' . gmdate( 'Y-m-d-His' ) . '.csv';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public function header(): array {
		return array(
			'check',
			'identifier_column',
			'user_id',
			'identifier_hash_prefix',
			'related_count',
			'submission_id',
			'form_id',
			'note',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return iterable
	 * @phpstan-return iterable<array<int, mixed>>
	 */
	public function rows(): iterable {
		$tool = $this->tool ?? MaintenanceToolRegistry::create_default()->get( 'submission_link_audit' );

		if ( ! $tool instanceof MaintenanceToolInterface ) {
			return array(
				array( 'error', '', '', '', '', '', '', __( 'The audit tool is not available on this install.', 'ffcertificate' ) ),
			);
		}

		$report = $tool->run( array( 'limit' => self::EXPORT_LIMIT ) );
		$checks = isset( $report['checks'] ) && is_array( $report['checks'] ) ? $report['checks'] : array();

		$out = array();

		foreach ( $checks as $check => $data ) {
			$rows = ( is_array( $data ) && isset( $data['rows'] ) && is_array( $data['rows'] ) ) ? $data['rows'] : array();

			foreach ( $rows as $row ) {
				if ( is_array( $row ) ) {
					$out[] = $this->line( (string) $check, $row );
				}
			}

			if ( ! empty( $data['truncated'] ) ) {
				$out[] = array(
					(string) $check,
					'',
					'',
					'',
					'',
					'',
					'',
					sprintf(
						/* translators: %d: the per-check row cap. */
						__( 'TRUNCATED: this check reached the %d-row export cap, so more findings exist than are listed here.', 'ffcertificate' ),
						self::EXPORT_LIMIT
					),
				);
			}
		}

		if ( array() === $out ) {
			$out[] = array( '', '', '', '', '', '', '', __( 'No link problems found.', 'ffcertificate' ) );
		}

		return $out;
	}

	/**
	 * Normalise one finding onto the shared header.
	 *
	 * The seven checks return seven different column sets, so a row is mapped
	 * rather than splatted: `id` is a submission id in the two submission-scoped
	 * checks that return one, and `subject` is a hash or a user id depending on
	 * which way its check grouped (see {@see self::SUBJECT_IS}).
	 *
	 * `multiple_identities` is the one check whose row carries TWO counts
	 * (`cpf_count`, `rf_count`). It reports the larger one and names the column
	 * it belongs to, because a row that said `2` without saying two of what is
	 * not a lead anybody can act on.
	 *
	 * @param string               $check Check key.
	 * @param array<string, mixed> $row   One finding.
	 * @return array<int, mixed>
	 */
	private function line( string $check, array $row ): array {
		$user_id = isset( $row['user_id'] ) ? (string) $row['user_id'] : '';
		$column  = isset( $row['identifier_column'] ) ? (string) $row['identifier_column'] : '';
		$count   = '';
		$hash    = isset( $row['cpf_hash'] ) ? (string) $row['cpf_hash'] : '';
		$sub_id  = isset( $row['id'] ) ? (string) $row['id'] : '';
		$form_id = isset( $row['form_id'] ) ? (string) $row['form_id'] : '';

		if ( '' !== $hash ) {
			$column = '' !== $column ? $column : 'cpf_hash';
		}

		if ( isset( $row['subject'] ) ) {
			if ( 'hash' === ( self::SUBJECT_IS[ $check ] ?? '' ) ) {
				$hash = (string) $row['subject'];
			} else {
				$user_id = (string) $row['subject'];
			}
		}

		foreach ( array( 'user_count', 'identifier_count' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$count = (string) $row[ $key ];
			}
		}

		if ( isset( $row['cpf_count'] ) || isset( $row['rf_count'] ) ) {
			$cpf    = isset( $row['cpf_count'] ) ? (int) $row['cpf_count'] : 0;
			$rf     = isset( $row['rf_count'] ) ? (int) $row['rf_count'] : 0;
			$count  = (string) max( $cpf, $rf );
			$column = $cpf >= $rf ? 'cpf_hash' : 'rf_hash';
		}

		return array(
			$check,
			$column,
			$user_id,
			$this->prefix( $hash ),
			$count,
			$sub_id,
			$form_id,
			'',
		);
	}

	/**
	 * The grouping prefix of a hash, or an empty string when there is none.
	 *
	 * @param string $hash Full hash.
	 * @return string
	 */
	private function prefix( string $hash ): string {
		return '' === $hash ? '' : substr( $hash, 0, self::HASH_PREFIX_CHARS );
	}
}
