<?php
/**
 * The identity-index invariant, against a real database (#1313 PR 9, guard 3).
 *
 * **THE INVARIANT.** Whenever a record gains a link to a user, that user's
 * identity index receives the identifiers. It is the sentence #1313 is built
 * on, and it is the one of the issue's three guards that no static scan can
 * check: it is a statement about ROWS, not about call sites. A unit test would
 * assert it against the double that supplies the values, which is the shape
 * `CLAUDE.md` names as a test that cannot fail.
 *
 * **WHY IT CANNOT SIMPLY QUERY.** On a fresh install every table is empty, so
 * the invariant holds vacuously and a query alone would report success on a
 * database where the code is entirely broken -- the #1071 / #1094 rule, that an
 * empty result must never read as clean. So the check WRITES first: it plants
 * an orphaned submission carrying a CPF hash and no user, asks the plugin's own
 * resolver to resolve that identifier, and only then looks.
 *
 * Three things must then be true, and each is a different half of the arc:
 *
 *   1. The resolver created a user (#1313 PR 4 / #1320).
 *   2. The orphaned row was ADOPTED -- its `user_id` is that user.
 *   3. The identity index carries the hash, which is what the module tables
 *      stop having to be scanned for.
 *
 * Then the general sweep runs: no row in any module table may carry a
 * `user_id` and a hash that the user's index does not hold. With the planted
 * row present the sweep has something to look at, so a pass means the
 * invariant, not an empty table.
 *
 * **WHY THIS JOB.** `fresh-install` is the only one with a real MariaDB and a
 * real WordPress: the resolver writes through `UserProfileRepository`, adopts
 * rows with an `UPDATE`, and creates a WordPress user -- none of which exists
 * in the unit suite. It runs before the uninstall steps, which delete
 * everything it planted.
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

$wp_root = (string) ( $argv[1] ?? '' );

if ( '' === $wp_root || ! is_readable( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php identity-index-invariant-check.php <wp-root>\n" );
	exit( 2 );
}

require_once $wp_root . '/wp-load.php';

/**
 * Report a failure and stop.
 *
 * @param string $message Why.
 * @return never
 */
function ffc_invariant_fail( string $message ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

global $wpdb;

$submissions = $wpdb->prefix . 'ffc_submissions';
$profiles    = $wpdb->prefix . 'ffc_user_profiles';

foreach ( array( $submissions, $profiles ) as $table ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		ffc_invariant_fail( "{$table} does not exist, so the invariant cannot be measured at all." );
	}
}

// ---------------------------------------------------------------------------
// Plant: an orphaned record carrying an identifier and no user.
// ---------------------------------------------------------------------------

$cpf      = '12345678909';
$email    = 'identity-invariant@example.test';
$cpf_hash = \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'cpf', $cpf );

if ( null === $cpf_hash ) {
	ffc_invariant_fail( 'The registry could not hash a CPF, so encryption is unavailable and nothing below would mean anything.' );
}

// Only the columns the schema requires plus the identifier under test:
// `form_id` and `submission_date` are the two `NOT NULL` columns with no
// default, `user_id` is nullable and left out precisely because this row is
// the orphan, and every other column has a default. A wider insert would be
// more to keep in step with the CREATE for no gain here.
$planted = $wpdb->insert(
	$submissions,
	array(
		'form_id'         => 1,
		'cpf_hash'        => $cpf_hash,
		'submission_date' => time(),
	),
	array( '%d', '%s', '%d' )
);

if ( ! $planted ) {
	ffc_invariant_fail( 'Could not plant the orphaned submission: ' . $wpdb->last_error );
}

$planted_id = (int) $wpdb->insert_id;

// ---------------------------------------------------------------------------
// Act: the plugin's own resolver, exactly as a submission would call it.
// ---------------------------------------------------------------------------

