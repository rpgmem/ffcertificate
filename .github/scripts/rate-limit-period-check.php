<?php
/**
 * The e-mail and CPF/RF period limits, against a real database (#2339).
 *
 * **WHY A REAL DATABASE.** `RateLimitRepository::get_submission_count()`
 * compared `submission_date` -- a unix instant since 6.6.0 -- with
 * `DATE_SUB(NOW(), …)`, a DATETIME. The comparison was always false, every
 * count read 0, and no day, week, month or year limit ever blocked anybody,
 * while the unit tests (against a `$wpdb` double that answered what the test
 * supplied) stayed green. Only a server can say what a predicate selects.
 *
 * **WHAT IT PLANTS.** Submissions on one throwaway form id, for one e-mail
 * and one CPF, made 1 hour, 3 days, 20 days, 200 days and 400 days ago. The
 * counts must be 1 for a day, 2 for a week, 3 for a month and 4 for a year,
 * and each limiter must refuse at exactly the period whose maximum the count
 * reaches. It then blocks the CPF temporarily and checks the block is read
 * back, which compares a UTC `blocked_until` with PHP's UTC clock.
 *
 * **WHY THIS JOB.** `fresh-install` has the only real MariaDB and the freshly
 * created schema. Everything planted is deleted before the script exits.
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

$wp_root = (string) ( $argv[1] ?? '' );

if ( '' === $wp_root || ! is_readable( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php rate-limit-period-check.php <wp-root>\n" );
	exit( 2 );
}

require_once $wp_root . '/wp-load.php';

use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Security\RateLimiter;
use FreeFormCertificate\Security\RateLimitRepository;
use FreeFormCertificate\Settings\RateLimitSettingsReader;

global $wpdb;

$ffc_rl_table   = $wpdb->prefix . 'ffc_submissions';
$ffc_rl_limits  = $wpdb->prefix . 'ffc_rate_limits';
$ffc_rl_form    = 987654322;
$ffc_rl_email   = 'period-check@example.test';
$ffc_rl_cpf     = '52998224725';
$ffc_rl_stored  = get_option( RateLimitSettingsReader::OPTION_KEY, null );

/**
 * Remove what the script planted and restore the settings.
 *
 * @return void
 */
function ffc_rl_cleanup(): void {
	global $wpdb, $ffc_rl_table, $ffc_rl_limits, $ffc_rl_form, $ffc_rl_stored;
	$wpdb->delete( $ffc_rl_table, array( 'form_id' => $ffc_rl_form ), array( '%d' ) );
	$wpdb->delete( $ffc_rl_limits, array( 'form_id' => $ffc_rl_form ), array( '%d' ) );
	if ( null === $ffc_rl_stored ) {
		delete_option( RateLimitSettingsReader::OPTION_KEY );
	} else {
		update_option( RateLimitSettingsReader::OPTION_KEY, $ffc_rl_stored );
	}
}

/**
 * Clean up, report a failure and stop.
 *
 * @param string $message Why.
 * @return never
 */
function ffc_rl_fail( string $message ) {
	ffc_rl_cleanup();
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

/**
 * Store limits and drop the checker's memo, so the next check reads them.
 *
 * @param array<string, mixed> $settings Settings to store.
 * @return void
 */
function ffc_rl_settings( array $settings ): void {
	update_option( RateLimitSettingsReader::OPTION_KEY, $settings );
	$memo = new \ReflectionProperty( \FreeFormCertificate\Security\RateLimitChecker::class, 'settings_cache' );
	$memo->setAccessible( true );
	$memo->setValue( null, null );
}

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ffc_rl_table ) ) !== $ffc_rl_table ) {
	ffc_rl_fail( "{$ffc_rl_table} does not exist, so the count cannot be measured." );
}

$ffc_rl_email_hash = SensitiveFieldRegistry::hash_identifier( 'email', $ffc_rl_email );
$ffc_rl_cpf_hash   = SensitiveFieldRegistry::hash_identifier( 'cpf', $ffc_rl_cpf );
if ( null === $ffc_rl_email_hash || null === $ffc_rl_cpf_hash ) {
	ffc_rl_fail( 'The identifiers could not be hashed, so nothing can match them.' );
}

