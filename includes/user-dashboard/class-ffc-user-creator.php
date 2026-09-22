<?php
/**
 * UserCreator
 *
 * Handles WordPress user creation, linking, and username generation for FFC.
 * Extracted from UserManager (v4.12.2) for single-responsibility.
 *
 * @package FreeFormCertificate\UserDashboard
 * @since 4.12.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * User Creator.
 */
class UserCreator {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Get or create a WordPress user when BOTH a CPF and an RF are
	 * available for the same person.
	 *
	 * The only entry point. A single-hash predecessor took one identifier
	 * and missed a previously-registered submission keyed on the OTHER --
	 * falling through to email matching or, worse, a duplicate user. It was
	 * deprecated in 6.26.0 and removed in 6.28.0 (#1313); this one checks both
	 * columns against both hashes in a single SQL pass.
	 *
	 * Flow is identical to the single-hash path otherwise:
	 *   1. Lookup against `ffc_submissions` where any of the supplied
	 *      hashes matches its respective column.
	 *   2. Email lookup in wp_users.
	 *   3. Create.
	 *
	 * @param string|null          $cpf_hash        SHA-256 hash of the candidate CPF (or null).
	 * @param string|null          $rf_hash         SHA-256 hash of the candidate RF (or null).
	 * @param string               $email           Plain email.
	 * @param array<string, mixed> $submission_data Optional metadata for user creation.
	 * @param string               $context         Capability context.
	 * @param bool                 $notify          Whether a CREATED user is sent
	 *                                              the account notification. Has
	 *                                              no effect on the two matching
	 *                                              branches, which create nobody.
	 * @return int|\WP_Error User ID or error. Returns a WP_Error('ffc_user_no_identifier') when both hashes AND email are empty.
	 */
	public static function get_or_create_user_dual( ?string $cpf_hash, ?string $rf_hash, string $email, array $submission_data = array(), string $context = CapabilityManager::CONTEXT_CERTIFICATE, bool $notify = true ) {
		// Normalize empty strings to null so downstream branches can rely
		// on `null !== $cpf_hash` semantics.
		$cpf_hash = ( is_string( $cpf_hash ) && '' !== $cpf_hash ) ? $cpf_hash : null;
		$rf_hash  = ( is_string( $rf_hash ) && '' !== $rf_hash ) ? $rf_hash : null;

		if ( null === $cpf_hash && null === $rf_hash && '' === $email ) {
			return new \WP_Error( 'ffc_user_no_identifier', 'No identifier provided.' );
		}

		// STEP 1: Submissions lookup against the supplied hashes.
		$existing_user_id = self::find_user_id_by_hashes( $cpf_hash, $rf_hash );

		if ( $existing_user_id ) {
			$uid = (int) $existing_user_id;
			CapabilityManager::grant_context_capabilities( $uid, $context );
			self::link_orphaned_records_dual( $cpf_hash, $rf_hash, $uid );
			return $uid;
		}

		// STEP 2: Email lookup.
		$existing_user = '' !== $email ? get_user_by( 'email', $email ) : false;
		if ( $existing_user ) {
			$uid = (int) $existing_user->ID;
			$existing_user->add_role( 'ffc_end_user' );
			CapabilityManager::grant_context_capabilities( $uid, $context );

			if ( empty( $existing_user->display_name ) || $existing_user->display_name === $existing_user->user_login ) {
				self::sync_user_metadata( $uid, $submission_data );
			}

			self::link_orphaned_records_dual( $cpf_hash, $rf_hash, $uid );
			return $uid;
		}

		// STEP 3: Create. Empty email here means we have at least one
		// hash but no email — `wp_create_user` will reject the empty
		// address with a WP_Error, which we propagate.
		$user_id = self::create_ffc_user( $email, $submission_data, $context, $notify );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		self::link_orphaned_records_dual( $cpf_hash, $rf_hash, (int) $user_id );
		return (int) $user_id;
	}

	/**
	 * The user a CPF and/or RF hash already belongs to, or null.
	 *
	 * Extracted from {@see self::get_or_create_user_dual()}'s step 1 so the
	 * read-only probe below and the create-if-missing resolver cannot drift:
	 * a probe that looked in MORE places than the resolver would report a match
	 * and then watch promotion create a duplicate anyway. One lookup, two
	 * callers.
	 *
	 * **It asks the identity index first, then `ffc_submissions`** (#1313 PR 4).
	 *
	 * **Why the index does not REPLACE the submissions query, which is what
	 * #1313 proposed.** `ffc_user_profiles` is the right place to ask "which
	 * user carries this identifier": one row per user, both columns indexed,
	 * and `user_id` is the answer rather than a link that may be null. But it
	 * is NOT a superset of the module tables on an existing install, and
	 * nothing makes it one. A certificate submission writes
	 * `ffc_submissions.cpf_hash` and never touches the profile, so a user known
	 * only through a submission has no profile row — and reading the index
	 * ALONE would stop finding them and create a duplicate, which is the exact
	 * defect this work exists to remove. The invariant that fills the index
	 * starts below, at the moment a record is linked; it says nothing about
	 * records linked before it existed.
	 *
	 * So the index leads and submissions remain the fallback. When measurement
	 * shows the fallback finding nothing the index missed, that is the trigger
	 * to collapse this to one query — extracted from a proven fact rather than
	 * on spec, the criterion `CLAUDE.md` records for #788 / #902 / #993.
	 *
	 * **Appointments and candidacies are still consulted by neither**, while
	 * this class's own `link_orphaned_records_dual()` happily ADOPTS an
	 * appointment row once the user is identified some other way — so the
	 * plugin declines to RECOGNISE someone it is willing to adopt a record for
	 * a moment later. Since #1345 a candidacy IS adopted, through the
	 * `ffc_adopt_orphaned_identity_records` action fired below; recognition
	 * still consults neither. That asymmetry is #1295's, not this PR's: widening to
	 * them means a `SHOW TABLES` probe per lookup on a path every certificate
	 * submission takes, and it should be decided with that cost measured
	 * rather than folded into a change about the index.
	 *
	 * @since 6.26.0
	 * @param string|null $cpf_hash CPF hash, or null to skip that column.
	 * @param string|null $rf_hash  RF hash, or null to skip that column.
	 * @return int|null User id, or null when neither hash is known.
	 */
	private static function find_user_id_by_hashes( ?string $cpf_hash, ?string $rf_hash ): ?int {
		if ( null === $cpf_hash && null === $rf_hash ) {
			return null;
		}

		global $wpdb;

		// Build a `cpf_hash = %s OR rf_hash = %s` clause that includes only the
		// columns we actually have a value for.
		$where_parts = array();
		$values      = array();
		if ( null !== $cpf_hash ) {
			$where_parts[] = 'cpf_hash = %s';
			$values[]      = $cpf_hash;
		}
		if ( null !== $rf_hash ) {
			$where_parts[] = 'rf_hash = %s';
			$values[]      = $rf_hash;
		}
		$where = implode( ' OR ', $where_parts );

		foreach ( self::identity_sources() as $table ) {
			// `user_id IS NOT NULL` is always true on the profile, where the
			// column is the primary fact rather than a link that may be
			// missing. One statement shape for both is worth more than saving
			// that predicate on one of them.
			//
			// No `table_exists()` probe: both tables are created by activators
			// that #1311 wired into the runtime healing path, and probing would
			// cost a `SHOW TABLES` per lookup on a path every certificate
			// submission takes. Same exposure, and the same answer, as
			// `UserProfileService`'s own index write.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where built from hard-coded fragments above with matching placeholder count.
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT user_id FROM %i WHERE ({$where}) AND user_id IS NOT NULL LIMIT 1",
					$table,
					...$values
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			if ( $found ) {
				return (int) $found;
			}
		}

		return null;
	}

	/**
	 * The stores that can answer "which user carries this identifier", in the
	 * order they are asked.
	 *
	 * Both carry `cpf_hash`, `rf_hash` and `user_id`, which is what lets one
	 * statement serve them — the difference between them is not the shape of
	 * the question but how complete the answer is.
	 *
	 * The identity index leads because it is the only one built for this
	 * question, and because a hit there costs one key probe on a table with one
	 * row per user. Submissions follow because that is where an identifier most
	 * often first appears, and on an existing install it is the only store that
	 * knows about anyone who never edited a profile field.
	 *
	 * @since 6.26.0
	 * @return array<int, string> Full table names, in lookup order.
	 */
	private static function identity_sources(): array {
		global $wpdb;

		return array(
			$wpdb->prefix . 'ffc_user_profiles',
			\FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table(),
		);
	}

	/**
	 * Resolve an identity to an EXISTING user, creating nothing.
	 *
	 * The read-only sibling of {@see self::get_or_create_user_dual()}: it runs
	 * that method's first two steps — hash lookup, then e-mail — and stops
	 * before the third. Callers that must report what resolution WOULD do,
	 * without doing it, need exactly this: the reregistration CSV import
	 * validates every row before promoting any (#1214), and the job may be
	 * blocked immediately afterwards, so a probe that created users would leave
	 * accounts behind for an import that never ran.
	 *
	 * It is the same distinction the captcha contract draws in this codebase
	 * between `verify()`, which spends the challenge, and `peek()`, which
	 * checks it without spending.
	 *
	 * **Deliberately no side effects**: no capability grant, no role, no
	 * orphan adoption. Those belong to the act, not to the question.
	 *
	 * @since 6.26.0
	 * @param string|null $cpf_hash CPF hash, or null.
	 * @param string|null $rf_hash  RF hash, or null.
	 * @param string      $email    Plain e-mail, or '' when unknown.
	 * @return int User id, or 0 when resolution would create one.
	 */
	public static function resolve_existing_user( ?string $cpf_hash, ?string $rf_hash, string $email ): int {
		$by_hash = self::find_user_id_by_hashes( $cpf_hash, $rf_hash );
		if ( null !== $by_hash ) {
			return $by_hash;
		}

		if ( '' !== $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				return (int) $user->ID;
			}
		}

		return 0;
	}

