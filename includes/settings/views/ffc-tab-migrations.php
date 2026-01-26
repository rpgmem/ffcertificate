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

// Initialize Migration Manager
require_once FFC_PLUGIN_DIR . 'includes/migrations/class-ffc-migration-manager.php';
$migration_manager = new \FFC_Migration_Manager();
$migrations = $migration_manager->get_migrations();
?>
<div class="ffc-settings-wrap">

<div class="ffc-migrations-settings-wrap">
    
    <div class="card">
        <h2>⚙️ <?php esc_html_e( 'Database Migrations', 'ffc' ); ?></h2>
        
        <p class="description">
            <?php esc_html_e( 'Manage database structure migrations to improve performance and data organization. These migrations move data from JSON storage to dedicated database columns for faster queries and better reliability.', 'ffc' ); ?>
        </p>
        
        <div class="ffc-migration-warning">
            <p>
                <strong>ℹ️ <?php esc_html_e( 'Important:', 'ffc' ); ?></strong>
                <?php esc_html_e( 'Migrations are safe to run multiple times. Each migration processes up to 100 records at a time. Run again if needed until 100% complete.', 'ffc' ); ?>
            </p>
        </div>
    </div>

    <?php
    // Display migrations
    if ( empty( $migrations ) ) :
    ?>
        <div class="notice notice-info inline">
            <p><?php esc_html_e( 'No migrations available at this time.', 'ffc' ); ?></p>
        </div>
    <?php
    else :
        foreach ( $migrations as $key => $migration ) :
            // Check if migration is available
            if ( ! $migration_manager->is_migration_available( $key ) ) {
                continue;
            }
            
            // Get migration status
            $status = $migration_manager->get_migration_status( $key );
            
            if ( is_wp_error( $status ) ) {
                // ✅ v2.9.16: If there's an error, assume no data exists (empty database)
                $percent = 100;
                $is_complete = true;
                $pending = 0;
                $total = 0;
                $migrated = 0;
            } else {
                $percent = $status['percent'];
                $is_complete = $status['is_complete'];
                $pending = number_format( $status['pending'] );
                $total = number_format( $status['total'] );
                $migrated = number_format( $status['migrated'] );
            }
            
            // Generate migration URL
            $migrate_url = wp_nonce_url(
                add_query_arg( array(
                    'post_type' => 'ffc_form',
                    'page' => 'ffc-settings',
                    'tab' => 'migrations',
                    'ffc_run_migration' => $key
                ), admin_url( 'edit.php' ) ),
                'ffc_migration_' . $key
            );
            
            $status_class = $is_complete ? 'complete' : 'pending';
            $progress_color = $is_complete ? 'complete' : 'pending';
            $stat_pending_class = $is_complete ? 'success' : 'pending';
            $stat_progress_class = $is_complete ? 'success' : 'info';
            $label_class = $percent > 50 ? 'dark' : 'light';
    ?>
    
    <div class="postbox ffc-migration-card ffc-migration-<?php echo esc_attr( $status_class ); ?>">
        <div class="postbox-header">
            <h3 class="hndle">
                <span><?php echo esc_html( $migration['icon'] . ' ' . $migration['name'] ); ?></span>
                <?php if ( $is_complete ) : ?>
                    <span class="dashicons dashicons-yes-alt"></span>
                <?php endif; ?>
            </h3>
        </div>
        
        <div class="inside">
            <p class="description">
                <?php echo esc_html( $migration['description'] ); ?>
            </p>
            
            <!-- Migration Statistics -->
            <div class="ffc-migration-stats">
                <div>
                    <div class="ffc-migration-stat-label">
                        <?php esc_html_e( 'Total Records', 'ffc' ); ?>
                    </div>
                    <div class="ffc-migration-stat-value">
                        <?php echo esc_html( $total ); ?>
                    </div>
                </div>
                
                <div>
                    <div class="ffc-migration-stat-label">
                        <?php esc_html_e( 'Migrated', 'ffc' ); ?>
                    </div>
                    <div class="ffc-migration-stat-value success">
                        <?php echo esc_html( $migrated ); ?>
                    </div>
                </div>
                
                <div>
                    <div class="ffc-migration-stat-label">
                        <?php esc_html_e( 'Pending', 'ffc' ); ?>
                    </div>
                    <div class="ffc-migration-stat-value <?php echo esc_attr( $stat_pending_class ); ?>">
                        <?php echo esc_html( $pending ); ?>
                    </div>
                </div>
                
                <div>
                    <div class="ffc-migration-stat-label">
                        <?php esc_html_e( 'Progress', 'ffc' ); ?>
                    </div>
                    <div class="ffc-migration-stat-value <?php echo esc_attr( $stat_progress_class ); ?>">
                        <?php echo number_format( $percent, 1 ); ?>%
                    </div>
                </div>
            </div>
            
            <!-- Progress Bar -->
            <div class="ffc-migration-progress-bar">
                <div class="ffc-progress-bar-container">
                    <div class="ffc-progress-bar-fill <?php echo esc_attr( $progress_color ); ?>"></div>
                    <div class="ffc-progress-bar-label <?php echo esc_attr( $label_class ); ?>">
                        <?php echo number_format( $percent, 1 ); ?>% <?php esc_html_e( 'Complete', 'ffc' ); ?>
                    </div>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="ffc-migration-actions">
                <?php if ( $is_complete ) : ?>
                    <span class="button button-secondary" disabled>
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e( 'Migration Complete', 'ffc' ); ?>
                    </span>
                    
                    <p class="description">
                        ✓ <?php esc_html_e( 'All records have been successfully migrated.', 'ffc' ); ?>
                    </p>
                <?php else : ?>
                    <a href="<?php echo esc_url( $migrate_url ); ?>"
                       class="button button-primary"
                       onclick="return confirm('<?php echo esc_js( sprintf( __( 'Run %s migration?\n\nThis will automatically process ALL records in batches of 100 until complete.', 'ffc' ), $migration['name'] ) ); ?>')">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Run Migration', 'ffc' ); ?>
                    </a>

                    <p class="description">
                        <?php
                        printf(
                            esc_html__( 'Click once to process all records automatically. %s records remaining.', 'ffc' ),
                            '<strong>' . esc_html( $pending ) . '</strong>'
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
        <h3>❓ <?php esc_html_e( 'Need Help?', 'ffc' ); ?></h3>
        
        <p><strong><?php esc_html_e( 'What are migrations?', 'ffc' ); ?></strong></p>
        <p><?php esc_html_e( 'Migrations improve database performance by moving frequently queried data from JSON format to dedicated database columns. This makes searches and filtering much faster.', 'ffc' ); ?></p>
        
        <p><strong><?php esc_html_e( 'Is it safe?', 'ffc' ); ?></strong></p>
        <p><?php esc_html_e( 'Yes! Migrations only copy data to new columns - your original data remains intact. They are safe to run multiple times.', 'ffc' ); ?></p>
        
        <p><strong><?php esc_html_e( 'How many times should I run it?', 'ffc' ); ?></strong></p>
        <p><?php esc_html_e( 'Just click "Run Migration" once! The process will automatically continue processing batches of 100 records until complete. You can watch the progress bar update in real-time.', 'ffc' ); ?></p>
        
        <p><strong><?php esc_html_e( 'Can I undo a migration?', 'ffc' ); ?></strong></p>
        <p><?php esc_html_e( 'Migrations cannot be undone, but they don\'t delete any data. If you experience issues, your original data remains in the JSON column and can be accessed.', 'ffc' ); ?></p>
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
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> <?php esc_html_e( 'Processing...', 'ffc' ); ?>');

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
                            $card.find('.ffc-progress-bar-label').text(newPercent[1] + '% <?php esc_html_e( 'Complete', 'ffc' ); ?>');
                        }

                        // Update counters
                        var $newStats = $newCard.find('.ffc-migration-stats');
                        $card.find('.ffc-migration-stats').html($newStats.html());

                        totalProcessed += 100; // Approximate
                        $description.html('<?php esc_html_e( 'Processed ', 'ffc' ); ?><strong>' + totalProcessed + '</strong> <?php esc_html_e( 'records...', 'ffc' ); ?>');

                        // Check if complete (button is disabled or has "Complete" text)
                        var isComplete = $newCard.find('.button[disabled]').length > 0;

                        if (isComplete) {
                            // Migration complete!
                            $btn.html('<span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Migration Complete', 'ffc' ); ?>');
                            $description.html('✓ <?php esc_html_e( 'All records have been successfully migrated.', 'ffc' ); ?>');

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
                    $description.html('✗ <?php esc_html_e( 'Error occurred. Please try again.', 'ffc' ); ?>');
                }
            });
        }

        // Start first batch
        runBatch();

        return false;
    });
});
</script>