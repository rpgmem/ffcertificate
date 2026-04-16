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

// Autoloader handles class loading — wrapped in try-catch to prevent 500 errors.
$ffcertificate_migration_manager = null;
$ffcertificate_migrations        = array();
$ffcertificate_init_error        = '';

try {
	$ffcertificate_migration_manager = new \FreeFormCertificate\Migrations\MigrationManager();
	$ffcertificate_migrations        = $ffcertificate_migration_manager->get_migrations();
} catch ( \Throwable $e ) {
	$ffcertificate_init_error = $e->getMessage();
	if ( class_exists( '\\FreeFormCertificate\\Core\\Utils' ) ) {
		\FreeFormCertificate\Core\Utils::debug_log(
			'Migration tab initialization failed',
			array(
				'error' => $e->getMessage(),
				'file'  => $e->getFile(),
				'line'  => $e->getLine(),
			)
		);
	}
}
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
	// Show initialization error if MigrationManager failed
	if ( ! empty( $ffcertificate_init_error ) ) :
		?>
		<div class="notice notice-error inline">
			<p>
				<strong><?php esc_html_e( 'Migration system error:', 'ffcertificate' ); ?></strong>
				<?php echo esc_html( $ffcertificate_init_error ); ?>
			</p>
			<p><?php esc_html_e( 'Try deactivating and reactivating the plugin, or clearing any server-side cache (OPcache).', 'ffcertificate' ); ?></p>
		</div>
		<?php
	elseif ( empty( $ffcertificate_migrations ) ) :
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

			// Get migration status — wrapped in try-catch to prevent DB errors from crashing the page.
			try {
				$ffcertificate_status = $ffcertificate_migration_manager->get_migration_status( $ffcertificate_key );
			} catch ( \Throwable $e ) {
				$ffcertificate_status = new WP_Error( 'status_error', $e->getMessage() );
			}

			if ( is_wp_error( $ffcertificate_status ) ) {
				// Status calculation failed — show error state, keep migration available
				$ffcertificate_percent      = 0;
				$ffcertificate_is_complete  = false;
				$ffcertificate_pending      = '?';
				$ffcertificate_total        = '?';
				$ffcertificate_migrated     = '?';
				$ffcertificate_status_error = $ffcertificate_status->get_error_message();
			} else {
				$ffcertificate_status_error = '';
				$ffcertificate_percent      = $ffcertificate_status['percent'];
				$ffcertificate_is_complete  = $ffcertificate_status['is_complete'];
				$ffcertificate_pending      = number_format( $ffcertificate_status['pending'] );
				$ffcertificate_total        = number_format( $ffcertificate_status['total'] );
				$ffcertificate_migrated     = number_format( $ffcertificate_status['migrated'] );
			}

			// Generate migration URL
			$ffcertificate_migrate_url = wp_nonce_url(
				add_query_arg(
					array(
						'post_type'         => 'ffc_form',
						'page'              => 'ffc-settings',
						'tab'               => 'migrations',
						'ffc_run_migration' => $ffcertificate_key,
					),
					admin_url( 'edit.php' )
				),
				'ffc_migration_' . $ffcertificate_key
			);

			$ffcertificate_status_class        = $ffcertificate_is_complete ? 'complete' : 'pending';
			$ffcertificate_progress_color      = $ffcertificate_is_complete ? 'complete' : 'pending';
			$ffcertificate_stat_pending_class  = $ffcertificate_is_complete ? 'success' : 'pending';
			$ffcertificate_stat_progress_class = $ffcertificate_is_complete ? 'success' : 'info';
			$ffcertificate_label_class         = $ffcertificate_percent > 50 ? 'dark' : 'light';
			?>
	
	<div class="postbox ffc-migration-card ffc-migration-<?php echo esc_attr( $ffcertificate_status_class ); ?>">
		<div class="postbox-header">
			<h3 class="hndle">
				<span class="<?php echo esc_attr( $ffcertificate_migration['icon'] ); ?>"><?php echo esc_html( $ffcertificate_migration['name'] ); ?></span>
				<?php if ( $ffcertificate_is_complete ) : ?>
					<span class="ffc-icon-checkmark"></span>
				<?php endif; ?>
			</h3>
		</div>
		
		<div class="inside">
			<p class="description">
				<?php echo esc_html( $ffcertificate_migration['description'] ); ?>
			</p>

			<?php if ( ! empty( $ffcertificate_status_error ) ) : ?>
				<div class="notice notice-warning inline" style="margin: 10px 0;">
					<p><strong><?php esc_html_e( 'Status check error:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $ffcertificate_status_error ); ?></p>
				</div>
			<?php endif; ?>

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
					<div class="ffc-progress-bar-fill <?php echo esc_attr( $ffcertificate_progress_color ); ?>" style="width: <?php echo esc_attr( number_format( $ffcertificate_percent, 1 ) ); ?>%"></div>
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
						data-confirm="
						<?php
						echo esc_attr(
							sprintf(
							/* translators: %s: migration name */
								__( 'Run %s migration?\n\nThis will automatically process ALL records in batches of 100 until complete.', 'ffcertificate' ),
								$ffcertificate_migration['name']
							)
						);
						?>
							">
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

	<?php
	// ──────────────────────────────────────────────────────────────
	// Obsolete Shortcode Cleanup (v5.1.0)
	// ──────────────────────────────────────────────────────────────
	$ffcertificate_settings     = get_option( 'ffc_settings', array() );
	$ffcertificate_cleanup_days = is_array( $ffcertificate_settings ) && isset( $ffcertificate_settings['obsolete_shortcode_days'] )
		? max( 1, (int) $ffcertificate_settings['obsolete_shortcode_days'] )
		: 90;

	$ffcertificate_user_id        = get_current_user_id();
	$ffcertificate_cleanup_report = get_transient( 'ffc_obsolete_cleanup_report_' . $ffcertificate_user_id );
	$ffcertificate_preview_ok     = (bool) get_transient( 'ffc_obsolete_cleanup_preview_ok_' . $ffcertificate_user_id );

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query-arg display.
	$ffcertificate_cleanup_msg = isset( $_GET['obsolete_cleanup_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['obsolete_cleanup_msg'] ) ) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query-arg display.
	$ffcertificate_cleanup_err = isset( $_GET['obsolete_cleanup_error'] ) ? sanitize_text_field( wp_unslash( $_GET['obsolete_cleanup_error'] ) ) : '';

	$ffcertificate_base_url = add_query_arg(
		array(
			'post_type' => 'ffc_form',
			'page'      => 'ffc-settings',
			'tab'       => 'migrations',
		),
		admin_url( 'edit.php' )
	);

	$ffcertificate_preview_url   = wp_nonce_url(
		add_query_arg( 'ffc_obsolete_cleanup', 'preview', $ffcertificate_base_url ),
		'ffc_obsolete_cleanup_preview'
	);
	$ffcertificate_apply_url     = wp_nonce_url(
		add_query_arg( 'ffc_obsolete_cleanup', 'apply', $ffcertificate_base_url ),
		'ffc_obsolete_cleanup_apply'
	);
	$ffcertificate_save_days_url = wp_nonce_url(
		add_query_arg( 'ffc_obsolete_cleanup', 'save_days', $ffcertificate_base_url ),
		'ffc_obsolete_cleanup_save_days'
	);
	?>

	<div class="postbox ffc-migration-card ffc-obsolete-cleanup-card">
		<div class="postbox-header">
			<h3 class="hndle">
				<span class="ffc-icon-delete"><?php esc_html_e( 'Obsolete Shortcode Cleanup', 'ffcertificate' ); ?></span>
			</h3>
		</div>

		<div class="inside">
			<p class="description">
				<?php esc_html_e( 'Scan published posts, pages and reusable blocks for embedded [ffc_form] shortcodes that point to forms whose end date is more than N days in the past, and remove those obsolete shortcodes from the content.', 'ffcertificate' ); ?>
			</p>

			<div class="ffc-migration-warning">
				<p>
					<strong class="ffc-icon-info"><?php esc_html_e( 'Safe by design:', 'ffcertificate' ); ?></strong>
					<?php esc_html_e( 'WordPress automatically creates a revision for every modified post so administrators can roll back manually. Only shortcodes pointing at expired forms are removed — the rest of the content is left untouched.', 'ffcertificate' ); ?>
				</p>
			</div>

			<?php if ( $ffcertificate_cleanup_msg ) : ?>
				<div class="notice notice-success inline" style="margin: 10px 0;">
					<p><?php echo esc_html( $ffcertificate_cleanup_msg ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( $ffcertificate_cleanup_err ) : ?>
				<div class="notice notice-error inline" style="margin: 10px 0;">
					<p><?php echo esc_html( $ffcertificate_cleanup_err ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Grace-window form -->
			<form method="post" action="<?php echo esc_url( $ffcertificate_save_days_url ); ?>" style="margin: 12px 0;">
				<label for="ffc-obsolete-days" style="margin-right: 8px;">
					<?php esc_html_e( 'Remove shortcodes for forms ended more than', 'ffcertificate' ); ?>
				</label>
				<input
					type="number"
					id="ffc-obsolete-days"
					name="obsolete_shortcode_days"
					min="1"
					max="3650"
					step="1"
					value="<?php echo esc_attr( (string) $ffcertificate_cleanup_days ); ?>"
					style="width: 90px;">
				<span><?php esc_html_e( 'days ago', 'ffcertificate' ); ?></span>
				<button type="submit" class="button button-secondary">
					<?php esc_html_e( 'Save', 'ffcertificate' ); ?>
				</button>
			</form>

			<!-- Action buttons -->
			<div class="ffc-migration-actions">
				<a href="<?php echo esc_url( $ffcertificate_preview_url ); ?>" class="button button-secondary">
					<span class="dashicons dashicons-visibility"></span>
					<?php esc_html_e( 'Preview affected posts', 'ffcertificate' ); ?>
				</a>

				<?php if ( $ffcertificate_preview_ok ) : ?>
					<a href="<?php echo esc_url( $ffcertificate_apply_url ); ?>"
						class="button button-primary"
						data-confirm="<?php esc_attr_e( 'This will modify the post_content of every affected post. Revisions will be created automatically. Continue?', 'ffcertificate' ); ?>">
						<span class="dashicons dashicons-trash"></span>
						<?php esc_html_e( 'Remove shortcodes now', 'ffcertificate' ); ?>
					</a>
				<?php else : ?>
					<span class="button button-primary" disabled aria-disabled="true" title="<?php esc_attr_e( 'Run a preview first', 'ffcertificate' ); ?>">
						<span class="dashicons dashicons-trash"></span>
						<?php esc_html_e( 'Remove shortcodes now', 'ffcertificate' ); ?>
					</span>
				<?php endif; ?>

				<p class="description">
					<?php esc_html_e( 'Run a preview first; the removal button unlocks for 5 minutes after a successful preview.', 'ffcertificate' ); ?>
				</p>
			</div>

			<?php
			if ( is_array( $ffcertificate_cleanup_report ) ) :
				$ffcertificate_report_is_dry    = ! empty( $ffcertificate_cleanup_report['dry_run'] );
				$ffcertificate_report_expired   = isset( $ffcertificate_cleanup_report['expired_forms'] ) ? (int) $ffcertificate_cleanup_report['expired_forms'] : 0;
				$ffcertificate_report_scanned   = isset( $ffcertificate_cleanup_report['posts_scanned'] ) ? (int) $ffcertificate_cleanup_report['posts_scanned'] : 0;
				$ffcertificate_report_affected  = isset( $ffcertificate_cleanup_report['posts_affected'] ) ? (int) $ffcertificate_cleanup_report['posts_affected'] : 0;
				$ffcertificate_report_removed   = isset( $ffcertificate_cleanup_report['shortcodes_removed'] ) ? (int) $ffcertificate_cleanup_report['shortcodes_removed'] : 0;
				$ffcertificate_report_items     = isset( $ffcertificate_cleanup_report['affected'] ) && is_array( $ffcertificate_cleanup_report['affected'] ) ? $ffcertificate_cleanup_report['affected'] : array();
				$ffcertificate_report_truncated = ! empty( $ffcertificate_cleanup_report['truncated'] );
				$ffcertificate_report_days      = isset( $ffcertificate_cleanup_report['days'] ) ? (int) $ffcertificate_cleanup_report['days'] : $ffcertificate_cleanup_days;
				?>
				<h4 style="margin-top: 18px;">
					<?php if ( $ffcertificate_report_is_dry ) : ?>
						<?php esc_html_e( 'Preview report', 'ffcertificate' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Cleanup report', 'ffcertificate' ); ?>
					<?php endif; ?>
				</h4>

				<p class="description">
					<?php
					printf(
						/* translators: %d: days threshold */
						esc_html__( 'Grace window: more than %d days since form end date.', 'ffcertificate' ),
						(int) $ffcertificate_report_days
					);
					?>
				</p>

				<div class="ffc-migration-stats">
					<div>
						<div class="ffc-migration-stat-label"><?php esc_html_e( 'Expired forms', 'ffcertificate' ); ?></div>
						<div class="ffc-migration-stat-value"><?php echo esc_html( number_format_i18n( $ffcertificate_report_expired ) ); ?></div>
					</div>
					<div>
						<div class="ffc-migration-stat-label"><?php esc_html_e( 'Posts scanned', 'ffcertificate' ); ?></div>
						<div class="ffc-migration-stat-value"><?php echo esc_html( number_format_i18n( $ffcertificate_report_scanned ) ); ?></div>
					</div>
					<div>
						<div class="ffc-migration-stat-label"><?php esc_html_e( 'Posts affected', 'ffcertificate' ); ?></div>
						<div class="ffc-migration-stat-value info"><?php echo esc_html( number_format_i18n( $ffcertificate_report_affected ) ); ?></div>
					</div>
					<div>
						<div class="ffc-migration-stat-label"><?php esc_html_e( 'Shortcodes removed', 'ffcertificate' ); ?></div>
						<div class="ffc-migration-stat-value success"><?php echo esc_html( number_format_i18n( $ffcertificate_report_removed ) ); ?></div>
					</div>
				</div>

				<?php if ( ! empty( $ffcertificate_report_items ) ) : ?>
					<table class="widefat striped" style="margin-top: 10px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Post', 'ffcertificate' ); ?></th>
								<th><?php esc_html_e( 'Type', 'ffcertificate' ); ?></th>
								<th style="width: 110px; text-align: right;">
									<?php if ( $ffcertificate_report_is_dry ) : ?>
										<?php esc_html_e( 'Would remove', 'ffcertificate' ); ?>
									<?php else : ?>
										<?php esc_html_e( 'Removed', 'ffcertificate' ); ?>
									<?php endif; ?>
								</th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $ffcertificate_report_items as $ffcertificate_item ) :
								$ffcertificate_item_post_id   = isset( $ffcertificate_item['post_id'] ) ? (int) $ffcertificate_item['post_id'] : 0;
								$ffcertificate_item_title     = isset( $ffcertificate_item['post_title'] ) ? (string) $ffcertificate_item['post_title'] : '';
								$ffcertificate_item_type      = isset( $ffcertificate_item['post_type'] ) ? (string) $ffcertificate_item['post_type'] : '';
								$ffcertificate_item_count     = isset( $ffcertificate_item['removed_count'] ) ? (int) $ffcertificate_item['removed_count'] : 0;
								$ffcertificate_item_edit_link = $ffcertificate_item_post_id > 0 ? (string) get_edit_post_link( $ffcertificate_item_post_id ) : '';
								if ( $ffcertificate_item_title === '' ) {
									$ffcertificate_item_title = sprintf(
										/* translators: %d: post id */
										__( '(no title, ID %d)', 'ffcertificate' ),
										$ffcertificate_item_post_id
									);
								}
								?>
								<tr>
									<td>
										<?php if ( $ffcertificate_item_edit_link ) : ?>
											<a href="<?php echo esc_url( $ffcertificate_item_edit_link ); ?>">
												<?php echo esc_html( $ffcertificate_item_title ); ?>
											</a>
										<?php else : ?>
											<?php echo esc_html( $ffcertificate_item_title ); ?>
										<?php endif; ?>
									</td>
									<td><code><?php echo esc_html( $ffcertificate_item_type ); ?></code></td>
									<td style="text-align: right;"><?php echo esc_html( number_format_i18n( $ffcertificate_item_count ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( $ffcertificate_report_truncated ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %d: total posts affected */
								esc_html__( 'Showing first %1$d of %2$d affected posts. The rest will still be processed on Apply.', 'ffcertificate' ),
								(int) \FreeFormCertificate\Migrations\ObsoleteShortcodeCleaner::REPORT_LIMIT,
								$ffcertificate_report_affected
							);
							?>
						</p>
					<?php endif; ?>
				<?php elseif ( $ffcertificate_report_affected === 0 ) : ?>
					<p class="description">
						<?php esc_html_e( 'No obsolete shortcodes found. Nothing to clean up.', 'ffcertificate' ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

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