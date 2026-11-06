<?php
/**
 * Activity Log Page View
 *
 * @package FreeFormCertificate\Admin\Views
 * @version 3.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffcertificate_base_url = admin_url( 'admin.php?page=ffc-settings&tab=activity_log' );
?>

<div class="ffc-settings-wrap">
	<h2 class="wp-heading-inline ffc-icon-history"><?php esc_html_e( 'Activity Log', 'ffcertificate' ); ?></h2>

	<p class="description">
		<?php esc_html_e( 'Activity logs track important actions for audit and LGPD compliance.', 'ffcertificate' ); ?>
	</p>

	<!-- Filters -->
	<div class="tablenav top ffc-log-filters">
		<div class="alignleft actions">
			<form method="get">
				<input type="hidden" name="page" value="ffc-settings">
				<input type="hidden" name="tab" value="activity_log">

				<!-- Level Filter -->
				<label for="filter-by-level" class="screen-reader-text"><?php esc_html_e( 'Filter by level', 'ffcertificate' ); ?></label>
				<select name="level" id="filter-by-level">
					<option value=""><?php esc_html_e( 'All Levels', 'ffcertificate' ); ?></option>
					<option value="info" <?php selected( $level, 'info' ); ?>><?php esc_html_e( 'Info', 'ffcertificate' ); ?></option>
					<option value="warning" <?php selected( $level, 'warning' ); ?>><?php esc_html_e( 'Warning', 'ffcertificate' ); ?></option>
					<option value="error" <?php selected( $level, 'error' ); ?>><?php esc_html_e( 'Error', 'ffcertificate' ); ?></option>
					<option value="debug" <?php selected( $level, 'debug' ); ?>><?php esc_html_e( 'Debug', 'ffcertificate' ); ?></option>
				</select>

				<!-- Action Filter, sorted by label (#2340) -->
				<?php if ( ! empty( $unique_actions ) ) : ?>
					<label for="filter-by-action" class="screen-reader-text"><?php esc_html_e( 'Filter by action', 'ffcertificate' ); ?></label>
					<select name="log_action" id="filter-by-action">
						<option value=""><?php esc_html_e( 'All Actions', 'ffcertificate' ); ?></option>
						<?php foreach ( $unique_actions as $ffcertificate_act ) : ?>
							<option value="<?php echo esc_attr( $ffcertificate_act ); ?>" <?php selected( $action, $ffcertificate_act ); ?>>
								<?php echo esc_html( \FreeFormCertificate\Admin\AdminActivityLogPage::get_action_label( $ffcertificate_act ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>

				<!-- User Filter (#2340) -->
				<label for="filter-by-user" class="screen-reader-text"><?php esc_html_e( 'Filter by user', 'ffcertificate' ); ?></label>
				<select name="log_user" id="filter-by-user">
					<option value=""><?php esc_html_e( 'All Users', 'ffcertificate' ); ?></option>
					<?php foreach ( $user_options as $ffcertificate_user_value => $ffcertificate_user_label ) : ?>
						<option value="<?php echo esc_attr( (string) $ffcertificate_user_value ); ?>" <?php selected( $log_user, (string) $ffcertificate_user_value ); ?>><?php echo esc_html( $ffcertificate_user_label ); ?></option>
					<?php endforeach; ?>
				</select>

				<!-- Date Range, in the site's timezone (#2340) -->
				<label for="filter-date-from"><?php esc_html_e( 'From', 'ffcertificate' ); ?></label>
				<input type="date" id="filter-date-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
				<label for="filter-date-to"><?php esc_html_e( 'To', 'ffcertificate' ); ?></label>
				<input type="date" id="filter-date-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">

				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label' => __( 'Filter', 'ffcertificate' ),
						'icon'  => 'filter',
						'type'  => 'submit',
					)
				);
				// Always printed and shown while a filter is set, so it works
				// after a filter applied in place (#2340). `ffc-log-clear` is
				// the hook ffc-admin-activity-log.js resets the filters on.
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label' => __( 'Clear Filters', 'ffcertificate' ),
						'icon'  => 'eraser',
						'href'  => $ffcertificate_base_url,
						'class' => 'ffc-log-clear',
						'attrs' => $has_filters ? array() : array( 'hidden' => 'hidden' ),
					)
				);
				?>
			</form>
		</div>

		<!-- Search Box -->
		<div class="alignright actions">
			<form method="get" class="ffc-inline">
				<input type="hidden" name="page" value="ffc-settings">
				<input type="hidden" name="tab" value="activity_log">
				<?php
				// Without JavaScript the search keeps the other filters.
				foreach (
					array(
						'level'      => $level,
						'log_action' => $action,
						'log_user'   => $log_user,
						'date_from'  => $date_from,
						'date_to'    => $date_to,
					) as $ffcertificate_hidden_name => $ffcertificate_hidden_value
				) :
					if ( '' !== $ffcertificate_hidden_value ) :
						?>
						<input type="hidden" name="<?php echo esc_attr( $ffcertificate_hidden_name ); ?>" value="<?php echo esc_attr( $ffcertificate_hidden_value ); ?>">
						<?php
					endif;
				endforeach;
				?>
				<label for="ffc-log-search" class="screen-reader-text"><?php esc_html_e( 'Search activity logs', 'ffcertificate' ); ?></label>
				<input type="search" id="ffc-log-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search logs...', 'ffcertificate' ); ?>">
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label' => __( 'Search', 'ffcertificate' ),
						'icon'  => 'search',
						'type'  => 'submit',
					)
				);
				?>
			</form>

			<?php
			// Batched CSV export (#772). It takes the filters on screen when it
			// is clicked, and only a holder of the export capability sees it
			// (#2340).
			if ( $can_export ) :
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label' => __( 'Export CSV', 'ffcertificate' ),
						'icon'  => 'download',
						'id'    => 'ffc-activitylog-export-btn',
					)
				);
			endif;
			?>
		</div>
	</div>

	<!-- Count and page reveal (#2340) -->
	<div class="ffc-log-meta" <?php echo empty( $logs ) ? 'hidden' : ''; ?>>
		<span class="ffc-log-count" aria-live="polite"><?php echo esc_html( \FreeFormCertificate\Admin\AdminActivityLogPage::count_text( (int) $total_logs ) ); ?></span>
		<?php echo \FreeFormCertificate\Core\PiiReveal::page_button( \FreeFormCertificate\Admin\AdminActivityLogPage::PII_CAP, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page_button() escapes every value. ?>
	</div>

	<!-- Logs Table -->
	<div id="ffc-activity-log-table">
	<?php if ( empty( $logs ) ) : ?>
		<?php echo \FreeFormCertificate\Admin\AdminActivityLogPage::render_empty_html( $has_filters ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callout_markup() and Button::html() escape every value. ?>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th width="12%"><?php esc_html_e( 'Date/Time', 'ffcertificate' ); ?></th>
					<th width="10%"><?php esc_html_e( 'Level', 'ffcertificate' ); ?></th>
					<th width="18%"><?php esc_html_e( 'Action', 'ffcertificate' ); ?></th>
					<th width="15%"><?php esc_html_e( 'User', 'ffcertificate' ); ?></th>
					<th width="12%"><?php esc_html_e( 'IP Address', 'ffcertificate' ); ?></th>
					<th width="33%"><?php esc_html_e( 'Context', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				// Server-rendered rows — the AJAX endpoint reuses the
				// same helper so the JS swap doesn't drift.
				echo \FreeFormCertificate\Admin\AdminActivityLogPage::render_rows_html( $logs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped helper.
				?>
			</tbody>
		</table>

		<div id="ffc-activity-log-pagination">
			<?php
			echo \FreeFormCertificate\Admin\AdminActivityLogPage::render_pagination_html( (int) $total_logs, (int) $current_page, (int) $per_page ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped helper.
			?>
		</div>
	<?php endif; ?>
	</div>

	<!-- Stats Summary -->
	<div class="card ffc-activity-card">
		<h2 class="ffc-icon-chart"><?php esc_html_e( 'Activity Summary (Last 30 Days)', 'ffcertificate' ); ?></h2>
		<?php
		$ffcertificate_stats = \FreeFormCertificate\Core\ActivityLogQuery::get_stats( 30 );
		?>
		<p>
			<strong><?php esc_html_e( 'Total Activities:', 'ffcertificate' ); ?></strong> <?php echo esc_html( number_format_i18n( $ffcertificate_stats['total'] ) ); ?>
		</p>

		<?php if ( ! empty( $ffcertificate_stats['by_level'] ) ) : ?>
			<p><strong><?php esc_html_e( 'By Level:', 'ffcertificate' ); ?></strong></p>
			<ul class="ffc-ml-20">
				<?php foreach ( $ffcertificate_stats['by_level'] as $ffcertificate_level_stat ) : ?>
					<li>
						<?php echo wp_kses_post( \FreeFormCertificate\Admin\AdminActivityLogPage::get_level_badge( $ffcertificate_level_stat['level'] ) ); ?>
						<?php echo esc_html( number_format_i18n( $ffcertificate_level_stat['count'] ) ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( ! empty( $ffcertificate_stats['top_actions'] ) ) : ?>
			<p><strong><?php esc_html_e( 'Top Actions:', 'ffcertificate' ); ?></strong></p>
			<ul class="ffc-ml-20">
				<?php foreach ( array_slice( $ffcertificate_stats['top_actions'], 0, 5 ) as $ffcertificate_action_stat ) : ?>
					<li>
						<strong><?php echo esc_html( \FreeFormCertificate\Admin\AdminActivityLogPage::get_action_label( $ffcertificate_action_stat['action'] ) ); ?>:</strong>
						<?php echo esc_html( number_format_i18n( $ffcertificate_action_stat['count'] ) ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</div>
