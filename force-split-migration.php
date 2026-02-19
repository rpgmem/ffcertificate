<?php
/**
 * One-time script to force re-run the split CPF/RF migration.
 *
 * This triggers the drop_legacy_columns() step which removes
 * plaintext columns that have been replaced by encrypted counterparts.
 *
 * Usage:
 *   wp eval-file wp-content/plugins/ffcertificate/force-split-migration.php
 *
 * Or via browser (logged in as admin):
 *   https://yoursite.com/wp-content/plugins/ffcertificate/force-split-migration.php
 *
 * DELETE THIS FILE AFTER RUNNING.
 */

// ── Bootstrap WordPress ──────────────────────────────────────────
$wp_load_paths = array(
    __DIR__ . '/../../../wp-load.php',        // standard: wp-content/plugins/ffcertificate/
    __DIR__ . '/../../../../wp-load.php',      // alternate depth
);

$loaded = false;
foreach ( $wp_load_paths as $path ) {
    if ( file_exists( $path ) ) {
        require_once $path;
        $loaded = true;
        break;
    }
}

// If running via WP-CLI (wp eval-file), WordPress is already loaded
if ( ! $loaded && ! defined( 'ABSPATH' ) ) {
    echo "ERROR: Could not locate wp-load.php\n";
    echo "Run this via WP-CLI instead:\n";
    echo "  wp eval-file wp-content/plugins/ffcertificate/force-split-migration.php\n";
    exit( 1 );
}

// ── Security check (browser only) ───────────────────────────────
$is_cli = ( php_sapi_name() === 'cli' || defined( 'WP_CLI' ) );

if ( ! $is_cli ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Acesso negado. Faça login como administrador.' );
    }
    header( 'Content-Type: text/plain; charset=utf-8' );
}

// ── Helper: output ──────────────────────────────────────────────
function ffc_out( string $msg ): void {
    echo $msg . "\n";
    if ( php_sapi_name() !== 'cli' && ! defined( 'WP_CLI' ) ) {
        ob_flush();
        flush();
    }
}

// ── Run the migration ───────────────────────────────────────────
ffc_out( '=== FFC Split Migration — Force Run ===' );
ffc_out( '' );

// 1. Show current column state BEFORE
global $wpdb;
$sub_table  = $wpdb->prefix . 'ffc_submissions';
$appt_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';

$columns_to_check = array(
    // Submissions
    $sub_table => array( 'cpf_rf', 'cpf_rf_encrypted', 'cpf_rf_hash', 'user_ip', 'email', 'cpf', 'rf', 'consent_ip' ),
    // Appointments
    $appt_table => array( 'cpf_rf', 'cpf_rf_encrypted', 'cpf_rf_hash', 'user_ip', 'email', 'cpf', 'rf', 'consent_ip', 'phone', 'custom_data' ),
);

ffc_out( '[BEFORE] Plaintext columns still present:' );
foreach ( $columns_to_check as $table => $cols ) {
    $short = str_replace( $wpdb->prefix, '', $table );
    $existing = array();
    foreach ( $cols as $col ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME, $table, $col
        ) );
        if ( (int) $found > 0 ) {
            $existing[] = $col;
        }
    }
    if ( empty( $existing ) ) {
        ffc_out( "  {$short}: (none — already clean)" );
    } else {
        ffc_out( "  {$short}: " . implode( ', ', $existing ) );
    }
}
ffc_out( '' );

// 2. Execute migration
ffc_out( 'Running migration...' );

try {
    $strategy = new \FreeFormCertificate\Migrations\Strategies\CpfRfSplitMigrationStrategy();

    $result = $strategy->execute( 'split_cpf_rf', array( 'batch_size' => 200 ), 0 );

    ffc_out( '' );
    ffc_out( 'Result:' );
    ffc_out( '  success:   ' . ( $result['success'] ? 'YES' : 'NO' ) );
    ffc_out( '  processed: ' . $result['processed'] );
    ffc_out( '  has_more:  ' . ( $result['has_more'] ? 'YES' : 'NO' ) );
    ffc_out( '  message:   ' . $result['message'] );

    if ( ! empty( $result['errors'] ) ) {
        ffc_out( '' );
        ffc_out( 'Errors:' );
        foreach ( $result['errors'] as $err ) {
            ffc_out( '  - ' . $err );
        }
    }
} catch ( \Throwable $e ) {
    ffc_out( 'EXCEPTION: ' . $e->getMessage() );
    ffc_out( $e->getTraceAsString() );
}

// 3. Show column state AFTER
ffc_out( '' );
ffc_out( '[AFTER] Plaintext columns still present:' );
foreach ( $columns_to_check as $table => $cols ) {
    $short = str_replace( $wpdb->prefix, '', $table );
    $existing = array();
    foreach ( $cols as $col ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME, $table, $col
        ) );
        if ( (int) $found > 0 ) {
            $existing[] = $col;
        }
    }
    if ( empty( $existing ) ) {
        ffc_out( "  {$short}: (none — all dropped!)" );
    } else {
        ffc_out( "  {$short}: " . implode( ', ', $existing ) );
    }
}

ffc_out( '' );
ffc_out( 'Done. DELETE THIS FILE NOW.' );
