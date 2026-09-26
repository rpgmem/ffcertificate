<?php
/**
 * Identity split
 *
 * Creates an account for records that belong to somebody who has none, and
 * moves them onto it (#1386). The third verb of the identity queue, and the
 * thinnest: it is {@see IdentityRelink} with the target created first.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.28.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\DataSanitizer;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SubmitterName;
use FreeFormCertificate\Repositories\UserProfileRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement here targets the plugin's own ffc_* tables, for which WordPress exposes no API, and the answer must reflect the live rows: a proposal built from a cached read would name an address the records no longer carry.
/**
 * Give records of their own to somebody who has no account.
 */
class IdentitySplit {

	/**
	 * The identifiers a split can carry onto a new account.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = array( 'rf', 'cpf' );

	/**
	 * How many characters of a hash reach the log.
	 *
	 * @var int
	 */
	public const LOG_PREFIX = 12;

	/**
	 * Create an account for one identifier's records and move them onto it.
	 *
	 * THE OPERATOR SUPPLIES THE ADDRESS, AND THAT IS NOT A CONVENIENCE.
	 *
	 * WordPress requires `user_email` to be unique, and every typo finding the
	 * production audit carries reports `shared_email` -- both identifiers sit
	 * under the address the existing account already uses. So the new account
	 * CANNOT inherit the one on the records: there is no address to give it
	 * except one a person provides, case by case (#1386, decision 1).
	 *
	 * PER IDENTIFIER, ONCE OR TWICE -- NEVER BOTH AT ONCE
	 *
	 * An account holding two identifiers that both belong elsewhere is two
	 * people, not one, so a single new account taking both would recreate the
	 * conflict under a new login. Splitting each in turn produces two
	 * accounts, which is what two people need.
	 *
	 * WHY THE AGREEMENT RULE IS SATISFIED BY CONSTRUCTION HERE, AND WHY THAT
	 * IS NOT A LOOPHOLE
	 *
	 * The new account is seeded with the identifier being split before the
	 * move, so {@see IdentityRelink} finds a match rather than refusing. That
	 * reads like a way around the rule and is not one: what the rule guards
	 * against is records landing on a PRE-EXISTING account belonging to
	 * somebody else, and an account created in this call belongs to nobody
	 * yet. The operator's assertion that these records are a separate person
	 * IS the decision, and the address they had to supply is what makes it
	 * deliberate rather than a click.
	 *
	 * @param string $hash  The stored hash whose records get their own account.
	 * @param string $email The address for the new account.
	 * @param int    $actor Who decided it, for the log.
	 * @param string $field `rf` or `cpf`; which column the hash sits in.
	 * @return array{account: int, moved: array<string, int>, from: int}|WP_Error
	 */
	public function split( string $hash, string $email, int $actor = 0, string $field = 'rf' ): array|WP_Error {
		if ( ! in_array( $field, self::FIELDS, true ) ) {
			return new WP_Error(
				'ffc_identity_split_unknown_field',
				__( 'That is not an identifier this can split.', 'ffcertificate' )
			);
		}

		$hash = trim( $hash );

		// THE ONE FUNCTION DECIDES WHAT AN ADDRESS IS.
		//
		// `normalize_email()` lowercases and trims, and `IdentifierIdiomTest`
		// holds that no file writes its own: an operator typing `Joao@...`
		// must reach the same person as `joao@...`, and the address here is
		// checked against every existing account before it opens a new one.
		// Validity stays a separate question, which is the two calls below.
		$email = DataSanitizer::normalize_email( $email );

		if ( '' === $hash ) {
			return new WP_Error(
				'ffc_identity_split_no_subject',
				__( 'No records were named to split.', 'ffcertificate' )
			);
		}

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'ffc_identity_split_invalid_email',
				__( 'A split needs a valid e-mail address for the new account.', 'ffcertificate' )
			);
		}

		if ( email_exists( $email ) ) {
			return new WP_Error(
				'ffc_identity_split_email_taken',
				__( 'That address already belongs to an account, so it cannot open a new one. Use a different address — or, if that account is the right one, move the records to it instead of splitting.', 'ffcertificate' )
			);
		}

		$account = $this->create_account( $email );

		if ( is_wp_error( $account ) ) {
			return $account;
		}

		// SEEDED BEFORE THE MOVE, FOR THE REASON THE DOCBLOCK ARGUES.
		//
		// A brand-new account holds nothing, so the agreement rule would refuse
		// every split. Writing the identifier first is what says whose account
		// this is -- and it is written BEFORE the move so that a failure below
		// leaves nothing half-owned once the account is removed.
		if ( ! $this->seed_index( $account, $field . '_hash', $hash ) ) {
			$this->discard_account( $account );

			return new WP_Error(
				'ffc_identity_split_index_failed',
				__( 'The account was created but the identity index refused to record what it holds, so it was removed again and nothing was changed.', 'ffcertificate' )
			);
		}

		$moved = $this->movements()->relink( $hash, $account, $actor, $field );

		// A FAILED SPLIT LEAVES NO ACCOUNT BEHIND.
		//
		// The relink makes its own refusals -- records split across two
		// accounts, records that are already gone -- and any of them here
		// would otherwise leave an empty login nobody asked for, which an
		// operator would have to notice and clean up by hand.
		if ( is_wp_error( $moved ) ) {
			$this->discard_account( $account );

			return $moved;
		}

		ActivityLog::log(
			'identity_records_split',
			ActivityLog::LEVEL_WARNING,
			array(
				// A prefix, never the hash and never the value.
				'identifier' => substr( $hash, 0, self::LOG_PREFIX ),
				'field'      => $field,
				'from'       => $moved['from'],
				'account'    => $account,
				'stores'     => $moved['moved'],
			),
			$actor
		);

		return array(
			'account' => $account,
			'moved'   => $moved['moved'],
			'from'    => $moved['from'],
		);
	}

	/**
	 * Every store a split's records can sit in.
	 *
	 * The same three {@see IdentityRelink} moves, and deliberately its own
	 * constant rather than a read of that one: what this reads is columns the
	 * move never touches, so a store could legitimately be here and not there.
	 *
	 * @var array<int, string>
	 */
	private const STORES = array(
		'ffc_submissions',
		'ffc_self_scheduling_appointments',
		'ffc_recruitment_candidate',
	);

	/**
	 * The address a split could derive, and the names to show beside it (#1480).
	 *
	 * WHY THIS EXISTS AT ALL, GIVEN `split()` ARGUES THE OPPOSITE.
	 *
	 * That method's docblock says the operator must supply the address because
	 * "every typo finding the production audit carries reports `shared_email`"
	 * -- both identifiers under the address the existing account already uses,
	 * so there is nothing to inherit. That was measured and is still nearly
	 * true: 37 of 38 findings on the 2026-09-26 audit. It simply does not
	 * describe the 38th.
	 *
	 * Where the verdict is `distinct_emails`, each identifier HAS its own
	 * address, and asking the operator to type one they can already see is
	 * where a typo enters. `ffc_submissions` stores `email_encrypted` beside
	 * `email_hash`, so the address is recoverable -- there is something to
	 * inherit after all.
	 *
	 * THE MACHINE'S TEST IS THE IDENTIFIER AND THE ADDRESS. NOT THE NAME.
	 *
	 * Two discordant elements, both with a hash, neither needing a decrypt to
	 * compare. The name is returned as EVIDENCE for the operator's
	 * confirmation, never as a condition, and that is not a shortcut: two
	 * people in a school system share a name often, and one person's name is
	 * spelled two ways across two submissions -- accent, abbreviation, married
	 * name. So a differing name is not proof of two people and a matching one
	 * is not proof of one, while the address is the discriminator the queue
	 * already reasons with (#1345).
	 *
	 * It reads and decrypts; it writes nothing, and it proposes rather than
	 * decides -- the caller still passes an address to {@see self::split()},
	 * which re-checks validity and `email_exists()` at write time.
	 *
	 * @since 6.30.0
	 * @param string $hash  The stored hash whose records would get their own account.
	 * @param string $field `rf` or `cpf`; which column the hash sits in.
	 * @return array{email: string, reason: string, names: array<int, string>}
	 */
	public function proposal( string $hash, string $field = 'rf' ): array {
		global $wpdb;

		$out = array(
			'email'  => '',
			'reason' => 'none',
			'names'  => array(),
		);

		$hash = trim( $hash );

		if ( '' === $hash || ! in_array( $field, self::FIELDS, true ) ) {
			return $out;
		}

		$column  = $field . '_hash';
		$ciphers = array();
		$names   = array();

		foreach ( self::STORES as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			// TWO LITERAL STATEMENTS, BECAUSE A NAME IS NOT IN THE SAME PLACE
			// IN ALL THREE STORES.
			//
			// `ffc_self_scheduling_appointments` and `ffc_recruitment_candidate`
			// each declare a plain `name varchar(255)`. `ffc_submissions`
			// declares no name column at all -- the name sits inside the answers
			// under a per-form key, which is what {@see SubmitterName} resolves.
			// So one statement over all three would name a column two of them do
			// not have.
			//
			// Written out rather than composed from a column list: the list would
			// be this class's own constant and safe to interpolate, but it would
			// need a suppression to say so, and a constant that only DOCUMENTS
			// the shapes is one nothing reads -- which is what level 9 reported
			// when the first draft kept it. The two statements are the register.
			$found = 'ffc_submissions' === $suffix
				? $wpdb->get_results(
					$wpdb->prepare(
						'SELECT email_hash, email_encrypted, data, data_encrypted FROM %i WHERE %i = %s',
						$table,
						$column,
						$hash
					),
					ARRAY_A
				)
				: $wpdb->get_results(
					$wpdb->prepare(
						'SELECT email_hash, email_encrypted, name FROM %i WHERE %i = %s',
						$table,
						$column,
						$hash
					),
					ARRAY_A
				);

			foreach ( (array) $found as $row ) {
				$row = (array) $row;

				// KEYED BY THE HASH, so "how many addresses" is answered without
				// decrypting anything: the count that decides is a count of
				// hashes, and only the survivor is ever deciphered.
				$email_hash = ArrayValue::string( $row, 'email_hash' );
				$cipher     = ArrayValue::string( $row, 'email_encrypted' );

				if ( '' !== $email_hash && '' !== $cipher ) {
					$ciphers[ $email_hash ] = $cipher;
				}

				$name = self::name_in( $row, $suffix );

				if ( '' !== $name && ! in_array( $name, $names, true ) ) {
					$names[] = $name;
				}
			}
		}

		$out['names'] = $names;

		if ( array() === $ciphers ) {
			return $out;
		}

		// MORE THAN ONE ADDRESS IS NOT A PROPOSAL.
		//
		// The same rule the agreement uses for an identifier: two values do not
		// say which one the new account gets, and picking would invent an
		// answer. It is also the shape that says these rows are not one
		// person's, which is a different finding rather than a split.
		if ( count( $ciphers ) > 1 ) {
			$out['reason'] = 'several';

			return $out;
		}

		$email = $this->decrypt( (string) reset( $ciphers ) );

		if ( null === $email || '' === trim( (string) $email ) ) {
			// An address nobody can read is not an address. Saying so beats
			// proposing an empty field that looks like "there is none".
			$out['reason'] = 'unreadable';

			return $out;
		}

		$email = DataSanitizer::normalize_email( (string) $email );

		if ( '' === $email || ! is_email( $email ) ) {
			$out['reason'] = 'unreadable';

			return $out;
		}

		// ALREADY AN ACCOUNT'S ADDRESS MEANS THE VERB IS A MOVE.
		//
		// `split()` refuses this at write time anyway, and would be right to.
		// Reported here it is actionable instead: the destination exists, so the
		// records go to it rather than to a new login.
		if ( email_exists( $email ) ) {
			$out['reason'] = 'taken';

			return $out;
		}

		$out['email']  = $email;
		$out['reason'] = '';

		return $out;
	}

	/**
	 * The name on one row, from wherever that store keeps it.
	 *
	 * @param array<mixed, mixed> $row   The row.
	 * @param string              $store Unprefixed table name.
	 * @return string
	 */
	private function name_in( array $row, string $store ): string {
		if ( 'ffc_submissions' !== $store ) {
			return trim( ArrayValue::string( $row, 'name' ) );
		}

		// THE ENCRYPTED COPY FIRST, because it is the one kept current: the
		// plaintext `data` column is what installs held before the answers were
		// encrypted, and a row carrying both has the ciphertext as the answer.
		$cipher = ArrayValue::string( $row, 'data_encrypted' );

		if ( '' !== $cipher ) {
			$plain = $this->decrypt( $cipher );

			if ( null !== $plain ) {
				$answers = json_decode( (string) $plain, true );

				if ( is_array( $answers ) ) {
					return SubmitterName::from( $answers );
				}
			}
		}

		$answers = json_decode( ArrayValue::string( $row, 'data' ), true );

		return is_array( $answers ) ? SubmitterName::from( $answers ) : '';
	}

	/**
	 * Decryption as a seam, so a proposal can be driven without a key.
	 *
	 * @param string $cipher Stored ciphertext.
	 * @return string|null
	 */
	protected function decrypt( string $cipher ): ?string {
		return class_exists( Encryption::class ) ? Encryption::decrypt( $cipher ) : null;
	}

	/**
	 * Create the account, with the role every FFC account gets.
	 *
	 * No welcome mail is sent, deliberately: this runs from a maintenance
	 * screen while an operator is correcting data, and an account notification
	 * arriving unannounced is a side effect they did not ask for. Telling the
	 * person is theirs to do, through WordPress's own password reset.
	 *
	 * @param string $email The address.
	 * @return int|WP_Error The new account's id.
	 */
	protected function create_account( string $email ) {
		// A FILTER, BECAUSE CREATION LIVES IN ONE PLACE AND IT IS NOT THIS ONE.
		//
		// `IdentityConvergenceGuardTest` holds that a WordPress user is created
		// only by `UserCreator`, which resolves the identifier first so a
		// person never gets a second account. A split is the one case where
		// resolving would defeat the purpose -- the identifier resolves to the
		// account the records are being taken off -- so the exception is argued
		// THERE, in `create_for_identity_split()`, and reached from here through
		// a hook. Naming the class directly would add a `Maintenance >
		// UserDashboard` edge the module baseline does not carry; this is the
		// same shape, and the same reasoning, as
		// `ffc_grant_certificate_capabilities`.
		$created = apply_filters( 'ffc_create_identity_account', null, $email );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$user_id = is_numeric( $created ) ? (int) $created : 0;

		if ( $user_id <= 0 ) {
			return new WP_Error(
				'ffc_identity_split_no_creator',
				__( 'No account could be created: the identity split has nothing wired to create one on this install.', 'ffcertificate' )
			);
		}

		return $user_id;
	}

	/**
	 * Remove an account this call created, after a failure.
	 *
	 * Only ever called on an account created moments earlier in this same
	 * call, which is why deleting is safe here and nowhere else in the queue:
	 * nothing has been moved onto it yet, so there is nothing to lose.
	 *
	 * @param int $user_id The account.
	 * @return void
	 */
	protected function discard_account( int $user_id ): void {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		wp_delete_user( $user_id );
	}

	/**
	 * Record what the new account holds.
	 *
	 * @param int    $user_id The account.
	 * @param string $column  The index column.
	 * @param string $hash    The identifier.
	 * @return bool
	 */
	protected function seed_index( int $user_id, string $column, string $hash ): bool {
		return ( new UserProfileRepository() )->upsertForUserId( $user_id, array( $column => $hash ) );
	}

	/**
	 * The move, as a seam a test can replace.
	 *
	 * @return IdentityRelink
	 */
	protected function movements(): IdentityRelink {
		return new IdentityRelink();
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