	/**
	 * Link orphaned records to a user.
	 *
	 * Updates any orphaned `ffc_submissions` / `ffc_self_scheduling_appointments`
	 * row that matches EITHER of the supplied hashes against its
	 * respective column.
	 *
	 * @param string|null $cpf_hash CPF hash (or null to skip).
	 * @param string|null $rf_hash  RF hash (or null to skip).
	 * @param int         $user_id  WordPress user ID to link.
	 * @return void
	 */
	private static function link_orphaned_records_dual( ?string $cpf_hash, ?string $rf_hash, int $user_id ): void {
		if ( null === $cpf_hash && null === $rf_hash ) {
			return;
		}

		global $wpdb;

		$where_parts = array();
		$params      = array( $user_id );
		if ( null !== $cpf_hash ) {
			$where_parts[] = 'cpf_hash = %s';
			$params[]      = $cpf_hash;
		}
		if ( null !== $rf_hash ) {
			$where_parts[] = 'rf_hash = %s';
			$params[]      = $rf_hash;
		}
		$where             = implode( ' OR ', $where_parts );
		$submissions_table = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where built from hard-coded fragments above with matching placeholder count.
		$linked_submissions = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET user_id = %d WHERE ({$where}) AND user_id IS NULL",
				$submissions_table,
				...$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// ADOPTING A RECORD HAS TO GRANT THE CAP THAT READS IT
		//
		// The appointment half below has always done this and this half never
		// did, in the same method -- so a person whose account was created by
		// a non-certificate path (a promoted candidacy and a reregistration
		// import both grant NOTHING by deliberate decision, and an
		// appointment grants only its own caps) had their old submissions
		// claimed here and no capability to see them.
		//
		// The dashboard then closes twice over: `canViewCertificates` hides
		// the tab client-side and `UserCertificatesRestController` answers
		// 403, so the certificates exist, are linked, and are unreachable.
		// Measured on production: an affected account held
		// `a:1:{s:12:"ffc_end_user";b:1;}` -- the role and not one FFC cap --
		// beside two linked submissions.
		//
		// The defect is a coupling error, not a missing call: the capability
		// came from the path that created the ACCOUNT while ownership comes
		// from the ROW's `user_id`, and adoption moves the second without
		// touching the first. Granting on what was actually claimed is what
		// ties them back together (#1345).
		if ( is_numeric( $linked_submissions ) && (int) $linked_submissions > 0 ) {
			CapabilityManager::grant_certificate_capabilities( $user_id );
		}

		// Appointments link via the existing per-column repository call
		// so the surrounding capability-grant logic stays in one place.
		$appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
		if ( self::table_exists( $appointments_table ) ) {
			$apt_repo            = new \FreeFormCertificate\Repositories\AppointmentRepository();
			$linked_appointments = 0;
			if ( null !== $cpf_hash ) {
				$linked_appointments += $apt_repo->linkByIdentifierHash( $user_id, 'cpf_hash', $cpf_hash );
			}
			if ( null !== $rf_hash ) {
				$linked_appointments += $apt_repo->linkByIdentifierHash( $user_id, 'rf_hash', $rf_hash );
			}
			if ( $linked_appointments > 0 ) {
				CapabilityManager::grant_appointment_capabilities( $user_id );
			}
		}

		/**
		 * Let other modules claim their own unlinked records for this person.
		 *
		 * This class adopts submissions and appointments directly, because
		 * both live in `Repositories`. A candidacy does not: its writer is in
		 * the recruitment module, which already depends on THIS one, so
		 * calling it from here would close a cycle between the two. The
		 * action inverts that -- the same reasoning the CSV export registry
		 * uses to keep Core from naming a feature class (#1345).
		 *
		 * Registered by `Loader::init_plugin()` rather than by
		 * `RecruitmentLoader`, deliberately: that loader is gated on the
		 * Modules-tab toggle, and an adoption that a toggle can skip leaves a
		 * candidacy orphaned with nothing to ever claim it -- the same reason
		 * the recruitment SCHEMA and role registration were relocated to the
		 * orchestrator.
		 *
		 * @since 6.28.0
		 * @param string|null $cpf_hash CPF hash, or null.
		 * @param string|null $rf_hash  RF hash, or null.
		 * @param int         $user_id  The resolved WP user.
		 */
		do_action( 'ffc_adopt_orphaned_identity_records', $cpf_hash, $rf_hash, $user_id );

		self::feed_identity_index( $cpf_hash, $rf_hash, $user_id );
	}

