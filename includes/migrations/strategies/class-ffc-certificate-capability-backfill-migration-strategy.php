<?php
/**
 * Certificate-capability backfill migration strategy.
 *
 * Grants the certificate capabilities to every account that owns a
 * certificate submission and cannot read it.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.28.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repair for accounts that own a certificate and hold no capability to see it.
 *
 * WHAT WENT WRONG, BECAUSE THE SHAPE IS WHAT MAKES THE REPAIR SAFE
 *
 * The capability to read a certificate was granted by the path that created
 * the ACCOUNT, while ownership of a certificate is established by the ROW's
 * `user_id`. `UserCreator::link_orphaned_records_dual()` moves the second
 * without touching the first: it claims a person's old submissions, and
 * until #1345 it granted nothing for them. An account created by a promoted
 * candidacy or a reregistration import grants no capability at all by
 * deliberate decision, and an appointment grants only its own -- so a person
 * arriving through any of those paths had their certificates claimed and no
 * way to see them.
 *
 * The dashboard then closes twice over: `canViewCertificates` hides the tab
 * client-side and `UserCertificatesRestController` answers 403. Measured on
 * production, 2026-09-20: **1,478 accounts** own a non-trashed submission and
 * carry no `ffc_view_own_certificates`; a sampled one held
 * `a:1:{s:12:"ffc_end_user";b:1;}` beside two linked certificates.
 *
 * IT MEASURES, IT DOES NOT LATCH
 *
 * `pending` is computed from the data on every read, so "complete" means the
 * query returns zero and re-running is a no-op by construction. That puts
 * this card with the five that cannot lie about themselves rather than with
 * the one whose flag is one-way -- the distinction #1345 records after the
 * identity-index card reported 100% while 72 accounts were missing from it.
 *
 * THE POPULATION IS DEFINED BY OWNERSHIP, WITH NO EXCEPTIONS
 *
 * Anybody who owns a certificate should be able to read it, so there is no
 * role carve-out here: an administrator who owns one is in scope like anyone
 * else. That also keeps the measurement honest, since every exclusion is a
 * row the count would have to explain away. It is the mirror of the reason
 * `CapabilityManager::CONTEXT_REREGISTRATION` grants nothing -- a cap is
 * given for something the person actually holds, never on spec.
 *
 * IT ANNOUNCES THE GRANT RATHER THAN PERFORMING IT
 *
 * `CapabilityManager` owns every capability grant in the plugin, and naming
 * it from here would add `Migrations > UserDashboard` to a graph that already
 * carries `UserDashboard > Migrations` -- a cycle. The batch fires
 * `ffc_grant_certificate_capabilities` instead. The card cannot lie about the
 * result of that inversion either: `pending` is measured from the data, so a
 * listener that never ran shows as a number that does not move.
 *
 * @since 6.28.2
 */
class CertificateCapabilityBackfillMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * The capability whose absence defines the population.
	 *
	 * One of the three {@see CapabilityManager::CERTIFICATE_CAPABILITIES},
	 * and the one the dashboard actually gates on -- both in
	 * `DashboardAssetManager::enqueue_assets()` and in the REST controller.
	 * The grant writes all three, because that is what every other
	 * certificate grant in the plugin does; measuring on one of them is what
	 * lets the count be a single indexed read.
	 *
	 * @var string
	 */
	private const GATE_CAPABILITY = 'ffc_view_own_certificates';

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		$total   = $this->count_owners();
		$pending = $this->count_owners_without_capability();

		$migrated = max( 0, $total - $pending );
		$percent  = ( $total > 0 ) ? ( $migrated / $total ) * 100 : 100;

		return array(
			'total'       => $total,
			'migrated'    => $migrated,
			'pending'     => $pending,
			'percent'     => round( $percent, 2 ),
			'is_complete' => ( 0 === $pending ),
		);
	}

	/**
	 * Grant the certificate capabilities to one batch of affected accounts.
	 *
	 * Cursor-free on purpose: every account it repairs leaves the pending set
	 * by that repair, so the next batch reads the next hundred with no offset
	 * to keep. A batch that grants nothing therefore means the work is done,
	 * never that a cursor ran off the end.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number     Batch number (unused -- see above).
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		// `$migration_config` is read after `ffcertificate_migrations_registry`
		// has run, so a filter can put anything here; `(int) 'x'` is 0 and a
		// batch of 0 would report completion having done nothing (#1060).
		$batch_size = isset( $migration_config['batch_size'] ) && is_numeric( $migration_config['batch_size'] )
			? max( 1, (int) $migration_config['batch_size'] )
			: 100;

		$user_ids  = $this->owners_without_capability( $batch_size );
		$processed = 0;

		foreach ( $user_ids as $user_id ) {
			// TERMINATION MUST NOT DEPEND ON THE MEASURE BEING RIGHT.
			//
			// It did, and that is what looped: this counted accounts SELECTED
			// rather than accounts CHANGED, so a grant that was a no-op still
			// reported progress and the driver never stopped. An account that
			// already holds the capability is skipped here, which makes a
			// batch that changes nothing return 0 -- the condition the
			// docblock above always claimed and the code never produced.
			if ( user_can( (int) $user_id, self::GATE_CAPABILITY ) ) {
				continue;
			}

			/**
			 * Grant the certificate capabilities to one repaired account.
			 *
			 * AN ACTION, BECAUSE A DIRECT CALL WOULD CLOSE A CYCLE
			 *
			 * `CapabilityManager` is the one place that grants a capability,
			 * and naming it from here would add `Migrations > UserDashboard`
			 * while `UserDashboard > Migrations` already exists -- making the
			 * two mutually dependent. That is the same wall #1349 hit between
			 * recruitment and the resolver, and it takes the same answer: the
			 * producer announces and the owner subscribes, registered by
			 * `Loader::init_plugin()`.
			 *
			 * It opens no surface that was not already open: the method it
			 * reaches is a public static one, so anything able to fire this
			 * action could have called it directly.
			 *
			 * @since 6.28.2
			 * @param int $user_id Account that owns a certificate it cannot read.
			 */
			do_action( 'ffc_grant_certificate_capabilities', (int) $user_id );
			++$processed;
		}

		return array(
			'success'   => true,
			'processed' => $processed,
			'message'   => sprintf(
				/* translators: %d: how many accounts were repaired in this batch. */
				__( 'Accounts that can now read the certificates they own: %d', 'ffcertificate' ),
				$processed
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|\WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'certificate_capability_backfill';
	}

	/**
	 * Every account that owns a certificate submission.
	 *
	 * @return int
	 */
	private function count_owners(): int {
		global $wpdb;

		$table = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own submissions table, for which WordPress exposes no API; a migration card must reflect the live rows, so a cached count would be precisely wrong.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM %i WHERE user_id IS NOT NULL AND user_id <> 0 AND status <> 'trash'",
				$table
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Every role whose definition grants the gate capability.
	 *
	 * Read from `wp_roles()`, which is the `wp_user_roles` option -- the only
	 * place a role's capabilities exist. A user holding one of these roles has
	 * the capability without a single character of it appearing in their own
	 * `wp_capabilities` meta.
	 *
	 * @since 6.28.2
	 * @return array<int, string>
	 */
	private static function roles_granting_gate(): array {
		// The ternary is what keeps the guard meaningful to PHPStan -- the
		// stubs type `wp_roles()` as always returning `WP_Roles`, while the
		// test harness stubs it as NULL on purpose, which is how an unguarded
		// read of `->roles` fatals in a suite and nowhere else. `is_object`
		// rather than `instanceof WP_Roles` because the doubles in this
		// repository are plain objects, and refusing one would make the guard
		// untestable by the project's own idiom.
		$roles = function_exists( 'wp_roles' ) ? wp_roles() : null;
		$all   = is_object( $roles ) ? (array) $roles->roles : array();
		$out   = array();

		foreach ( $all as $slug => $definition ) {
			$caps = ( isset( $definition['capabilities'] ) && is_array( $definition['capabilities'] ) )
				? $definition['capabilities']
				: array();

			if ( ! empty( $caps[ self::GATE_CAPABILITY ] ) ) {
				$out[] = (string) $slug;
			}
		}

		return $out;
	}

	/**
	 * How many of those cannot read what they own.
	 *
	 * @return int
	 */
	private function count_owners_without_capability(): int {
		return count( $this->owners_without_capability( 0 ) );
	}

	/**
	 * The accounts that own a certificate and lack the capability to read it.
	 *
	 * WHY THIS JOINS `wp_usermeta` RATHER THAN ASKING `user_can()`
	 *
	 * The honest per-user question is `user_can( $id, … )`, and asking it once
	 * per owner would mean thousands of calls on every render of the
	 * Migrations tab. The capability meta is a single indexed row per user, so
	 * one join answers the set question -- the same reasoning the identity
	 * audit's account facts use, and the reason `CapabilityMigrator` prefilters
	 * on that meta too. The grant it feeds is idempotent and re-asks the real
	 * question through `has_cap()`, so a false positive here costs a no-op and
	 * never a wrong write.
	 *
	 * `esc_like()` is not decoration: `_` is a LIKE wildcard and this
	 * capability name carries four of them, so an unescaped term would match
	 * strings that are not it.
	 *
	 * @param int $limit Max rows, or 0 for every one.
	 * @return array<int, int>
	 */
	private function owners_without_capability( int $limit ): array {
		global $wpdb;

		$table    = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();
		$meta_key = $wpdb->get_blog_prefix() . 'capabilities';
		$term     = '%' . $wpdb->esc_like( self::GATE_CAPABILITY ) . '%';

		// A CAPABILITY HELD THROUGH A ROLE IS NOT IN THE USER'S META.
		//
		// `wp_capabilities` carries the role NAME plus per-user grants; the
		// role's own capabilities live in the `wp_user_roles` option. So a
		// LIKE for the capability cannot see it, and the account is counted
		// pending forever while `has_cap()` -- which does resolve the role --
		// makes every grant a no-op. That is what made this card loop.
		//
		// It never showed on the 1,478 ordinary accounts because `ffc_end_user`
		// carries no FFC capability at all: there the grant really is per user.
		// It shows the moment one owner holds the capability through
		// `ffc_administrator` or any module role.
		//
		// Resolved from the roles rather than per user: one option read here
		// against one `get_userdata()` per candidate, on a card that renders
		// its count on every page load.
		$sql = "SELECT s.user_id
                  FROM %i s
                  JOIN {$wpdb->usermeta} um ON um.user_id = s.user_id AND um.meta_key = %s
                 WHERE s.user_id IS NOT NULL AND s.user_id <> 0 AND s.status <> 'trash'
                   AND um.meta_value NOT LIKE %s";

		$values = array( $table, $meta_key, $term );

		foreach ( self::roles_granting_gate() as $role ) {
			// The SERIALIZED form, never the bare slug: `%administrator%`
			// also matches `ffc_administrator`, and over-excluding here
			// reports work as finished that never happened.
			$sql     .= ' AND um.meta_value NOT LIKE %s';
			$values[] = '%' . $wpdb->esc_like( '"' . $role . '";b:1' ) . '%';
		}

		$sql .= ' GROUP BY s.user_id ORDER BY s.user_id ASC';

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d';
			$values[] = $limit;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- `wp_usermeta` is named per line rather than under a file-level disable, which this file deliberately does not carry: the only interpolation is `$wpdb->usermeta`, a core property, and every value travels as a placeholder. A migration card must reflect the live rows, never a cache.
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, ...$values ) );

		return array_map( 'intval', (array) $rows );
	}
}
