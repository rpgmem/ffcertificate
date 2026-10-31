<?php
/**
 * The personal-calendar reminder query, against a real database (#2244).
 *
 * **WHY A REAL DATABASE.** The unit suite asserts the SQL text and the values
 * it binds, against a `$wpdb` double that answers whatever the test supplies.
 * That is how the old query shipped: `DATE_SUB( date, 'HH:MM:SS' )` returns
 * NULL on MariaDB, so every comparison was false and no reminder was ever
 * sent, while every unit test stayed green. Only a server can say what a
 * predicate selects.
 *
 * **WHAT IT PLANTS.** Appointments on one throwaway calendar id, placed around
 * the site's "now" in a timezone that is not UTC, so a window computed in the
 * wrong clock selects the wrong rows:
 *
 *   - confirmed, 1 hour and 23 hours ahead -- due (the second one lies on the
 *     next date whenever now is after 01:00, so the window crosses midnight);
 *   - confirmed, 1 hour ago -- already started, never reminded now;
 *   - confirmed, 25 hours ahead -- outside a 24-hour window;
 *   - confirmed, 2 hours ahead but already reminded;
 *   - cancelled and pending, inside the window.
 *
 * Then `getUpcomingForReminders( 24 )` must return exactly the two due rows
 * of that calendar. An empty answer fails: it is what the broken query gave.
 *
 * **WHY THIS JOB.** `fresh-install` has the only real MariaDB and the freshly
 * created schema. Everything planted is deleted before the script exits, and
 * the uninstall steps after it remove the rest.
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

$wp_root = (string) ( $argv[1] ?? '' );

if ( '' === $wp_root || ! is_readable( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php self-scheduling-query-check.php <wp-root>\n" );
	exit( 2 );
}

require_once $wp_root . '/wp-load.php';

global $wpdb;

$table       = $wpdb->prefix . 'ffc_self_scheduling_appointments';
$calendar_id = 987654321;

/**
 * Delete what this script planted, report a failure and stop.
 *
 * @param string $message Why.
 * @return never
 */
function ffc_ss_query_fail( string $message ) {
	global $wpdb, $table, $calendar_id;
	$wpdb->delete( $table, array( 'calendar_id' => $calendar_id ), array( '%d' ) );
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
	ffc_ss_query_fail( "{$table} does not exist, so the query cannot be measured." );
}

// A site timezone away from UTC, so a window read in the server clock misses.
update_option( 'timezone_string', 'America/Sao_Paulo' );
update_option( 'gmt_offset', '' );

$now = new \DateTimeImmutable( 'now', wp_timezone() );

/**
 * Plant one appointment and return its id.
 *
 * @param string   $offset   Relative to now, e.g. '+1 hour'.
 * @param string   $status   Appointment status.
 * @param int|null $reminded `reminder_sent_at`, or null.
 * @return int
 */
function ffc_ss_plant( string $offset, string $status, ?int $reminded = null ): int {
	global $wpdb, $table, $calendar_id, $now;
	$at  = $now->modify( $offset );
	$row = array(
		'calendar_id'      => $calendar_id,
		'appointment_date' => $at->format( 'Y-m-d' ),
		'start_time'       => $at->format( 'H:i:s' ),
		'end_time'         => $at->modify( '+30 minutes' )->format( 'H:i:s' ),
		'status'           => $status,
		'created_at'       => current_time( 'mysql' ),
	);
	if ( null !== $reminded ) {
		$row['reminder_sent_at'] = $reminded;
	}
	if ( ! $wpdb->insert( $table, $row ) ) {
		ffc_ss_query_fail( 'Could not plant an appointment: ' . $wpdb->last_error );
	}
	return (int) $wpdb->insert_id;
}

$due = array(
	ffc_ss_plant( '+1 hour', 'confirmed' ),
	ffc_ss_plant( '+23 hours', 'confirmed' ),
);
ffc_ss_plant( '-1 hour', 'confirmed' );
ffc_ss_plant( '+25 hours', 'confirmed' );
ffc_ss_plant( '+2 hours', 'confirmed', time() );
ffc_ss_plant( '+3 hours', 'cancelled' );
ffc_ss_plant( '+4 hours', 'pending' );

$rows = ( new \FreeFormCertificate\Repositories\AppointmentRepository() )->getUpcomingForReminders( 24 );
$got  = array();
foreach ( $rows as $row ) {
	if ( (int) $row['calendar_id'] === $calendar_id ) {
		$got[] = (int) $row['id'];
	}
}

if ( array() === $got ) {
	ffc_ss_query_fail( 'The reminder query selected nothing, which is what the broken DATE_SUB query returned on MariaDB.' );
}

if ( $got !== $due ) {
	ffc_ss_query_fail(
		'The reminder query selected ' . wp_json_encode( $got ) . ' where exactly ' . wp_json_encode( $due )
		. ' (confirmed, not reminded, starting within 24 hours of ' . $now->format( 'Y-m-d H:i' ) . ' site time) were due.'
	);
}

$wpdb->delete( $table, array( 'calendar_id' => $calendar_id ), array( '%d' ) );

echo 'REMINDER QUERY HOLDS: ' . count( $due ) . ' due appointment(s) selected out of 7 planted, at '
	. $now->format( 'Y-m-d H:i' ) . " site time.\n";
