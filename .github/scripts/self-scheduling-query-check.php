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
 * **THE SLOT QUERIES.** The slot listing reads a date's blocked dates in one
 * query and matches them per slot in PHP, and the booking counts capacity
 * per exact start time. Both are checked on planted rows: the blocks that
 * reach a date (and none from another day), and two confirmed plus one
 * cancelled appointment filling a slot of two.
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

// ---------------------------------------------------------------------------
// The slot queries (#2244): the blocks a date reads in one query, and the
// per-slot capacity count the booking path takes under lock.
// ---------------------------------------------------------------------------

$blocked = $wpdb->prefix . 'ffc_self_scheduling_blocked_dates';
$day     = $now->modify( '+30 days' )->format( 'Y-m-d' );
$planted_blocks = array();
foreach (
	array(
		array( 'block_type' => 'time_range', 'start_date' => $day, 'end_date' => $day, 'start_time' => '10:00:00', 'end_time' => '11:00:00' ),
		// A single day written with no end date (#2244 PR 6 makes this mean one day everywhere).
		array( 'block_type' => 'full_day', 'start_date' => $day ),
		// Another day: must not be read for $day.
		array( 'block_type' => 'full_day', 'start_date' => $now->modify( '+31 days' )->format( 'Y-m-d' ), 'end_date' => $now->modify( '+31 days' )->format( 'Y-m-d' ) ),
		// The day before, with no end date: one day, so not read for $day
		// either (it used to read as never ending).
		array( 'block_type' => 'full_day', 'start_date' => $now->modify( '+29 days' )->format( 'Y-m-d' ) ),
	) as $block
) {
	if ( ! $wpdb->insert( $blocked, array_merge( array( 'calendar_id' => $calendar_id, 'created_at' => current_time( 'mysql' ) ), $block ) ) ) {
		ffc_ss_query_fail( 'Could not plant a blocked date: ' . $wpdb->last_error );
	}
	$planted_blocks[] = (int) $wpdb->insert_id;
}

$blocks_repo = new \FreeFormCertificate\Repositories\BlockedDateRepository();
$read        = array_map( 'intval', array_column( $blocks_repo->getBlocksForDate( $calendar_id, $day ), 'id' ) );
sort( $read );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE calendar_id = %d', $blocked, $calendar_id ) );

if ( array( $planted_blocks[0], $planted_blocks[1] ) !== $read ) {
	ffc_ss_query_fail( 'getBlocksForDate() read ' . wp_json_encode( $read ) . ' for ' . $day . ' where exactly ' . wp_json_encode( array( $planted_blocks[0], $planted_blocks[1] ) ) . ' reach it.' );
}
if ( ! \FreeFormCertificate\Repositories\BlockedDateRepository::blocksMatch( array( array( 'block_type' => 'time_range', 'start_time' => '10:00:00', 'end_time' => '11:00:00' ) ), $day, '10:30:00' ) ) {
	ffc_ss_query_fail( 'A time-range block read from the database does not close the slot inside it.' );
}
if ( ! \FreeFormCertificate\Repositories\BlockedDateRepository::blocksMatch( array( array( 'block_type' => 'time_range', 'start_time' => '10:00:00', 'end_time' => '11:00:00' ) ), $day, '09:30:00', '10:30:00' ) ) {
	ffc_ss_query_fail( 'A time-range block does not close a slot that overlaps it.' );
}

// Capacity: two confirmed at 09:00:00 on $day fill a slot of two; a cancelled
// one does not count.
$now = new \DateTimeImmutable( $day . ' 09:00:00', wp_timezone() ); // ffc_ss_plant() places rows relative to it.
foreach ( array( 'confirmed', 'confirmed', 'cancelled' ) as $status ) {
	ffc_ss_plant( '+0 seconds', $status );
}
$reader = new \FreeFormCertificate\Repositories\AppointmentRepository();
$open   = array(
	$reader->isSlotAvailable( $calendar_id, $day, '09:00:00', 2 ),
	$reader->isSlotAvailable( $calendar_id, $day, '09:00:00', 3 ),
	count( $reader->getAppointmentsByDate( $calendar_id, $day ) ),
);
$wpdb->delete( $table, array( 'calendar_id' => $calendar_id ), array( '%d' ) );

if ( array( false, true, 2 ) !== $open ) {
	ffc_ss_query_fail( 'The capacity queries answered ' . wp_json_encode( $open ) . ' for two confirmed and one cancelled at 09:00 (expected [false,true,2]).' );
}

echo "SLOT QUERIES HOLD: one block read per date, capacity counted per start.\n";
