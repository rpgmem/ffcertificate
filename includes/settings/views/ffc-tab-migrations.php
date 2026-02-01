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

// Autoloader handles class loading
$ffc_migration_manager = new \FreeFormCertificate\Migrations\MigrationManager();
$ffc_migrations = $ffc_migration_manager->get_migrations();
?>
<div class="ffc-settings-wrap">

<div class="ffc-migrations-settings-wrap">
    
    <div class="card">
        <h2>⚙️ <?php esc_html_e( 'Database Migrations', 'wp-ffcertificate' ); ?></h2>
        
        <p class="description">
            <?php esc_html_e( 'Manage database structure migrations to improve performance and data organization. These migrations move data from JSON storage to dedicated database columns for faster queries and better reliability.', 'wp-ffcertificate' ); ?>
        </p>
        
        <div class="ffc-migration-warning">
            <p>
                <strong>ℹ️ <?php esc_html_e( 'Important:', 'wp-ffcertificate' ); ?></strong>
                <?php esc_html_e( 'Migrations are safe to run multiple times. Each migration processes up to 100 records at a time. Run again if needed until 100% complete.', 'wp-ffcertificate' ); ?>
            </p>
        </div>
    </div>

    <?php
    // Display migrations
    if ( empty( $ffc_migrations ) ) :
    ?>
        <div class="notice notice-info inline">
            <p><?php esc_html_e( 'No migrations available at this time.', 'wp-ffcertificate' ); ?></p>
        </div>
    <?php
    else :
        foreach ( $ffc_migrations as $ffc_key => $ffc_migration ) :
            // Check if migration is available
            if ( ! $ffc_migration_manager->is_migration_available( $ffc_key ) ) {
                continue;
            }
            
            // Get migration status
            $ffc_status = $ffc_migration_manager->get_migration_status( $ffc_key );
            
            if ( is_wp_error( $ffc_status ) ) {
                // ✅ v2.9.16: If there's an error, assume no data exists (empty database)
                $ffc_percent = 100;
                $ffc_is_complete = true;
                $ffc_pending = 0;
                $ffc_total = 0;
                $ffc_migrated = 0;
            } else {
                $ffc_percent = $ffc_status['percent'];
                $ffc_is_complete = $ffc_status['is_complete'];
                $ffc_pending = number_format( $ffc_status['pending'] );
                $ffc_total = number_format( $ffc_status['total'] );
                $ffc_migrated = number_format( $ffc_status['migrated'] );
            }
            
            // Generate migration URL
            $ffc_migrate_url = wp_nonce_url(
                add_query_arg( array(
                    'post_type' => 'ffc_form',
                    'page' => 'ffc-settings',
                    'tab' => 'migrations',
                    'ffc_run_migration' => $ffc_key
                ), admin_url( 'edit.php' ) ),
                'ffc_migration_' . $ffc_key
            );
            
            $ffc_status_class = $ffc_is_complete ? 'complete' : 'pending';
            $ffc_progress_color = $ffc_is_complete ? 'complete' : 'pending';
            $ffc_stat_pending_class = $ffc_is_complete ? 'success' : 'pending';
            $ffc_stat_progress_class = $ffc_is_complete ? 'success' : 'info';
            $ffc_label_class = $ffc_percent > 50 ? 'dark' : 'light';
    ?>
    
    <div class="postbox ffc-migration-card ffc-migration-<?php echo esc_attr( $ffc_status_class ); ?>">
        <div class="postbox-header">
            <h3 class="hndle">
                <span><?php echo esc_html( $ffc_migration['icon'] . ' ' . $ffc_migration['name'] ); ?></span>
                <?php if ( $ffc_is_complete ) : ?>
                    <span class="dashicons dashicons-yes-alt"></span>
                <?php endif; ?>
            </h3>
        </div>
        
        <div class="inside">
            <p class="description">
                <?php echo esc_html( $ffc_migration['description'] ); ?>
            </p>
            
            <!-- Migration Statistics -->
            <div class="ffc-migration-stats">
                <div>
                    <div class="ffc-migration-stat-label">
                        <?php esc_html_e( 'Total Records', 'wp-ffcertificate' ); ?>
                    </div>
                    <div class="ffc-migration-stat-value">
                        <?php echo esc_html( $ffc_total ); ?>
                    </div>
                </div>
                
                <div>
                    <div class="ffc-migration-stat-label">
                        <?php esc_html_e( 'Migrated', 'wp-ffcertificate' ); ?>
                    </div>
                    <div class="ffc-migration-stat-value success">
                        <?php echo esc_html( $ffc_migrated ); ?>
                    </div>
                </div>
                
                <div>
                    <div class="ffc-migration-stat-label">
                        <?php esc_html_e( 'Pending', 'wp-ffcertificate' ); ?>
                    </div>
                    <div class="ffc-migration-stat-value <?php echo esc_attr( $ffc_stat_pending_class ); ?>">
                        <?php echo esc_html( $ffc_pending ); ?>
                    </div>
                </div>
                
                <div>
                    <div class="ffc-migration-stat-label">
                        <?php esc_html_e( 'Progress', 'wp-ffcertificate' ); ?>
                    </div>
                    <div class="ffc-migration-stat-value <?php echo esc_attr( $ffc_stat_progress_class ); ?>">
                        <?php echo number_format( $ffc_percent, 1 ); ?>%
                    </div>
                </div>
            </div>
            
            <!-- Progress Bar -->
            <div class="ffc-migration-progress-bar">
                <div class="ffc-progress-bar-container">
                    <div class="ffc-progress-bar-fill <?php echo esc_attr( $ffc_progress_color ); ?>"></div>
                    <div class="ffc-progress-bar-label <?php echo esc_attr( $ffc_label_class ); ?>">
                        <?php echo number_format( $ffc_percent, 1 ); ?>% <?php esc_html_e( 'Complete', 'wp-ffcertificate' ); ?>
                    </div>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="ffc-migration-actions">
                <?php if ( $ffc_is_complete ) : ?>
                    <span class="button button-secondary" disabled>
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e( 'Migration Complete', 'wp-ffcertificate' ); ?>
                    </span>
                    
                    <p class="description">
                        ✓ <?php esc_html_e( 'All records have been successfully migrated.', 'wp-ffcertificate' ); ?>
                    </p>
                <?php else : ?>
                    <a href="<?php echo esc_url( $ffc_migrate_url ); ?>"
                       class="button button-primary"
                       onclick="return confirm('<?php echo esc_js( sprintf(
                           /* translators: %s: migration name */
                           __( 'Run %s migration?\n\nThis will automatically process ALL records in batches of 100 until complete.', 'wp-ffcertificate' ), $ffc_migration['name'] ) ); ?>')">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Run Migration', 'wp-ffcertificate' ); ?>
                    </a>

                    <p class="description">
                        <?php
                        /* translators: %s: number of pending records */
                        printf(
                            esc_html__( 'Click once to process all records automatically. %s records remaining.', 'wp-ffcertificate' ),
                            '<strong>' . esc_html( $ffc_pending ) . '</strong>'
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
        <h3>❓ <?php esc_html_e( 'Need Help?', 'wp-ffcertificate' ); ?></h3>
        
        <p><strong><?php esc_html_e( 'What are migrations?', 'wp-ffcertificate' ); ?></strong></p>
        <p><?php esc_html_e( 'Migrations improve database performance by moving frequently queried data from JSON format to dedicated database columns. This makes searches and filtering much faster.', 'wp-ffcertificate' ); ?></p>
        
        <p><strong><?php esc_html_e( 'Is it safe?', 'wp-ffcertificate' ); ?></strong></p>
        <p><?php esc_html_e( 'Yes! Migrations only copy data to new columns - your original data remains intact. They are safe to run multiple times.', 'wp-ffcertificate' ); ?></p>
        
        <p><strong><?php esc_html_e( 'How many times should I run it?', 'wp-ffcertificate' ); ?></strong></p>
        <p><?php esc_html_e( 'Just click "Run Migration" once! The process will automatically continue processing batches of 100 records until complete. You can watch the progress bar update in real-time.', 'wp-ffcertificate' ); ?></p>
        
        <p><strong><?php esc_html_e( 'Can I undo a migration?', 'wp-ffcertificate' ); ?></strong></p>
        <p><?php esc_html_e( 'Migrations cannot be undone, but they don\'t delete any data. If you experience issues, your original data remains in the JSON column and can be accessed.', 'wp-ffcertificate' ); ?></p>
    </div>

</div>
</div><!-- .ffc-settings-wrap -->

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Intercept migration button clicks to run automatically via AJAX
    $('.ffc-migration-actions a.button-primary').on('click', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var migrationUrl = $btn.attr('href');
        var $card = $btn.closest('.ffc-migration-card');
        var $description = $btn.next('.description');
        var originalBtnHtml = $btn.html();
        var totalProcessed = 0;

        if (!confirm($btn.attr('onclick').match(/confirm\('([^']+)'/)[1])) {
            return false;
        }

        // Disable button and show processing
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> <?php esc_html_e( 'Processing...', 'wp-ffcertificate' ); ?>');

        function runBatch() {
            $.ajax({
                url: migrationUrl,
                type: 'GET',
                dataType: 'html',
                success: function(response) {
                    // Parse the response to check if has_more
                    // We'll extract the migration card and check its state
                    var $newCard = $(response).find('.ffc-migration-card').filter(function() {
                        return $(this).find('h3').text() === $card.find('h3').text();
                    });

                    if ($newCard.length) {
                        // Update progress bar
                        var $newProgress = $newCard.find('.ffc-progress-bar-fill');
                        var newPercent = $newProgress.attr('style').match(/width:\s*(\d+\.?\d*)%/);
                        if (newPercent) {
                            $card.find('.ffc-progress-bar-fill').attr('style', 'width: ' + newPercent[1] + '%');
                            $card.find('.ffc-progress-bar-label').text(newPercent[1] + '% <?php esc_html_e( 'Complete', 'wp-ffcertificate' ); ?>');
                        }

                        // Update counters
                        var $newStats = $newCard.find('.ffc-migration-stats');
                        $card.find('.ffc-migration-stats').html($newStats.html());

                        totalProcessed += 100; // Approximate
                        $description.html('<?php esc_html_e( 'Processed ', 'wp-ffcertificate' ); ?><strong>' + totalProcessed + '</strong> <?php esc_html_e( 'records...', 'wp-ffcertificate' ); ?>');

                        // Check if complete (button is disabled or has "Complete" text)
                        var isComplete = $newCard.find('.button[disabled]').length > 0;

                        if (isComplete) {
                            // Migration complete!
                            $btn.html('<span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Migration Complete', 'wp-ffcertificate' ); ?>');
                            $description.html('✓ <?php esc_html_e( 'All records have been successfully migrated.', 'wp-ffcertificate' ); ?>');

                            // Reload page after 2 seconds to show final state
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            // Continue processing next batch
                            setTimeout(runBatch, 500); // Small delay between batches
                        }
                    } else {
                        // Error parsing response, reload page
                        location.reload();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Migration error:', error);
                    $btn.prop('disabled', false).html(originalBtnHtml);
                    $description.html('✗ <?php esc_html_e( 'Error occurred. Please try again.', 'wp-ffcertificate' ); ?>');
                }
            });
        }

        // Start first batch
        runBatch();

        return false;
    });
});
</script>