	/**
	 * Record this user's identifiers in the identity index (#1313 PR 4).
	 *
	 * THE INVARIANT: whenever a record gains a link to a user, that user's
	 * identity index receives the identifiers. Without it the index only ever
	 * learns about someone who edits a profile field, which is a subset nobody
	 * can state — and the resolver above would keep falling through to the
	 * module tables forever, never able to collapse to one query.
	 *
	 * **It fills, it never overwrites.** A column that already holds a
	 * different hash is a person with two identifiers on record, and the
	 * honest answer to that is not to pick one silently: it is the conflict
	 * #1313 wants COUNTED before anyone designs a merge policy. Overwriting
	 * would destroy the evidence and leave the index asserting whichever write
	 * happened last. So the index grows monotonically and a disagreement
	 * survives to be found.
	 *
	 * The hashes arrive already canonical: every caller of this method obtained
	 * them through `SensitiveFieldRegistry::hash_identifier()`, which is the
	 * whole point of PR 1.
	 *
	 * @since 6.26.0
	 * @param string|null $cpf_hash CPF hash, or null.
	 * @param string|null $rf_hash  RF hash, or null.
	 * @param int         $user_id  The user the record was linked to.
	 * @return void
	 */
	private static function feed_identity_index( ?string $cpf_hash, ?string $rf_hash, int $user_id ): void {
		if ( $user_id <= 0 || ( null === $cpf_hash && null === $rf_hash ) ) {
			return;
		}

		$repository = new \FreeFormCertificate\Repositories\UserProfileRepository();
		$row        = $repository->findByUserId( $user_id );
		$index      = array();

		foreach ( array(
			'cpf_hash' => $cpf_hash,
			'rf_hash'  => $rf_hash,
		) as $column => $hash ) {
			if ( null === $hash ) {
				continue;
			}

			$stored = null !== $row ? ( $row[ $column ] ?? null ) : null;
			if ( is_string( $stored ) && '' !== $stored ) {
				continue;
			}

			$index[ $column ] = $hash;
		}

		if ( array() === $index ) {
			return;
		}

		$repository->upsertForUserId( $user_id, $index );
	}

