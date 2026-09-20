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
use FreeFormCertificate\Migrations\MigrationForeignKeys;

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
	 * What each grouped check GROUPED BY, and therefore what it counted.
	 *
	 * `IdentityConflictQuery::grouped()` aliases whatever it grouped BY as
	 * `subject`, so the same column name means a hash in one check and a user
	 * id in the other. Mapping it here is what stops the CSV from putting a
	 * hash in the `user_id` column.
	 *
	 * Since #1344 it answers a second question from the same fact: `related`
	 * holds the OTHER half -- the values the count aggregated away -- so a
	 * check grouped by hash carries account ids there, and one grouped by
	 * account carries hashes. The two legacy checks group the same two ways
	 * without emitting a literal `subject`, and are listed for the second
	 * reading.
	 *
	 * @var array<string, string>
	 */
	private const SUBJECT_IS = array(
		'cross_store_shared_identities'   => 'hash',
		'cross_store_multiple_identities' => 'user_id',
		'shared_identities'               => 'hash',
		'multiple_identities'             => 'user_id',
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
			'user_ids',
			'identifier_hash_prefixes',
			'related_count',
			'submission_id',
			'form_id',
			'stores',
			'email_verdict',
			'account_status',
			'account_rows',
			'account_urls',
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
			// Through `note_row()`, never hand-counted: this row was written out
			// by hand and was already one column SHORT of the header before
			// #1345 widened it, which is the drift that method exists to stop.
			return array( $this->note_row( 'error', __( 'The audit tool is not available on this install.', 'ffcertificate' ) ) );
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
				$out[] = $this->note_row(
					(string) $check,
					sprintf(
						/* translators: %d: the per-check row cap. */
						__( 'TRUNCATED: this check reached the %d-row export cap, so more findings exist than are listed here.', 'ffcertificate' ),
						self::EXPORT_LIMIT
					)
				);
			}
		}

		if ( array() === $out ) {
			$out[] = $this->note_row( '', __( 'No link problems found.', 'ffcertificate' ) );
		}

		// Last, and unconditional: it explains the `account_status` column
		// above it, so it is worth reading even on a clean report -- a file
		// that says nothing is wrong AND that no constraint is installed says
		// something a clean report alone does not.
		$out[] = $this->foreign_key_note();

		return $out;
	}

	/**
	 * A full-width row carrying only a check name and a note.
	 *
	 * Built from {@see self::header()} rather than typed out, because a row
	 * whose width drifts from the header is a broken CSV -- and hand-counting
	 * empty strings is exactly how that drift happens. Adding a column now
	 * moves these rows with it.
	 *
	 * @param string $check Check key, or an empty string.
	 * @param string $note  The sentence.
	 * @return array<int, mixed>
	 */
	private function note_row( string $check, string $note ): array {
		$row                      = array_fill( 0, count( $this->header() ), '' );
		$row[0]                   = $check;
		$row[ count( $row ) - 1 ] = $note;

		return $row;
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
	 * SINCE #1344 THE TWO IDENTITY COLUMNS HOLD LISTS
	 *
	 * A grouped check destroys one half of its own pair, so `user_ids` was
	 * blank on every shared row and `identifier_hash_prefixes` blank on every
	 * multiple row -- the report named a conflict and withheld what it is
	 * between. Both now carry every value the count covers, separated by
	 * {@see IdentityConflictQuery::RELATED_SEPARATOR}, which is why the two
	 * headers are plural: a column named in the singular that sometimes holds
	 * a list is a name that lies to every later reader.
	 *
	 * `email_verdict` is filled on the one check that can have one -- an
	 * account holding several identifiers -- and is a machine value rather
	 * than a sentence, so an operator can filter the file by it. Deliberately
	 * NOT in `note`: prose there reports a CONDITION (the row cap, a
	 * truncated list), while this classifies the finding itself (#1345).
	 *
	 * @param string               $check Check key.
	 * @param array<string, mixed> $row   One finding.
	 * @return array<int, mixed>
	 */
	private function line( string $check, array $row ): array {
		$column  = isset( $row['identifier_column'] ) ? (string) $row['identifier_column'] : '';
		$sub_id  = isset( $row['id'] ) ? (string) $row['id'] : '';
		$form_id = isset( $row['form_id'] ) ? (string) $row['form_id'] : '';
		$stores  = isset( $row[ IdentityConflictQuery::COLUMN_STORES ] ) ? (string) $row[ IdentityConflictQuery::COLUMN_STORES ] : '';
		$verdict = isset( $row[ IdentityConflictQuery::COLUMN_EMAIL_VERDICT ] ) ? (string) $row[ IdentityConflictQuery::COLUMN_EMAIL_VERDICT ] : '';
		$count   = '';
		$short   = ! empty( $row[ IdentityConflictQuery::COLUMN_RELATED_TRUNCATED ] );

		$users  = isset( $row['user_id'] ) ? array( (string) $row['user_id'] ) : array();
		$hashes = array();

		if ( isset( $row['cpf_hash'] ) && '' !== (string) $row['cpf_hash'] ) {
			$hashes[] = (string) $row['cpf_hash'];
			$column   = '' !== $column ? $column : 'cpf_hash';
		}

		$grouped_by = self::SUBJECT_IS[ $check ] ?? '';

		if ( isset( $row['subject'] ) ) {
			if ( 'hash' === $grouped_by ) {
				$hashes = array( (string) $row['subject'] );
			} else {
				$users = array( (string) $row['subject'] );
			}
		}

		// The half the `COUNT` aggregated away. A check grouped by hash names
		// the accounts here; one grouped by account names the hashes (#1344).
		if ( isset( $row[ IdentityConflictQuery::COLUMN_RELATED ] ) ) {
			$related = self::split( (string) $row[ IdentityConflictQuery::COLUMN_RELATED ] );

			if ( 'hash' === $grouped_by ) {
				$users = $related;
			} else {
				$hashes = $related;
			}
		}

		// Read the alias by CONSTANT, never by a literal typed here. This read
		// `identifier_count` while the query emitted `identity_count`, so every
		// cross-store row shipped with an empty count -- and the test agreed,
		// because its fixture carried the same wrong name.
		foreach ( array( IdentityConflictQuery::ALIAS_USER_COUNT, IdentityConflictQuery::ALIAS_IDENTITY_COUNT ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$count = (string) $row[ $key ];
			}
		}

		if ( isset( $row['cpf_count'] ) || isset( $row['rf_count'] ) ) {
			$cpf    = isset( $row['cpf_count'] ) ? (int) $row['cpf_count'] : 0;
			$rf     = isset( $row['rf_count'] ) ? (int) $row['rf_count'] : 0;
			$count  = (string) max( $cpf, $rf );
			$column = $cpf >= $rf ? 'cpf_hash' : 'rf_hash';

			// This check counts both columns on one row, so it carries one
			// list per column. The list has to follow the same pick as the
			// count, or the row would name one column and list the other's.
			$pick   = $cpf >= $rf ? 'cpf' : 'rf';
			$hashes = self::split( isset( $row[ $pick . '_related' ] ) ? (string) $row[ $pick . '_related' ] : '' );
			$short  = ! empty( $row[ $pick . '_related_truncated' ] );
		}

		$prefixed = array();
		foreach ( $hashes as $hash ) {
			$prefixed[] = $this->prefix( (string) $hash );
		}

		// Positional against `user_ids`, and built from the SAME list: reading
		// the status off the row while deriving the ids again here is how the
		// two would drift apart on a check nobody re-checked.
		$status       = isset( $row[ IdentityConflictQuery::COLUMN_ACCOUNT_STATUS ] ) ? (string) $row[ IdentityConflictQuery::COLUMN_ACCOUNT_STATUS ] : '';
		$account_rows = isset( $row[ IdentityConflictQuery::COLUMN_ACCOUNT_ROWS ] ) ? (string) $row[ IdentityConflictQuery::COLUMN_ACCOUNT_ROWS ] : '';
		$urls         = $this->account_urls( $users, $status );

		return array(
			$check,
			$column,
			implode( IdentityConflictQuery::RELATED_SEPARATOR, $users ),
			implode( IdentityConflictQuery::RELATED_SEPARATOR, $prefixed ),
			$count,
			$sub_id,
			$form_id,
			$stores,
			$verdict,
			$status,
			$account_rows,
			implode( IdentityConflictQuery::RELATED_SEPARATOR, $urls ),
			$short ? __( 'INCOMPLETE: the database truncated this row\'s list, so it names fewer values than the count beside it.', 'ffcertificate' ) : '',
		);
	}

	/**
	 * One admin URL per account, empty where the account is gone.
	 *
	 * A link to a deleted user is a 404 dressed as a lead, so a `missing`
	 * account gets an empty slot rather than a URL -- the position is kept so
	 * the column stays aligned with `user_ids`, which is what lets a
	 * spreadsheet read the two side by side.
	 *
	 * Full URLs rather than paths because the file is opened in a spreadsheet,
	 * where only a full URL is clickable.
	 *
	 * @param array<int, string> $users  Account ids, in report order.
	 * @param string             $status The positional status list.
	 * @return array<int, string>
	 */
	private function account_urls( array $users, string $status ): array {
		$statuses = self::split( $status );
		$out      = array();

		foreach ( array_values( $users ) as $index => $id ) {
			$known = $statuses[ $index ] ?? '';

			$out[] = ( IdentityConflictQuery::STATUS_EXISTS === $known )
				? admin_url( 'user-edit.php?user_id=' . rawurlencode( (string) $id ) )
				: '';
		}

		return $out;
	}

	/**
	 * What the database actually enforces between `user_id` and `wp_users`.
	 *
	 * Reported ONCE, as a note, because it is a property of the install rather
	 * than of any finding -- and without it a `missing` account is ambiguous
	 * between the two readings that matter. `ffc_recruitment_candidate` is
	 * deliberately never constrained (a candidate is not a user until
	 * promotion), so a missing account there is ordinary; a missing one
	 * anywhere else means the foreign key is not in place, and the operator
	 * should be told which rather than left to infer it.
	 *
	 * The live constraints are read from the schema rather than the
	 * `ffc_foreign_keys_db_version` option, because that flag records that the
	 * migration RAN, not that every `ALTER` inside it succeeded -- and the
	 * difference is exactly the case this row exists to report.
	 *
	 * @return array<int, mixed>
	 */
	private function foreign_key_note(): array {
		$status = $this->foreign_key_status();

		$installed = isset( $status['existing_constraints'] ) && is_numeric( $status['existing_constraints'] )
			? (int) $status['existing_constraints']
			: 0;
		$total     = isset( $status['total_constraints'] ) && is_numeric( $status['total_constraints'] )
			? (int) $status['total_constraints']
			: 0;

		if ( ! empty( $status['is_complete'] ) ) {
			return $this->note_row(
				'foreign_keys',
				sprintf(
					/* translators: %d: how many user_id foreign keys are installed. */
					__( 'FOREIGN KEYS: all %d user_id constraints are installed, so an account reported missing above can only belong to a table no constraint covers — recruitment candidacies, which are deliberately unconstrained because a candidate is not a WordPress user until promotion.', 'ffcertificate' ),
					$installed
				)
			);
		}

		// The names that ARE there, never a list of the ones that are not:
		// the canonical set lives in `MigrationForeignKeys` and is not public,
		// so restating it here would be a claim about a value another file
		// owns — exactly the kind that goes stale in silence.
		$names = ( isset( $status['existing'] ) && is_array( $status['existing'] ) ) ? $status['existing'] : array();
		$names = array_map( 'strval', $names );
		sort( $names );

		return $this->note_row(
			'foreign_keys',
			sprintf(
				/* translators: 1: installed count, 2: total count, 3: comma-separated constraint names, or a dash. */
				__( 'FOREIGN KEYS: only %1$d of %2$d user_id constraints are installed (%3$s), so a missing account above is NOT necessarily a recruitment candidacy — the database is not enforcing the link on every table it should. Run the foreign-key migration before reading these statuses.', 'ffcertificate' ),
				$installed,
				$total,
				array() === $names ? '—' : implode( ', ', $names )
			)
		);
	}

	/**
	 * The live foreign-key state.
	 *
	 * A seam for the same reason the auditor is injectable: this reaches
	 * `information_schema` through the global `$wpdb`, and a test about which
	 * COLUMN a value lands in should not have to stand a database up to get
	 * there. Overridden in tests that are not about this; driven for real by
	 * the ones that are.
	 *
	 * @return array<string, mixed>
	 */
	protected function foreign_key_status(): array {
		return MigrationForeignKeys::get_status();
	}

	/**
	 * A separated list as an array, with an empty string meaning no values.
	 *
	 * `explode()` on `''` returns a one-element array holding the empty
	 * string, which would make every row without a list name one blank value.
	 *
	 * @param string $joined Separated values.
	 * @return array<int, string>
	 */
	private static function split( string $joined ): array {
		return '' === $joined ? array() : explode( IdentityConflictQuery::RELATED_SEPARATOR, $joined );
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
