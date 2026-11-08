<?php
/**
 * The date-message delivery log and birthday query, against a real database
 * (#2336).
 *
 * **WHY A REAL DATABASE.** The deduplication of a date message IS a database
 * property: `INSERT IGNORE` against the `uq_delivery` unique key decides who
 * may send, and a retried batch counts each decision once by the same key.
 * Every unit test runs against a `$wpdb` double that answers what the test
 * supplies, so none of them can show that the key exists, that it refuses a
 * second claim, that a released claim can be taken again, that the birthday
 * query over `wp_usermeta` selects the right month-days (29 February
 * included), or that the purge removes a run with its deliveries.
 *
 * **WHAT IT PLANTS.** Three throwaway accounts born on 28 February, 29
 * February and 1 March, and runs under a rule id no install uses. Everything
 * planted is deleted before the script exits.
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

$wp_root = (string) ( $argv[1] ?? '' );

if ( '' === $wp_root || ! is_readable( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php date-messages-log-check.php <wp-root>\n" );
	exit( 2 );
}

require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use FreeFormCertificate\DateMessages\BirthdaySource;
use FreeFormCertificate\DateMessages\DateMessagesActivator;
use FreeFormCertificate\DateMessages\DeliveryLog;
use FreeFormCertificate\UserDashboard\UserProfileFieldMap;

global $wpdb;

$ffc_dm_rule  = 987654323;
$ffc_dm_users = array();

/**
 * Remove what the script planted.
 *
 * @return void
 */
function ffc_dm_cleanup(): void {
	global $wpdb, $ffc_dm_rule, $ffc_dm_users;
	$wpdb->delete( DateMessagesActivator::log_table(), array( 'rule_id' => $ffc_dm_rule ), array( '%d' ) );
	$wpdb->delete( DateMessagesActivator::runs_table(), array( 'rule_id' => $ffc_dm_rule ), array( '%d' ) );
	foreach ( $ffc_dm_users as $user_id ) {
		wp_delete_user( $user_id );
	}
}

/**
 * Clean up, report a failure and stop.
 *
 * @param string $message Why.
 * @return never
 */
function ffc_dm_fail( string $message ) {
	ffc_dm_cleanup();
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   What it means when false.
 * @return void
 */
function ffc_dm_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		ffc_dm_fail( $message );
	}
}

foreach ( array( DateMessagesActivator::log_table(), DateMessagesActivator::runs_table() ) as $ffc_dm_table ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ffc_dm_table ) ) !== $ffc_dm_table ) {
		ffc_dm_fail( "{$ffc_dm_table} does not exist, so nothing can be measured." );
	}
}

// Three accounts: born on 28 February, 29 February and 1 March.
foreach ( array( 'feb28' => '02-28', 'feb29' => '02-29', 'mar01' => '03-01' ) as $ffc_dm_login => $ffc_dm_md ) {
	$ffc_dm_id = wp_insert_user(
		array(
			'user_login' => 'ffc-dm-check-' . $ffc_dm_login,
			'user_email' => 'ffc-dm-check-' . $ffc_dm_login . '@example.test',
			'user_pass'  => wp_generate_password( 24 ),
		)
	);
	if ( is_wp_error( $ffc_dm_id ) ) {
		ffc_dm_fail( 'Could not plant an account: ' . $ffc_dm_id->get_error_message() );
	}
	$ffc_dm_users[ $ffc_dm_login ] = (int) $ffc_dm_id;
	update_user_meta( (int) $ffc_dm_id, UserProfileFieldMap::BIRTH_MONTH_DAY_META_KEY, $ffc_dm_md );
}

/**
 * The planted accounts a birthday query selects on a day.
 *
 * @param string $day   Target day, `Y-m-d`.
 * @param int    $after Keyset cursor.
 * @param int    $limit Page size.
 * @return list<string> Logins, without the prefix.
 */
function ffc_dm_due( string $day, int $after = 0, int $limit = 500 ): array {
	global $ffc_dm_users;
	$target = new DateTimeImmutable( $day, wp_timezone() );
	$ids    = array_column( ( new BirthdaySource() )->due( $target, $after, $limit ), 'user_id' );
	$found  = array();
	foreach ( $ffc_dm_users as $login => $id ) {
		if ( in_array( $id, $ids, true ) ) {
			$found[] = $login;
		}
	}
	return $found;
}