	/**
	 * Create an account for records an operator has decided are a new person.
	 *
	 * DELIBERATELY WITHOUT RESOLVING THE IDENTIFIER, WHICH IS WHY IT IS HERE.
	 *
	 * Every other path through this class resolves first, so that a person who
	 * already has an account never gets a second one. A split is the one case
	 * where resolving would defeat the purpose: the identifier currently
	 * resolves to the account the records are being taken OFF, so
	 * {@see self::get_or_create_user_dual()} would hand that same account back
	 * and nothing would move (#1386).
	 *
	 * It lives here rather than in the caller because the rule
	 * `IdentityConvergenceGuardTest` holds is that a WordPress user is created
	 * in ONE place -- not that every creation resolves. An exception argued in
	 * the creation path is reviewable; a second creation path is not.
	 *
	 * No notification is sent: this runs while an operator corrects data, and
	 * an account mail arriving unannounced is a side effect they did not ask
	 * for. Telling the person is theirs to do.
	 *
	 * @since 6.28.3
	 * @param string $email The address the operator supplied.
	 * @return int|\WP_Error The new account's id.
	 */
	public static function create_for_identity_split( string $email ) {
		return self::create_ffc_user( $email, array(), CapabilityManager::CONTEXT_CERTIFICATE, false );
	}

