<?php
/**
 * Settings Tab: Data Migrations
 * 
 * Template for the Data Migrations settings tab
 * 
 * @since 2.9.16
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

// Autoloader handles class loading
$ffcertificate_migration_manager = new \FreeFormCertificate\Migrations\MigrationManager();
$ffcertificate_migrations = $ffcertificate_migration_manager->get_migrations();
?>
<div class="ffc-settings-wrap">

<div class="ffc-migrations-settings-wrap">
    
    <div class="card">
        <h2 class="ffc-icon-settings"><?php esc_html_e( 'Database Migrations', 'ffcertificate' ); ?></h2>
        
        <p class="description">
            <?php esc_html_e( 'Manage database structure migrations to improve performance and data organization. These migrations move data from JSON storage to dedicated database columns for faster queries and better reliability.', 'ffcertificate' ); ?>
        </p>
        
        <div class="ffc-migration-warning">
            <p>
                <strong class="ffc-icon-info"><?php esc_html_e( 'Important:', 'ffcertificate' ); ?></strong>
                <?php esc_html_e( 'Migrations are safe to run multiple times. Each migration processes up to 100 records at a time. Run again if needed until 100% complete.', 'ffcertificate' ); ?>
            </p>
        </div>
    </div>

    <?php
    // Display migrations
    if ( empty( $ffcertificate_migrations ) ) :
    ?>
        <div class="notice notice-info inline">
            <p><?php esc_html_e( 'No migrations available at this time.', 'ffcertificate' ); ?></p>
        </div>
    <?php
    else :
        foreach ( $ffcertificate_migrations as $ffcertificate_key => $ffcertificate_migration ) :
            // Check if migration is available
            if ( ! $ffcertificate_migration_manager->is_migration_available( $ffcertificate_key ) ) {
                continue;
            }
            
            // Get migration status
            $ffcertificate_status = $ffcertificate_migration_manager->get_migration_status( $ffcertificate_key );
            
            if ( is_wp_error( $ffcertificate_status ) ) {
                // If there's an error, assume no data exists (empty database)
                $ffcertificate_percent = 100;
                $ffcertificate_is_complete = true;
                $ffcertificate_pending = 0;
                $ffcertificate_total = 0;
                $ffcertificate_migrated = 0;
            } else {
                $ffcertificate_percent = $ffcertificate_status['percent'];
                $ffcertificate_is_complete = $ffcertificate_status['is_complete'];
                $ffcertificate_pending = number_format( $ffcertificate_status['pending'] );
                $ffcertificate_total = number_format( $ffcertificate_status['total'] );
                $ffcertificate_migrated = number_format( $ffcertificate_status['migrated'] );
            }
            
            // Generate migration URL
            $ffcertificate_migrate_url = wp_nonce_url(
                add_query_arg( array(
                    'post_type' => 'ffc_form',
                    'page' => 'ffc-settings',
                    'tab' => 'migrations',
                    'ffc_run_migration' => $ffcertificate_key
                ), admin_url( 'edit.php' ) ),
                'ffc_migration_' . $ffcertificate_key
            );
            
            $ffcertificate_status_class = $ffcertificate_is_complete ? 'complete' : 'pending';
            $ffcertificate_progress_color = $ffcertificate_is_complete ? 'complete' : 'pending';
            $ffcertificate_stat_pending_class = $ffcertificate_is_complete ? 'success' : 'pending';
            $ffcertificate_stat_progress_class = $ffcertificate_is_complete ? 'success' : 'info';
            $ffcertificate_label_class = $ffcertificate_percent > 50 ? 'dark' : 'light';
    ?>
    
    <div class="postbox ffc-migration-card ffc-migration-<?php echo esc_attr( $ffcertificate_status_class ); ?>">
        <div class="postbox-header">
            <h3 class="hndle">
                <span class="<?php echo esc_attr( $ffcertificate_migration['icon'] ); ?>"><?php echo esc_html( $ffcertificate_migration['name'] ); ?></span>
                <?php if ( $ffcertificate_is_complete ) : ?>
                    <span class="dashicons dashicons-yes-alt"></span>
                <?php endif; ?>
            </h3>
        </div>
        
        <div class="inside">
            <p class="description">
                <?php echo esc_html( $ffcertificate_migration['description'] ); ?>
            </p>
            
            <!-- Migration Statistics -->
            <div class="ffc-migration-stats">
                <div>
                    <div class="ffc-migration-stat-label">
                        <?php esc_html_e( 'Total Records', 'ffcertificate' ); ?>
                    </div>
                    <div class="ffc-migration-stat-value">
                        <?php echo esc_html( $ffcertificate_total ); ?>
                    </div>
                </div>
                
                <div>
                    <div class="ffc-migration-stat-label">
                        <?php esc_html_e( 'Migrated', 'ffcertificate' ); ?>
                    </div>
                    <div class="ffc-migration-stat-value success">
                        <?php echo esc_html( $ffcertificate_migrated ); ?>
                    </div>
                </div>
                
                <div>
                    <div class="ffc-migration-stat-label">
                        <?php esc_html_e( 'Pending', 'ffcertificate' ); ?>
                    </div>
                    <div class="ffc-migration-stat-value <?php echo esc_attr( $ffcertificate_stat_pending_class ); ?>">
                        <?php echo esc_html( $ffcertificate_pending ); ?>
                    </div>
                </div>
                
                <div>
                    <div class="ffc-migration-stat-label">
                        <?php esc_html_e( 'Progress', 'ffcertificate' ); ?>
                    </div>
                    <div class="ffc-migration-stat-value <?php echo esc_attr( $ffcertificate_stat_progress_class ); ?>">
                        <?php echo esc_html( number_format( $ffcertificate_percent, 1 ) ); ?>%
                    </div>
                </div>
            </div>
            
            <!-- Progress Bar -->
            <div class="ffc-migration-progress-bar">
                <div class="ffc-progress-bar-container" role="progressbar" aria-valuenow="<?php echo esc_attr( number_format( $ffcertificate_percent, 1 ) ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Migration progress', 'ffcertificate' ); ?>">
                    <div class="ffc-progress-bar-fill <?php echo esc_attr( $ffcertificate_progress_color ); ?>"></div>
                    <div class="ffc-progress-bar-label <?php echo esc_attr( $ffcertificate_label_class ); ?>">
                        <?php echo esc_html( number_format( $ffcertificate_percent, 1 ) ); ?>% <?php esc_html_e( 'Complete', 'ffcertificate' ); ?>
                    </div>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="ffc-migration-actions">
                <?php if ( $ffcertificate_is_complete ) : ?>
                    <span class="button button-secondary" disabled>
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e( 'Migration Complete', 'ffcertificate' ); ?>
                    </span>
                    
                    <p class="description">
                        <span class="ffc-icon-checkmark"></span><?php esc_html_e( 'All records have been successfully migrated.', 'ffcertificate' ); ?>
                    </p>
                <?php else : ?>
                    <a href="<?php echo esc_url( $ffcertificate_migrate_url ); ?>"
                       class="button button-primary"
                       data-confirm="<?php echo esc_attr( sprintf(
                           /* translators: %s: migration name */
                           __( 'Run %s migration?\n\nThis will automatically process ALL records in batches of 100 until complete.', 'ffcertificate' ), $ffcertificate_migration['name'] ) ); ?>">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Run Migration', 'ffcertificate' ); ?>
                    </a>

                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: number of pending records */
                            esc_html__( 'Click once to process all records automatically. %s records remaining.', 'ffcertificate' ),
                            '<strong>' . esc_html( $ffcertificate_pending ) . '</strong>'
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php
        endforeach;
    endif;
    ?>
    
    <!-- Help Section -->
    <div class="card ffc-migration-help">
        <h3 class="ffc-icon-help"><?php esc_html_e( 'Need Help?', 'ffcertificate' ); ?></h3>
        
        <p><strong><?php esc_html_e( 'What are migrations?', 'ffcertificate' ); ?></strong></p>
        <p><?php esc_html_e( 'Migrations improve database performance by moving frequently queried data from JSON format to dedicated database columns. This makes searches and filtering much faster.', 'ffcertificate' ); ?></p>
        
        <p><strong><?php esc_html_e( 'Is it safe?', 'ffcertificate' ); ?></strong></p>
        <p><?php esc_html_e( 'Yes! Migrations only copy data to new columns - your original data remains intact. They are safe to run multiple times.', 'ffcertificate' ); ?></p>
        
        <p><strong><?php esc_html_e( 'How many times should I run it?', 'ffcertificate' ); ?></strong></p>
        <p><?php esc_html_e( 'Just click "Run Migration" once! The process will automatically continue processing batches of 100 records until complete. You can watch the progress bar update in real-time.', 'ffcertificate' ); ?></p>
        
        <p><strong><?php esc_html_e( 'Can I undo a migration?', 'ffcertificate' ); ?></strong></p>
        <p><?php esc_html_e( 'Migrations cannot be undone, but they don\'t delete any data. If you experience issues, your original data remains in the JSON column and can be accessed.', 'ffcertificate' ); ?></p>
    </div>

</div>
</div><!-- .ffc-settings-wrap -->
<!-- Migration batch scripts in ffc-admin-migrations.js -->