// 29 February is treated as the 28th, in common and leap years alike.
ffc_dm_assert( array( 'feb28', 'feb29' ) === ffc_dm_due( '2027-02-28' ), '28 Feb of a common year must select the 28th and the 29th.' );
ffc_dm_assert( array( 'feb28', 'feb29' ) === ffc_dm_due( '2028-02-28' ), '28 Feb of a leap year must select the 28th and the 29th.' );
ffc_dm_assert( array() === ffc_dm_due( '2028-02-29' ), 'A leap year\'s 29 Feb must select nobody.' );
ffc_dm_assert( array( 'mar01' ) === ffc_dm_due( '2027-03-01' ), '1 March must select only its own.' );
// The keyset cursor: after the 28th's account, a page of one is the 29th's.
ffc_dm_assert( array( 'feb29' ) === ffc_dm_due( '2027-02-28', $ffc_dm_users['feb28'], 1 ), 'The cursor must continue after the last id read.' );

// The claim: the unique key decides who may send.
$ffc_dm_run = DeliveryLog::start_run( $ffc_dm_rule, 'cron', '2027-02-28', '2027-02-28', 0 );
ffc_dm_assert( $ffc_dm_run > 0, 'Could not open a run: ' . $wpdb->last_error );
$ffc_dm_user = $ffc_dm_users['feb28'];

ffc_dm_assert( DeliveryLog::claim( $ffc_dm_run, $ffc_dm_rule, $ffc_dm_user, '2027-02-28' ), 'The first claim must succeed.' );
ffc_dm_assert( ! DeliveryLog::claim( $ffc_dm_run + 1, $ffc_dm_rule, $ffc_dm_user, '2027-02-28' ), 'A second claim, even from another run, must lose to uq_delivery.' );
ffc_dm_assert( array( $ffc_dm_user => true ) === DeliveryLog::delivered_among( $ffc_dm_rule, '2027-02-28', array( $ffc_dm_user ) ), 'The claimed delivery must be read back.' );

DeliveryLog::release( $ffc_dm_rule, $ffc_dm_user, '2027-02-28' );
ffc_dm_assert( array() === DeliveryLog::delivered_among( $ffc_dm_rule, '2027-02-28', array( $ffc_dm_user ) ), 'A released claim must be gone.' );
ffc_dm_assert( DeliveryLog::claim( $ffc_dm_run, $ffc_dm_rule, $ffc_dm_user, '2027-02-28' ), 'A released claim must be claimable again.' );

// A decision counted once per run, however often its batch fires.
$ffc_dm_other = $ffc_dm_users['feb29'];
DeliveryLog::count_once( $ffc_dm_run, $ffc_dm_rule, $ffc_dm_other, '2027-02-28', 'opted_out' );
DeliveryLog::count_once( $ffc_dm_run, $ffc_dm_rule, $ffc_dm_other, '2027-02-28', 'opted_out' );
$ffc_dm_row = DeliveryLog::get_run( $ffc_dm_run );
ffc_dm_assert( null !== $ffc_dm_row && 1 === (int) $ffc_dm_row['opted_out'], 'A decision met twice in one run must count once.' );
ffc_dm_assert( array() === DeliveryLog::delivered_among( $ffc_dm_rule, '2027-02-28', array( $ffc_dm_other ) ), 'A counted decision must never read as a delivery.' );

// Another run counts the same person again: the key carries the run.
$ffc_dm_second = DeliveryLog::start_run( $ffc_dm_rule, 'manual', '2027-02-28', '2027-02-28', 0 );
DeliveryLog::count_once( $ffc_dm_second, $ffc_dm_rule, $ffc_dm_other, '2027-02-28', 'opted_out' );
$ffc_dm_row = DeliveryLog::get_run( $ffc_dm_second );
ffc_dm_assert( null !== $ffc_dm_row && 1 === (int) $ffc_dm_row['opted_out'], 'A second run must count its own decisions.' );

// The purge: an old run goes with its deliveries; a recent one stays.
$wpdb->update( DateMessagesActivator::runs_table(), array( 'started_at' => time() - 400 * DAY_IN_SECONDS ), array( 'id' => $ffc_dm_run ) );
DeliveryLog::purge_expired();
ffc_dm_assert( null === DeliveryLog::get_run( $ffc_dm_run ), 'A run older than the retention must be purged.' );
ffc_dm_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %d', DateMessagesActivator::log_table(), $ffc_dm_run ) ), 'Its deliveries must go with it.' );
ffc_dm_assert( null !== DeliveryLog::get_run( $ffc_dm_second ), 'A recent run must stay.' );

ffc_dm_cleanup();
echo "OK: the claim, release, counted-once decisions, birthday query and purge hold against the real database.\n";
exit( 0 );
