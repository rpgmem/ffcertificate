/**
 * FFC Admin Migrations
 *
 * Batch migration processing with progress bar updates.
 *
 * @since 4.6.0
 * @package FreeFormCertificate\Settings
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        var strings = typeof ffcMigrations !== 'undefined' ? ffcMigrations.strings : {};

        // Intercept migration button clicks to run automatically via AJAX
        $('.ffc-migration-actions a.button-primary').on('click', function(e) {
            e.preventDefault();

            var $btn = $(this);
            var migrationUrl = $btn.attr('href');
            var $card = $btn.closest('.ffc-migration-card');
            var $description = $btn.next('.description');
            var originalBtnHtml = $btn.html();
            var totalProcessed = 0;

            var confirmMsg = $btn.data('confirm') || strings.confirmMigration || 'Are you sure you want to run this migration?';
            if (!confirm(confirmMsg)) {
                return false;
            }

            // Disable button and show processing
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> ' + (strings.processing || 'Processing...'));

            function runBatch() {
                $.ajax({
                    url: migrationUrl,
                    type: 'GET',
                    dataType: 'html',
                    success: function(response) {
                        var $newCard = $(response).find('.ffc-migration-card').filter(function() {
                            return $(this).find('h3').text() === $card.find('h3').text();
                        });

                        if ($newCard.length) {
                            // Update progress bar — read percentage from aria-valuenow
                            var newPercent = $newCard.find('.ffc-progress-bar-container').attr('aria-valuenow');
                            if (newPercent) {
                                $card.find('.ffc-progress-bar-fill').css('width', newPercent + '%');
                                $card.find('.ffc-progress-bar-container').attr('aria-valuenow', newPercent);
                                $card.find('.ffc-progress-bar-label').text(parseFloat(newPercent).toFixed(1) + '% ' + (strings.complete || 'Complete'));
                            }

                            // Update counters
                            var $newStats = $newCard.find('.ffc-migration-stats');
                            $card.find('.ffc-migration-stats').html($newStats.html());

                            totalProcessed += 100;
                            $description.html((strings.processed || 'Processed ') + '<strong>' + totalProcessed + '</strong> ' + (strings.records || 'records...'));

                            // Check if complete
                            var isComplete = $newCard.find('.button[disabled]').length > 0;

                            if (isComplete) {
                                $btn.html('<span class="dashicons dashicons-yes-alt"></span> ' + (strings.migrationComplete || 'Migration Complete'));
                                $description.html('✓ ' + (strings.allRecordsMigrated || 'All records have been successfully migrated.'));

                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                setTimeout(runBatch, 500);
                            }
                        } else {
                            location.reload();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Migration error:', error);
                        $btn.prop('disabled', false).html(originalBtnHtml);
                        $description.html('✗ ' + (strings.errorOccurred || 'Error occurred. Please try again.'));
                    }
                });
            }

            runBatch();
            return false;
        });
    });

})(jQuery);