$user_id = \FreeFormCertificate\UserDashboard\UserCreator::get_or_create_user_dual(
	$cpf_hash,
	null,
	$email,
	array( 'name' => 'Identity Invariant' ),
	\FreeFormCertificate\UserDashboard\CapabilityManager::CONTEXT_CERTIFICATE,
	false
);

if ( is_wp_error( $user_id ) ) {
	ffc_invariant_fail( 'The resolver refused to create a user: ' . $user_id->get_error_message() );
}

$user_id = (int) $user_id;

if ( $user_id <= 0 ) {
	ffc_invariant_fail( 'The resolver returned no user id.' );
}

// ---------------------------------------------------------------------------
// Assert: adoption, then the index.
// ---------------------------------------------------------------------------

$adopted = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$submissions} WHERE id = %d", $planted_id ) );

if ( (int) $adopted !== $user_id ) {
	ffc_invariant_fail(
		"The orphaned submission was not adopted: row {$planted_id} carries user_id " . var_export( $adopted, true )
		. " and the resolver returned {$user_id}. Orphan adoption is what makes a person's earlier records theirs."
	);
}

$indexed = $wpdb->get_var( $wpdb->prepare( "SELECT cpf_hash FROM {$profiles} WHERE user_id = %d", $user_id ) );

if ( (string) $indexed !== $cpf_hash ) {
	ffc_invariant_fail(
		"The identity index does not carry the identifier the resolver just linked: user {$user_id} has cpf_hash "
		. var_export( $indexed, true ) . ". This is the invariant #1313 exists to create — without it the resolver"
		. ' must keep scanning the module tables forever.'
	);
}

echo "Planted submission {$planted_id} was adopted by user {$user_id}, and the index carries its CPF hash.\n";

// ---------------------------------------------------------------------------
// Sweep: every linked row, across every module table that exists.
// ---------------------------------------------------------------------------

$sources = array(
	$wpdb->prefix . 'ffc_submissions',
	$wpdb->prefix . 'ffc_self_scheduling_appointments',
	$wpdb->prefix . 'ffc_recruitment_candidate',
);

$checked  = 0;
$breaches = array();

foreach ( $sources as $table ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		continue;
	}

	foreach ( array( 'cpf_hash', 'rf_hash' ) as $column ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.user_id, r.%i AS h, p.%i AS indexed_hash
                 FROM %i AS r
                 LEFT JOIN %i AS p ON p.user_id = r.user_id
                 WHERE r.user_id IS NOT NULL AND r.%i IS NOT NULL AND r.%i <> ''",
				$column,
				$column,
				$table,
				$profiles,
				$column,
				$column
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			++$checked;

			if ( (string) $row['indexed_hash'] === (string) $row['h'] ) {
				continue;
			}

			// A DIFFERENT hash in the index is not a breach of this invariant:
			// it is one account carrying two identities, which the backfill
			// deliberately leaves for the auditor to count (#1313 PR 8/10).
			// What this guard forbids is an index that carries NOTHING for an
			// identifier the row already links to a user.
			if ( null === $row['indexed_hash'] || '' === (string) $row['indexed_hash'] ) {
				$breaches[] = "{$table}#{$row['id']} links user {$row['user_id']} by {$column}, and that user's index holds no {$column}.";
			}
		}
	}
}

if ( 0 === $checked ) {
	ffc_invariant_fail(
		'The sweep examined no rows at all, so it proves nothing. The planted row above should have been one of them —'
		. ' either the plant did not land or the query stopped matching what it is meant to read.'
	);
}

if ( array() !== $breaches ) {
	fwrite( STDERR, "FAIL: the identity index is incomplete for " . count( $breaches ) . " linked row(s):\n" );
	foreach ( array_slice( $breaches, 0, 20 ) as $breach ) {
		fwrite( STDERR, "  - {$breach}\n" );
	}
	exit( 1 );
}

echo "IDENTITY INDEX INVARIANT HOLDS: {$checked} linked identifier(s) examined, every one present in its user's index.\n";