foreach ( array( HOUR_IN_SECONDS, 3 * DAY_IN_SECONDS, 20 * DAY_IN_SECONDS, 200 * DAY_IN_SECONDS, 400 * DAY_IN_SECONDS ) as $ffc_rl_age ) {
	$ffc_rl_ok = $wpdb->insert(
		$ffc_rl_table,
		array(
			'form_id'         => $ffc_rl_form,
			'submission_date' => time() - $ffc_rl_age,
			'status'          => 'publish',
			'email_hash'      => $ffc_rl_email_hash,
			'cpf_hash'        => $ffc_rl_cpf_hash,
		),
		array( '%d', '%d', '%s', '%s', '%s' )
	);
	if ( false === $ffc_rl_ok ) {
		ffc_rl_fail( 'Could not plant a submission: ' . $wpdb->last_error );
	}
}

$ffc_rl_expected = array(
	'day'   => 1,
	'week'  => 2,
	'month' => 3,
	'year'  => 4,
);
foreach ( $ffc_rl_expected as $ffc_rl_period => $ffc_rl_count ) {
	$ffc_rl_email_count = RateLimitRepository::get_submission_count( 'email', $ffc_rl_email, $ffc_rl_period, $ffc_rl_form );
	$ffc_rl_cpf_count   = RateLimitRepository::get_submission_count( 'cpf', $ffc_rl_cpf, $ffc_rl_period, $ffc_rl_form );
	if ( $ffc_rl_email_count !== $ffc_rl_count || $ffc_rl_cpf_count !== $ffc_rl_count ) {
		ffc_rl_fail( "A {$ffc_rl_period} counted {$ffc_rl_email_count} by e-mail and {$ffc_rl_cpf_count} by CPF; {$ffc_rl_count} were planted inside it." );
	}
}

// Each period blocks at its own maximum, and only then.
$ffc_rl_email_cases = array(
	'email_day_limit'   => array( 'max_per_day' => 1, 'max_per_week' => 10, 'max_per_month' => 10 ),
	'email_week_limit'  => array( 'max_per_day' => 5, 'max_per_week' => 2, 'max_per_month' => 10 ),
	'email_month_limit' => array( 'max_per_day' => 5, 'max_per_week' => 5, 'max_per_month' => 3 ),
	''                  => array( 'max_per_day' => 5, 'max_per_week' => 5, 'max_per_month' => 5 ),
);
foreach ( $ffc_rl_email_cases as $ffc_rl_reason => $ffc_rl_limits_set ) {
	ffc_rl_settings( array( 'email' => $ffc_rl_limits_set + array( 'enabled' => true, 'check_database' => true ) ) );
	$ffc_rl_verdict = RateLimiter::check_email_limit( $ffc_rl_email, $ffc_rl_form );
	$ffc_rl_got     = $ffc_rl_verdict['allowed'] ? '' : (string) ( $ffc_rl_verdict['reason'] ?? '?' );
	if ( $ffc_rl_got !== $ffc_rl_reason ) {
		ffc_rl_fail( 'E-mail limits ' . wp_json_encode( $ffc_rl_limits_set ) . " answered '{$ffc_rl_got}', expected '{$ffc_rl_reason}'." );
	}
}

$ffc_rl_cpf_cases = array(
	'cpf_month_limit' => array( 'max_per_month' => 3, 'max_per_year' => 50 ),
	'cpf_year_limit'  => array( 'max_per_month' => 5, 'max_per_year' => 4 ),
	''                => array( 'max_per_month' => 5, 'max_per_year' => 5 ),
);
foreach ( $ffc_rl_cpf_cases as $ffc_rl_reason => $ffc_rl_limits_set ) {
	ffc_rl_settings( array( 'cpf' => $ffc_rl_limits_set + array( 'enabled' => true, 'check_database' => true, 'block_threshold' => 99 ) ) );
	$ffc_rl_verdict = RateLimiter::check_cpf_limit( $ffc_rl_cpf, $ffc_rl_form );
	$ffc_rl_got     = $ffc_rl_verdict['allowed'] ? '' : (string) ( $ffc_rl_verdict['reason'] ?? '?' );
	if ( $ffc_rl_got !== $ffc_rl_reason ) {
		ffc_rl_fail( 'CPF limits ' . wp_json_encode( $ffc_rl_limits_set ) . " answered '{$ffc_rl_got}', expected '{$ffc_rl_reason}'." );
	}
}

// A temporary block is read back on the same clock it was written in.
RateLimitRepository::block_temporarily( 'cpf', $ffc_rl_cpf, $ffc_rl_form, 1 );
if ( ! RateLimitRepository::is_temporarily_blocked( 'cpf', $ffc_rl_cpf, $ffc_rl_form ) ) {
	ffc_rl_fail( 'A CPF blocked for one hour does not read as blocked.' );
}

ffc_rl_cleanup();
fwrite( STDOUT, "OK: each period counted its own submissions, every limit refused at its maximum, and a temporary block read back.\n" );
exit( 0 );
