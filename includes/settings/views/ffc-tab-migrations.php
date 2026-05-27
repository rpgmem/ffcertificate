<?php
/**
 * Settings Tab: Data Migrations
 *
 * Template for the Data Migrations settings tab
 *
 * @package FreeFormCertificate\Settings\Views
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
		\FreeFormCertificate\Core\Debug::log_migrations(
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
	// Show initialization error if MigrationManager failed.
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
			// Check if migration is available.
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
				// Status calculation failed — show error state, keep migration available.
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

			// Generate migration URL.
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
	// ──────────────────────────────────────────────────────────────.
	// Obsolete Shortcode Cleanup (v5.1.0)
	// ──────────────────────────────────────────────────────────────.
	$ffcertificate_cleanup_days = max( 1, \FreeFormCertificate\Settings\SettingsReader::get_int( 'obsolete_shortcode_days', 90 ) );

	$ffcertificate_user_id        = get_current_user_id();
	$ffcertificate_cleanup_report = get_transient( 'ffc_obsolete_cleanup_report_' . $ffcertificate_user_id );
	$ffcertificate_preview_ok     = (bool) get_transient( 'ffc_obsolete_cleanup_preview_ok_' . $ffcertificate_user_id );

	$ffcertificate_cleanup_msg = \FreeFormCertificate\Core\Utils::get_get_string( 'obsolete_cleanup_msg' );
	$ffcertificate_cleanup_err = \FreeFormCertificate\Core\Utils::get_get_string( 'obsolete_cleanup_error' );

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
								if ( '' === $ffcertificate_item_title ) {
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
								/* translators: 1: visible posts shown, 2: total posts affected */
								esc_html__( 'Showing first %1$d of %2$d affected posts. The rest will still be processed on Apply.', 'ffcertificate' ),
								(int) \FreeFormCertificate\Migrations\ObsoleteShortcodeCleaner::REPORT_LIMIT,
								(int) $ffcertificate_report_affected
							);
							?>
						</p>
					<?php endif; ?>
				<?php elseif ( 0 === $ffcertificate_report_affected ) : ?>
					<p class="description">
						<?php esc_html_e( 'No obsolete shortcodes found. Nothing to clean up.', 'ffcertificate' ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

	<?php
	// ──────────────────────────────────────────────────────────────.
	// Short URL Cleanup (v6.7.x)
	// ──────────────────────────────────────────────────────────────.
	$ffcertificate_url_days     = max( 1, \FreeFormCertificate\Settings\SettingsReader::get_int( 'url_cleanup_days', 90 ) );
	$ffcertificate_url_orphaned = (bool) \FreeFormCertificate\Settings\SettingsReader::get_int( 'url_cleanup_orphaned', 1 );
	$ffcertificate_url_never    = (bool) \FreeFormCertificate\Settings\SettingsReader::get_int( 'url_cleanup_never_clicked', 0 );
	$ffcertificate_url_trashed  = (bool) \FreeFormCertificate\Settings\SettingsReader::get_int( 'url_cleanup_trashed', 1 );

	$ffcertificate_url_report     = get_transient( 'ffc_url_cleanup_report_' . $ffcertificate_user_id );
	$ffcertificate_url_preview_ok = (bool) get_transient( 'ffc_url_cleanup_preview_ok_' . $ffcertificate_user_id );
	$ffcertificate_url_msg        = \FreeFormCertificate\Core\Utils::get_get_string( 'url_cleanup_msg' );
	$ffcertificate_url_err        = \FreeFormCertificate\Core\Utils::get_get_string( 'url_cleanup_error' );

	$ffcertificate_url_preview_url = wp_nonce_url(
		add_query_arg( 'ffc_url_cleanup', 'preview', $ffcertificate_base_url ),
		'ffc_url_cleanup_preview'
	);
	$ffcertificate_url_apply_url   = wp_nonce_url(
		add_query_arg( 'ffc_url_cleanup', 'apply', $ffcertificate_base_url ),
		'ffc_url_cleanup_apply'
	);
	?>
	<div class="postbox ffc-migration-card ffc-url-cleanup-card">
		<div class="postbox-header">
			<h3 class="hndle">
				<span class="ffc-icon-delete"><?php esc_html_e( 'Short URL Cleanup', 'ffcertificate' ); ?></span>
			</h3>
		</div>
		<div class="inside">
			<p class="description">
				<?php esc_html_e( 'Delete obsolete short URLs from the URL shortener: those whose target post no longer exists, those created long ago and never clicked, and those already in the trash. Choose the criteria, preview, then delete.', 'ffcertificate' ); ?>
			</p>

			<div class="ffc-migration-warning">
				<p>
					<strong class="ffc-icon-info"><?php esc_html_e( 'Heads up:', 'ffcertificate' ); ?></strong>
					<?php esc_html_e( 'Deletion is permanent — short URLs are hard-deleted and any printed/shared links pointing at them will stop resolving. Always run a preview first.', 'ffcertificate' ); ?>
				</p>
			</div>

			<?php if ( $ffcertificate_url_msg ) : ?>
				<div class="notice notice-success inline" style="margin: 10px 0;"><p><?php echo esc_html( $ffcertificate_url_msg ); ?></p></div>
			<?php endif; ?>
			<?php if ( $ffcertificate_url_err ) : ?>
				<div class="notice notice-error inline" style="margin: 10px 0;"><p><?php echo esc_html( $ffcertificate_url_err ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $ffcertificate_url_preview_url ); ?>" style="margin: 12px 0;">
									<fieldset style="margin-bottom: 10px;">
						<div style="margin-bottom:6px;">
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'url_cleanup_orphaned',
									'id'      => 'url_cleanup_orphaned',
									'checked' => $ffcertificate_url_orphaned,
									'label'   => __( 'Orphaned — the target post no longer exists', 'ffcertificate' ),
								)
							);
							?>
						</div>
						<div style="margin-bottom:6px; display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'url_cleanup_never_clicked',
									'id'      => 'url_cleanup_never_clicked',
									'checked' => $ffcertificate_url_never,
									'label'   => __( 'Never clicked and created more than', 'ffcertificate' ),
								)
							);
							?>
							<input type="number" name="url_cleanup_days" min="1" max="3650" step="1" value="<?php echo esc_attr( (string) $ffcertificate_url_days ); ?>" style="width:80px;">
							<span><?php esc_html_e( 'days ago', 'ffcertificate' ); ?></span>
						</div>
						<div>
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'url_cleanup_trashed',
									'id'      => 'url_cleanup_trashed',
									'checked' => $ffcertificate_url_trashed,
									'label'   => __( 'In the trash (status = trashed)', 'ffcertificate' ),
								)
							);
							?>
						</div>
					</fieldset>
				<button type="submit" class="button button-secondary">
					<span class="dashicons dashicons-visibility"></span>
					<?php esc_html_e( 'Save criteria & preview', 'ffcertificate' ); ?>
				</button>
			</form>

			<div class="ffc-migration-actions">
				<?php if ( $ffcertificate_url_preview_ok ) : ?>
					<a href="<?php echo esc_url( $ffcertificate_url_apply_url ); ?>" class="button button-primary"
						data-confirm="<?php esc_attr_e( 'This permanently deletes the matched short URLs and cannot be undone. Continue?', 'ffcertificate' ); ?>">
						<span class="dashicons dashicons-trash"></span>
						<?php esc_html_e( 'Delete matched short URLs', 'ffcertificate' ); ?>
					</a>
				<?php else : ?>
					<span class="button button-primary" disabled aria-disabled="true" title="<?php esc_attr_e( 'Run a preview first', 'ffcertificate' ); ?>">
						<span class="dashicons dashicons-trash"></span>
						<?php esc_html_e( 'Delete matched short URLs', 'ffcertificate' ); ?>
					</span>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'The delete button unlocks for 5 minutes after a successful preview.', 'ffcertificate' ); ?>
				</p>
			</div>

			<?php
			if ( is_array( $ffcertificate_url_report ) ) :
				$ffcertificate_url_is_dry     = ! empty( $ffcertificate_url_report['dry_run'] );
				$ffcertificate_url_candidates = isset( $ffcertificate_url_report['candidates'] ) ? (int) $ffcertificate_url_report['candidates'] : 0;
				$ffcertificate_url_deleted    = isset( $ffcertificate_url_report['deleted'] ) ? (int) $ffcertificate_url_report['deleted'] : 0;
				$ffcertificate_url_by_reason  = isset( $ffcertificate_url_report['by_reason'] ) && is_array( $ffcertificate_url_report['by_reason'] ) ? $ffcertificate_url_report['by_reason'] : array();
				$ffcertificate_url_items      = isset( $ffcertificate_url_report['affected'] ) && is_array( $ffcertificate_url_report['affected'] ) ? $ffcertificate_url_report['affected'] : array();
				$ffcertificate_url_truncated  = ! empty( $ffcertificate_url_report['truncated'] );
				?>
				<h4 style="margin-top: 18px;">
					<?php echo $ffcertificate_url_is_dry ? esc_html__( 'Preview report', 'ffcertificate' ) : esc_html__( 'Cleanup report', 'ffcertificate' ); ?>
				</h4>

				<div class="ffc-migration-stats">
					<div>
						<div class="ffc-migration-stat-label"><?php esc_html_e( 'Candidates', 'ffcertificate' ); ?></div>
						<div class="ffc-migration-stat-value info"><?php echo esc_html( number_format_i18n( $ffcertificate_url_candidates ) ); ?></div>
					</div>
					<div>
						<div class="ffc-migration-stat-label"><?php esc_html_e( 'Orphaned', 'ffcertificate' ); ?></div>
						<div class="ffc-migration-stat-value"><?php echo esc_html( number_format_i18n( (int) ( $ffcertificate_url_by_reason['orphaned'] ?? 0 ) ) ); ?></div>
					</div>
					<div>
						<div class="ffc-migration-stat-label"><?php esc_html_e( 'Never clicked', 'ffcertificate' ); ?></div>
						<div class="ffc-migration-stat-value"><?php echo esc_html( number_format_i18n( (int) ( $ffcertificate_url_by_reason['never_clicked'] ?? 0 ) ) ); ?></div>
					</div>
					<div>
						<div class="ffc-migration-stat-label"><?php esc_html_e( 'Trashed', 'ffcertificate' ); ?></div>
						<div class="ffc-migration-stat-value"><?php echo esc_html( number_format_i18n( (int) ( $ffcertificate_url_by_reason['trashed'] ?? 0 ) ) ); ?></div>
					</div>
					<div>
						<div class="ffc-migration-stat-label"><?php echo $ffcertificate_url_is_dry ? esc_html__( 'Would delete', 'ffcertificate' ) : esc_html__( 'Deleted', 'ffcertificate' ); ?></div>
						<div class="ffc-migration-stat-value success"><?php echo esc_html( number_format_i18n( $ffcertificate_url_is_dry ? $ffcertificate_url_candidates : $ffcertificate_url_deleted ) ); ?></div>
					</div>
				</div>

				<?php if ( ! empty( $ffcertificate_url_items ) ) : ?>
					<table class="widefat striped" style="margin-top: 10px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Short code', 'ffcertificate' ); ?></th>
								<th><?php esc_html_e( 'Title / target', 'ffcertificate' ); ?></th>
								<th><?php esc_html_e( 'Reasons', 'ffcertificate' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $ffcertificate_url_items as $ffcertificate_u ) : ?>
								<tr>
									<td><code><?php echo esc_html( (string) ( $ffcertificate_u['short_code'] ?? '' ) ); ?></code></td>
									<td>
										<?php
										$ffcertificate_u_title = (string) ( $ffcertificate_u['title'] ?? '' );
										echo esc_html( '' !== $ffcertificate_u_title ? $ffcertificate_u_title : (string) ( $ffcertificate_u['target_url'] ?? '' ) );
										?>
									</td>
									<td>
										<?php
										$ffcertificate_u_reasons = isset( $ffcertificate_u['reasons'] ) && is_array( $ffcertificate_u['reasons'] ) ? $ffcertificate_u['reasons'] : array();
										echo esc_html( implode( ', ', array_map( 'strval', $ffcertificate_u_reasons ) ) );
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( $ffcertificate_url_truncated ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: 1: rows shown, 2: total candidates */
								esc_html__( 'Showing first %1$d of %2$d matches. All matches are processed on delete.', 'ffcertificate' ),
								(int) \FreeFormCertificate\Maintenance\UrlShortenerCleaner::REPORT_LIMIT,
								(int) $ffcertificate_url_candidates
							);
							?>
						</p>
					<?php endif; ?>
				<?php elseif ( 0 === $ffcertificate_url_candidates ) : ?>
					<p class="description"><?php esc_html_e( 'No matching short URLs found. Nothing to clean up.', 'ffcertificate' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

	<?php
	// ──────────────────────────────────────────────────────────────.
	// Disable Public Operator Access on old forms (v6.7.x)
	// ──────────────────────────────────────────────────────────────.
	$ffcertificate_pa_days       = max( 1, \FreeFormCertificate\Settings\SettingsReader::get_int( 'public_access_disable_days', 90 ) );
	$ffcertificate_pa_report     = get_transient( 'ffc_pubaccess_report_' . $ffcertificate_user_id );
	$ffcertificate_pa_preview_ok = (bool) get_transient( 'ffc_pubaccess_preview_ok_' . $ffcertificate_user_id );
	$ffcertificate_pa_msg        = \FreeFormCertificate\Core\Utils::get_get_string( 'pubaccess_msg' );
	$ffcertificate_pa_err        = \FreeFormCertificate\Core\Utils::get_get_string( 'pubaccess_error' );

	$ffcertificate_pa_preview_url = wp_nonce_url(
		add_query_arg( 'ffc_pubaccess', 'preview', $ffcertificate_base_url ),
		'ffc_pubaccess_preview'
	);
	$ffcertificate_pa_apply_url   = wp_nonce_url(
		add_query_arg( 'ffc_pubaccess', 'apply', $ffcertificate_base_url ),
		'ffc_pubaccess_apply'
	);
	?>
	<div class="postbox ffc-migration-card ffc-pubaccess-card">
		<div class="postbox-header">
			<h3 class="hndle">
				<span class="ffc-icon-lock"><?php esc_html_e( 'Disable Public Operator Access on old forms', 'ffcertificate' ); ?></span>
			</h3>
		</div>
		<div class="inside">
			<p class="description">
				<?php esc_html_e( 'Switch off Public Operator Access (and its sub-features) on published forms whose collection period ended more than the grace window ago. The access token and other settings are preserved, so access can be re-enabled later if needed.', 'ffcertificate' ); ?>
			</p>

			<?php if ( $ffcertificate_pa_msg ) : ?>
				<div class="notice notice-success inline" style="margin: 10px 0;"><p><?php echo esc_html( $ffcertificate_pa_msg ); ?></p></div>
			<?php endif; ?>
			<?php if ( $ffcertificate_pa_err ) : ?>
				<div class="notice notice-error inline" style="margin: 10px 0;"><p><?php echo esc_html( $ffcertificate_pa_err ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $ffcertificate_pa_preview_url ); ?>" style="margin: 12px 0;">
				<label for="ffc-pubaccess-days" style="margin-right: 8px;">
					<?php esc_html_e( 'Disable access on forms ended more than', 'ffcertificate' ); ?>
				</label>
				<input type="number" id="ffc-pubaccess-days" name="public_access_disable_days" min="1" max="3650" step="1" value="<?php echo esc_attr( (string) $ffcertificate_pa_days ); ?>" style="width: 90px;">
				<span><?php esc_html_e( 'days ago', 'ffcertificate' ); ?></span>
				<button type="submit" class="button button-secondary">
					<span class="dashicons dashicons-visibility"></span>
					<?php esc_html_e( 'Save & preview', 'ffcertificate' ); ?>
				</button>
			</form>

			<div class="ffc-migration-actions">
				<?php if ( $ffcertificate_pa_preview_ok ) : ?>
					<a href="<?php echo esc_url( $ffcertificate_pa_apply_url ); ?>" class="button button-primary"
						data-confirm="<?php esc_attr_e( 'This switches off Public Operator Access on the matched forms. Their access tokens are preserved so they can be re-enabled later. Continue?', 'ffcertificate' ); ?>">
						<span class="dashicons dashicons-lock"></span>
						<?php esc_html_e( 'Disable access now', 'ffcertificate' ); ?>
					</a>
				<?php else : ?>
					<span class="button button-primary" disabled aria-disabled="true" title="<?php esc_attr_e( 'Run a preview first', 'ffcertificate' ); ?>">
						<span class="dashicons dashicons-lock"></span>
						<?php esc_html_e( 'Disable access now', 'ffcertificate' ); ?>
					</span>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'The disable button unlocks for 5 minutes after a successful preview.', 'ffcertificate' ); ?>
				</p>
			</div>

			<?php
			if ( is_array( $ffcertificate_pa_report ) ) :
				$ffcertificate_pa_is_dry     = ! empty( $ffcertificate_pa_report['dry_run'] );
				$ffcertificate_pa_candidates = isset( $ffcertificate_pa_report['candidates'] ) ? (int) $ffcertificate_pa_report['candidates'] : 0;
				$ffcertificate_pa_disabled   = isset( $ffcertificate_pa_report['disabled'] ) ? (int) $ffcertificate_pa_report['disabled'] : 0;
				$ffcertificate_pa_items      = isset( $ffcertificate_pa_report['affected'] ) && is_array( $ffcertificate_pa_report['affected'] ) ? $ffcertificate_pa_report['affected'] : array();
				$ffcertificate_pa_truncated  = ! empty( $ffcertificate_pa_report['truncated'] );
				?>
				<h4 style="margin-top: 18px;">
					<?php echo $ffcertificate_pa_is_dry ? esc_html__( 'Preview report', 'ffcertificate' ) : esc_html__( 'Result', 'ffcertificate' ); ?>
				</h4>

				<div class="ffc-migration-stats">
					<div>
						<div class="ffc-migration-stat-label"><?php esc_html_e( 'Forms with access enabled & expired', 'ffcertificate' ); ?></div>
						<div class="ffc-migration-stat-value info"><?php echo esc_html( number_format_i18n( $ffcertificate_pa_candidates ) ); ?></div>
					</div>
					<div>
						<div class="ffc-migration-stat-label"><?php echo $ffcertificate_pa_is_dry ? esc_html__( 'Would disable', 'ffcertificate' ) : esc_html__( 'Disabled', 'ffcertificate' ); ?></div>
						<div class="ffc-migration-stat-value success"><?php echo esc_html( number_format_i18n( $ffcertificate_pa_is_dry ? $ffcertificate_pa_candidates : $ffcertificate_pa_disabled ) ); ?></div>
					</div>
				</div>

				<?php if ( ! empty( $ffcertificate_pa_items ) ) : ?>
					<table class="widefat striped" style="margin-top: 10px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Form', 'ffcertificate' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $ffcertificate_pa_items as $ffcertificate_pa_item ) :
								$ffcertificate_pa_fid   = isset( $ffcertificate_pa_item['form_id'] ) ? (int) $ffcertificate_pa_item['form_id'] : 0;
								$ffcertificate_pa_title = isset( $ffcertificate_pa_item['title'] ) ? (string) $ffcertificate_pa_item['title'] : '';
								$ffcertificate_pa_link  = $ffcertificate_pa_fid > 0 ? (string) get_edit_post_link( $ffcertificate_pa_fid ) : '';
								if ( '' === $ffcertificate_pa_title ) {
									$ffcertificate_pa_title = sprintf(
										/* translators: %d: form id */
										__( '(no title, ID %d)', 'ffcertificate' ),
										$ffcertificate_pa_fid
									);
								}
								?>
								<tr>
									<td>
										<?php if ( $ffcertificate_pa_link ) : ?>
											<a href="<?php echo esc_url( $ffcertificate_pa_link ); ?>"><?php echo esc_html( $ffcertificate_pa_title ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $ffcertificate_pa_title ); ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( $ffcertificate_pa_truncated ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: 1: forms shown, 2: total candidates */
								esc_html__( 'Showing first %1$d of %2$d forms. All are processed on apply.', 'ffcertificate' ),
								(int) \FreeFormCertificate\Maintenance\PublicOperatorAccessDisabler::REPORT_LIMIT,
								(int) $ffcertificate_pa_candidates
							);
							?>
						</p>
					<?php endif; ?>
				<?php elseif ( 0 === $ffcertificate_pa_candidates ) : ?>
					<p class="description"><?php esc_html_e( 'No expired forms still have Public Operator Access enabled. Nothing to do.', 'ffcertificate' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

	<?php
	// ──────────────────────────────────────────────────────────────.
	// Submission ↔ user link audit (report-only) (v6.7.x)
	// ──────────────────────────────────────────────────────────────.
	$ffcertificate_sa_report = get_transient( 'ffc_submission_audit_report_' . $ffcertificate_user_id );
	$ffcertificate_sa_msg    = \FreeFormCertificate\Core\Utils::get_get_string( 'submission_audit_msg' );
	$ffcertificate_sa_err    = \FreeFormCertificate\Core\Utils::get_get_string( 'submission_audit_error' );

	$ffcertificate_sa_scan_url = wp_nonce_url(
		add_query_arg( 'ffc_submission_audit', 'scan', $ffcertificate_base_url ),
		'ffc_submission_audit_scan'
	);

	$ffcertificate_sa_labels = array(
		'orphan_links'        => __( 'Linked to a deleted user', 'ffcertificate' ),
		'multiple_identities' => __( 'User bound to multiple CPF/RF', 'ffcertificate' ),
		'should_be_linked'    => __( 'Unlinked but CPF matches a linked record', 'ffcertificate' ),
		'shared_identities'   => __( 'Same CPF shared across users', 'ffcertificate' ),
	);
	?>
	<div class="postbox ffc-migration-card ffc-submission-audit-card">
		<div class="postbox-header">
			<h3 class="hndle">
				<span class="ffc-icon-search"><?php esc_html_e( 'Submission ↔ user link audit', 'ffcertificate' ); ?></span>
			</h3>
		</div>
		<div class="inside">
			<p class="description">
				<?php esc_html_e( 'Report-only scan for submissions wrongly linked to WordPress users. Nothing is changed — review each finding and fix it manually. Detection uses the stored CPF/RF hashes, so no decryption is involved.', 'ffcertificate' ); ?>
			</p>

			<?php if ( $ffcertificate_sa_msg ) : ?>
				<div class="notice notice-success inline" style="margin: 10px 0;"><p><?php echo esc_html( $ffcertificate_sa_msg ); ?></p></div>
			<?php endif; ?>
			<?php if ( $ffcertificate_sa_err ) : ?>
				<div class="notice notice-error inline" style="margin: 10px 0;"><p><?php echo esc_html( $ffcertificate_sa_err ); ?></p></div>
			<?php endif; ?>

			<div class="ffc-migration-actions" style="margin: 12px 0;">
				<a href="<?php echo esc_url( $ffcertificate_sa_scan_url ); ?>" class="button button-secondary">
					<span class="dashicons dashicons-search"></span>
					<?php esc_html_e( 'Run audit', 'ffcertificate' ); ?>
				</a>
			</div>

			<?php
			if ( is_array( $ffcertificate_sa_report ) ) :
				$ffcertificate_sa_total  = isset( $ffcertificate_sa_report['total'] ) ? (int) $ffcertificate_sa_report['total'] : 0;
				$ffcertificate_sa_checks = isset( $ffcertificate_sa_report['checks'] ) && is_array( $ffcertificate_sa_report['checks'] ) ? $ffcertificate_sa_report['checks'] : array();
				?>
				<?php if ( 0 === $ffcertificate_sa_total ) : ?>
					<p class="description"><?php esc_html_e( 'No link problems found. Submissions and users look consistent.', 'ffcertificate' ); ?></p>
				<?php else : ?>
					<div class="ffc-migration-stats">
						<?php foreach ( $ffcertificate_sa_labels as $ffcertificate_sa_key => $ffcertificate_sa_label ) : ?>
							<?php $ffcertificate_sa_c = isset( $ffcertificate_sa_checks[ $ffcertificate_sa_key ]['count'] ) ? (int) $ffcertificate_sa_checks[ $ffcertificate_sa_key ]['count'] : 0; ?>
							<div>
								<div class="ffc-migration-stat-label"><?php echo esc_html( $ffcertificate_sa_label ); ?></div>
								<div class="ffc-migration-stat-value <?php echo $ffcertificate_sa_c > 0 ? 'info' : ''; ?>">
									<?php
									echo esc_html( number_format_i18n( $ffcertificate_sa_c ) );
									echo ( ! empty( $ffcertificate_sa_checks[ $ffcertificate_sa_key ]['truncated'] ) ) ? '+' : '';
									?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="description" style="margin-top: 10px;">
						<?php esc_html_e( 'Counts are capped at 50 per check (a “+” means there may be more). These are leads to investigate, not automatic fixes.', 'ffcertificate' ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

	<!-- Help Section -->
	<div class="card ffc-migration-help">
		<h2 class="ffc-icon-help"><?php esc_html_e( 'Need Help?', 'ffcertificate' ); ?></h2>
		
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