	/**
	 * Create new WordPress user for FFC
	 *
	 * @param string               $email           Email address.
	 * @param array<string, mixed> $submission_data Submission data for user metadata.
	 * @param string               $context         Context for capability granting.
	 * @param bool                 $notify          Whether to send the account
	 *                                              notification. `false` only on
	 *                                              the bulk import path — see
	 *                                              the send site below.
	 * @return int|\WP_Error User ID or error
	 */
	private static function create_ffc_user( string $email, array $submission_data = array(), string $context = CapabilityManager::CONTEXT_CERTIFICATE, bool $notify = true ) {
		$password = wp_generate_password( 24, true, true );
		$username = self::generate_username( $email, $submission_data );
		$user_id  = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
				\FreeFormCertificate\Core\Debug::log_user_manager(
					'Failed to create user',
					array(
						'email' => $email,
						'error' => $user_id->get_error_message(),
					)
				);
			}
			return $user_id;
		}

		$user = new \WP_User( $user_id );
		$user->set_role( 'ffc_end_user' );
		CapabilityManager::grant_context_capabilities( $user_id, $context );
		self::sync_user_metadata( $user_id, $submission_data );
		self::create_user_profile( $user_id );

		// **Bulk creation passes `$notify = false`** (#1214). This send is
		// unconditional otherwise, so an import of 500 rows is 500 synchronous
		// account notifications from inside a batch loop. Suppressing it here is
		// deliberately NOT the same as the global "disable all emails" switch,
		// which has a different blast radius; the campaign's own invitation
		// flow is what tells an imported user their account exists.
		if ( ! $notify ) {
			return $user_id;
		}

		if ( ! class_exists( '\FreeFormCertificate\Integrations\EmailHandler' ) ) {
			$email_handler_file = FFC_PLUGIN_DIR . 'includes/integrations/class-ffc-email-handler.php';
			if ( file_exists( $email_handler_file ) ) {
				require_once $email_handler_file;
			}
		}

		if ( class_exists( '\FreeFormCertificate\Integrations\EmailHandler' ) ) {
			$email_context = CapabilityManager::CONTEXT_APPOINTMENT === $context ? 'appointment' : 'submission';
			$email_handler = new \FreeFormCertificate\Integrations\EmailHandler();
			$email_handler->send_wp_user_notification( $user_id, $email_context );
		}

		return $user_id;
	}

	/**
	 * Generate a unique username for a new FFC user
	 *
	 * @since 4.9.6
	 * @param string               $email           Email (used only as last-resort fallback).
	 * @param array<string, mixed> $submission_data Submission data containing name fields.
	 * @return string Unique username
	 */
	public static function generate_username( string $email, array $submission_data = array() ): string {
		// 1. Prefer the email prefix (everything before `@`) per the
		// plugin-wide convention — keeps usernames stable, predictable
		// for the operator, and consistent across the recruitment CSV
		// import path and the legacy ffc_form submission path.
		$email_prefix = '';
		if ( '' !== $email ) {
			$at_pos = strpos( $email, '@' );
			if ( false !== $at_pos && $at_pos > 0 ) {
				$email_prefix = strtolower( trim( substr( $email, 0, $at_pos ) ) );
			}
		}

		if ( '' !== $email_prefix ) {
			$slug = \FreeFormCertificate\Core\Utils::sanitize_username_slug( $email_prefix );

			if ( strlen( $slug ) >= 3 ) {
				if ( ! username_exists( $slug ) ) {
					return $slug;
				}
				for ( $i = 2; $i <= 99; $i++ ) {
					$candidate = $slug . '.' . $i;
					if ( ! username_exists( $candidate ) ) {
						return $candidate;
					}
				}
			}
		}

		// 2. Fallback to a name-based slug from submission data — covers
		// the (rare) case of an empty / unusable email.
		$possible_names = array( 'nome_completo', 'nome', 'name', 'full_name', 'ffc_nome' );
		$name           = '';

		foreach ( $possible_names as $field ) {
			if ( ! empty( $submission_data[ $field ] ) && is_string( $submission_data[ $field ] ) ) {
				$name = trim( $submission_data[ $field ] );
				break;
			}
		}

		if ( ! empty( $name ) ) {
			$slug = \FreeFormCertificate\Core\Utils::sanitize_username_slug( strtolower( $name ) );

			if ( strlen( $slug ) >= 3 ) {
				if ( ! username_exists( $slug ) ) {
					return $slug;
				}

				for ( $i = 2; $i <= 99; $i++ ) {
					$candidate = $slug . '.' . $i;
					if ( ! username_exists( $candidate ) ) {
						return $candidate;
					}
				}
			}
		}

		// 3. Last resort: random.
		do {
			$username = 'ffc_' . wp_generate_password( 8, false, false );
		} while ( username_exists( $username ) );

		return $username;
	}

	/**
	 * Sync user metadata from submission data
	 *
	 * @param int                  $user_id         WordPress user ID.
	 * @param array<string, mixed> $submission_data Submission data.
	 * @return void
	 */
	private static function sync_user_metadata( int $user_id, array $submission_data ): void {
		if ( empty( $submission_data ) ) {
			return;
		}

		$nome_completo  = '';
		$possible_names = array( 'nome_completo', 'nome', 'name', 'full_name', 'ffc_nome' );

		foreach ( $possible_names as $field ) {
			if ( ! empty( $submission_data[ $field ] ) ) {
				$nome_completo = $submission_data[ $field ];
				break;
			}
		}

		if ( ! empty( $nome_completo ) ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $nome_completo,
					'first_name'   => $nome_completo,
				)
			);
		}

		update_user_meta( $user_id, 'ffc_registration_date', current_time( 'mysql' ) );
	}

	/**
	 * Create user profile entry in ffc_user_profiles
	 *
	 * @since 4.9.4
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	private static function create_user_profile( int $user_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_user_profiles';

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$user         = get_userdata( $user_id );
		$display_name = $user ? $user->display_name : '';

		( new \FreeFormCertificate\Repositories\UserProfileRepository() )->createForUser( $user_id, $display_name );
	}
}
