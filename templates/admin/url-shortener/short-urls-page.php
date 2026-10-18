<?php
/**
 * Template: Short URLs admin page (list + create + filter + table + QR modal).
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\UrlShortener\UrlShortenerAdminPage::render_page()}
 * (rpgmem/ffcertificate#563 coverage hygiene). Markup is byte-identical to the
 * pre-extraction inline body; the controller prepares the locals below and
 * includes this file (so $this + the locals are in scope).
 *
 * Variables in scope (provided by the including method):
 *
 * @var \FreeFormCertificate\UrlShortener\UrlShortenerAdminPage $this Including controller.
 * @var string                    $search      Current search term.
 * @var string                    $orderby     Current sort column.
 * @var string                    $order       Current sort direction.
 * @var string                    $status      Current status filter.
 * @var string                    $msg         Flash message key.
 * @var int                       $page        Current page number.
 * @var int                       $total       Total matching rows.
 * @var int                       $total_pages Total page count.
 * @var array<string,int>         $stats       Aggregate link stats.
 * @var array<int,array<string,mixed>> $items  Current page of rows.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since   6.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		<div class="wrap ffc-admin-page ffc-page-short-urls">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Short URLs', 'ffcertificate' ); ?></h1>
			<?php if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( \FreeFormCertificate\UrlShortener\QrGeneratorAjaxEndpoint::CAP ) ) : ?>
				<?php // New short URLs are made in the QR Code Generator, behind its "Create a short URL" switch (#1586). ?>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label'    => __( 'New short URL', 'ffcertificate' ),
						'category' => 'create',
						'icon'     => 'plus',
						'href'     => admin_url( 'admin.php?page=' . \FreeFormCertificate\UrlShortener\QrGeneratorPage::SLUG ),
					)
				);
				?>
			<?php endif; ?>
			<?php if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_settings' ) ) : ?>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label' => __( 'Settings', 'ffcertificate' ),
						'icon'  => 'settings',
						'href'  => admin_url( 'admin.php?page=ffc-settings&tab=url_shortener' ),
					)
				);
				?>
			<?php endif; ?>
			<?php if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_export_url_shortener' ) ) : ?>
				<?php // Batched CSV export (#772): the button drives the unified ffc_export_* dispatcher via window.FFCBatchedExport; carries the current search/status filters. ?>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label' => __( 'Export CSV', 'ffcertificate' ),
						'icon'  => 'download',
						'id'    => 'ffc-shorturl-export-btn',
						'attrs' => array(
							'data-s'      => $search,
							'data-status' => $status,
						),
					)
				);
				?>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php if ( 'trashed' === $msg ) : ?>
				<?php
				\FreeFormCertificate\Core\AdminNotice::render(
					'success',
					esc_html__( 'Short URL moved to Trash.', 'ffcertificate' )
				);
				?>
			<?php elseif ( 'restored' === $msg ) : ?>
				<?php
				\FreeFormCertificate\Core\AdminNotice::render(
					'success',
					esc_html__( 'Short URL restored.', 'ffcertificate' )
				);
				?>
			<?php elseif ( 'deleted' === $msg ) : ?>
				<?php
				\FreeFormCertificate\Core\AdminNotice::render(
					'success',
					esc_html__( 'Short URL permanently deleted.', 'ffcertificate' )
				);
				?>
			<?php elseif ( 'emptied' === $msg ) : ?>
				<?php
				\FreeFormCertificate\Core\AdminNotice::render(
					'success',
					esc_html__( 'Trash emptied.', 'ffcertificate' )
				);
				?>
			<?php endif; ?>

			<?php if ( 'trashed' !== $status ) : ?>
				<?php
				// The shared stat cards (#1631), the component the dashboards draw.
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_stat_card() escapes every value.
				?>
			<div class="ffc-stats">
				<?php
				echo \FreeFormCertificate\Admin\AdminUI::get_stat_card(
					array(
						'label' => __( 'Total Links', 'ffcertificate' ),
						'value' => (int) $stats['total_links'],
						'icon'  => 'link',
					)
				);
				echo \FreeFormCertificate\Admin\AdminUI::get_stat_card(
					array(
						'label' => __( 'Active', 'ffcertificate' ),
						'value' => (int) $stats['active_links'],
						'icon'  => 'checkmark',
						'tone'  => 'success',
					)
				);
				echo \FreeFormCertificate\Admin\AdminUI::get_stat_card(
					array(
						'label' => __( 'Total Clicks', 'ffcertificate' ),
						'value' => (int) $stats['total_clicks'],
						'icon'  => 'chart',
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
			<?php endif; ?>


			<!-- Search + Filter -->
			<form method="get" class="ffc-shorturl-filter">
				<input type="hidden" name="page" value="ffc-short-urls" />
				<div class="ffc-shorturl-filter-row">
					<select name="status">
						<option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( 'All statuses', 'ffcertificate' ); ?></option>
						<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'ffcertificate' ); ?></option>
						<option value="disabled" <?php selected( $status, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'ffcertificate' ); ?></option>
						<option value="trashed" <?php selected( $status, 'trashed' ); ?>>
							<?php
							/* translators: %d: number of trashed links */
							printf( esc_html__( 'Trash (%d)', 'ffcertificate' ), (int) $stats['trashed_links'] );
							?>
						</option>
					</select>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'ffcertificate' ); ?>" />
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label' => __( 'Filter', 'ffcertificate' ),
							'icon'  => 'filter',
							'type'  => 'submit',
						)
					);
					?>
				</div>
			</form>

			<?php if ( 'trashed' === $status && $total > 0 ) : ?>
				<?php
				$empty_trash_url = wp_nonce_url(
					admin_url( 'admin.php?page=ffc-short-urls&ffc_action=empty_trash' ),
					'ffc_short_url_empty_trash'
				);
				?>
				<div class="ffc-shorturl-empty-trash">
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label'    => __( 'Empty Trash', 'ffcertificate' ),
							'category' => 'danger',
							'icon'     => 'delete',
							'href'     => $empty_trash_url,
							'attrs'    => array(
								'data-confirm' => __( 'Are you sure you want to permanently delete all items in the trash?', 'ffcertificate' ),
							),
						)
					);
					?>
				</div>
			<?php endif; ?>

			<!-- Table -->
			<table class="wp-list-table widefat fixed striped ffc-shorturl-table">
				<thead>
					<tr>
						<th class="column-title"><?php esc_html_e( 'Title', 'ffcertificate' ); ?></th>
						<th class="column-shorturl"><?php esc_html_e( 'Short URL', 'ffcertificate' ); ?></th>
						<th class="column-dest"><?php esc_html_e( 'Destination', 'ffcertificate' ); ?></th>
						<th class="column-clicks">
							<?php
							$clicks_url = add_query_arg(
								array(
									'page'    => 'ffc-short-urls',
									'orderby' => 'click_count',
									'order'   => ( 'click_count' === $orderby && 'DESC' === $order ) ? 'asc' : 'desc',
									's'       => $search,
									'status'  => $status,
								),
								admin_url( 'admin.php' )
							);
							?>
							<a href="<?php echo esc_url( $clicks_url ); ?>"><?php esc_html_e( 'Clicks', 'ffcertificate' ); ?></a>
						</th>
						<th class="column-status"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
						<th class="column-actions"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No short URLs found.', 'ffcertificate' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $items as $item ) : ?>
							<?php
							$short_url  = $this->service->get_short_url( $item['short_code'] );
							$is_trashed = 'trashed' === $item['status'];
							$is_manual  = empty( $item['post_id'] );
							// Post-linked rows follow the page permalink (#888).
							$dest_url = \FreeFormCertificate\UrlShortener\UrlShortenerService::resolve_target( $item );

							if ( $is_trashed ) {
								$status_css_class = 'ffc-shorturl-status ffc-shorturl-status-trashed';
								$status_label     = __( 'Trash', 'ffcertificate' );
							} elseif ( 'active' === $item['status'] ) {
								$status_css_class = 'ffc-shorturl-status ffc-shorturl-status-active';
								$status_label     = __( 'Active', 'ffcertificate' );
							} else {
								$status_css_class = 'ffc-shorturl-status ffc-shorturl-status-disabled';
								$status_label     = __( 'Disabled', 'ffcertificate' );
							}
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $item['title'] ? $item['title'] : '(' . __( 'no title', 'ffcertificate' ) . ')' ); ?></strong>
									<?php if ( $item['post_id'] ) : ?>
										<br><small><?php echo esc_html( get_the_title( (int) $item['post_id'] ) ); ?></small>
									<?php endif; ?>
								</td>
								<td>
									<code class="ffc-shorturl-code" title="<?php esc_attr_e( 'Click to copy', 'ffcertificate' ); ?>" data-url="<?php echo esc_attr( $short_url ); ?>">
										<?php echo esc_html( $short_url ); ?>
									</code>
								</td>
								<td>
									<a href="<?php echo esc_url( $dest_url ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $dest_url ); ?>">
										<?php echo esc_html( \FreeFormCertificate\Core\Utils::truncate( $dest_url, 50, '...' ) ); ?>
									</a>
									<?php if ( ! $is_manual ) : ?>
										<br><small class="ffc-shorturl-dest-note"><?php esc_html_e( 'follows the page', 'ffcertificate' ); ?></small>
									<?php endif; ?>
								</td>
								<td><strong><?php echo esc_html( number_format_i18n( (int) $item['click_count'] ) ); ?></strong></td>
								<?php // The Status badge follows the row's toggle once its save is confirmed (#1904). ?>
								<td><span id="ffc-short-url-status-<?php echo esc_attr( (string) $item['id'] ); ?>" class="<?php echo esc_attr( $status_css_class ); ?>" data-ffc-status-on-class="ffc-shorturl-status-active" data-ffc-status-off-class="ffc-shorturl-status-disabled"><?php echo esc_html( $status_label ); ?></span></td>
								<td>
									<?php if ( $is_trashed ) : ?>
										<?php
										$restore_url = wp_nonce_url(
											admin_url( 'admin.php?page=ffc-short-urls&ffc_action=restore&id=' . $item['id'] ),
											'ffc_short_url_restore_' . $item['id']
										);
										$delete_url  = wp_nonce_url(
											admin_url( 'admin.php?page=ffc-short-urls&ffc_action=delete&id=' . $item['id'] ),
											'ffc_short_url_delete_' . $item['id']
										);
										?>
										<?php
										\FreeFormCertificate\Admin\AdminUI::render_button(
											array(
												'label'    => __( 'Restore', 'ffcertificate' ),
												'category' => 'caution',
												'icon'     => 'restore',
												'href'     => $restore_url,
											)
										);
										\FreeFormCertificate\Admin\AdminUI::render_button(
											array(
												'label'    => __( 'Delete Permanently', 'ffcertificate' ),
												'category' => 'danger',
												'icon'     => 'delete',
												'href'     => $delete_url,
												'attrs'    => array(
													'data-confirm' => __( 'Delete permanently?', 'ffcertificate' ),
												),
											)
										);
										?>
									<?php else : ?>
										<?php
										$trash_url = wp_nonce_url(
											admin_url( 'admin.php?page=ffc-short-urls&ffc_action=trash&id=' . $item['id'] ),
											'ffc_short_url_trash_' . $item['id']
										);
										?>
										<?php
										// On/off is a state, not an action: the toggle saves the link's
										// status the moment it flips (#1904).
										\FreeFormCertificate\Admin\AdminUI::render_toggle(
											array(
												'name'    => 'ffc_short_url_active_' . $item['id'],
												'id'      => 'ffc-short-url-active-' . $item['id'],
												'checked' => 'active' === $item['status'],
												'label'   => __( 'Active', 'ffcertificate' ),
												'aria_label' => sprintf(
												/* translators: %s: short URL */
													__( 'Active: %s', 'ffcertificate' ),
													$short_url
												),
												'data'    => array_merge(
													\FreeFormCertificate\Core\RecordAutosave::attrs( 'short_url', (int) $item['id'], 'status' ),
													array(
														'ffc-status-target' => 'ffc-short-url-status-' . $item['id'],
														'ffc-status-on'     => __( 'Active', 'ffcertificate' ),
														'ffc-status-off'    => __( 'Disabled', 'ffcertificate' ),
													)
												),
											)
										);
										if ( $is_manual ) {
											\FreeFormCertificate\Admin\AdminUI::render_button(
												array(
													'label' => __( 'Edit', 'ffcertificate' ),
													'icon' => 'edit',
													'class' => 'ffc-edit-shorturl',
													'attrs' => array(
														'data-id'     => (string) $item['id'],
														'data-target' => $item['target_url'],
														'data-title'  => (string) $item['title'],
													),
												)
											);
										}
										\FreeFormCertificate\Admin\AdminUI::render_button(
											array(
												'label' => 'QR',
												'icon'  => 'qr',
												'class' => 'ffc-show-qr-modal',
												'attrs' => array(
													'data-code'  => $item['short_code'],
													'data-url'   => $short_url,
													'data-title' => $item['title'] ? $item['title'] : $item['short_code'],
												),
											)
										);
										\FreeFormCertificate\Admin\AdminUI::render_button(
											array(
												'label'    => __( 'Trash', 'ffcertificate' ),
												'category' => 'danger',
												'icon'     => 'delete',
												'href'     => $trash_url,
											)
										);
										?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'total'     => $total_pages,
									'current'   => $page,
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>

			<!-- QR Code Modal Overlay -->
			<div id="ffc-qr-modal" class="ffc-qr-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ffc-qr-modal-title">
				<div class="ffc-qr-modal__backdrop"></div>
				<div class="ffc-qr-modal__content">
					<?php
					\FreeFormCertificate\Core\Button::render(
						array(
							'label'     => __( 'Close', 'ffcertificate' ),
							'category'  => 'neutral',
							'icon'      => 'cross',
							'icon_only' => true,
							'class'     => 'ffc-qr-modal__close',
						)
					);
					?>
					<h2 id="ffc-qr-modal-title" class="ffc-qr-modal__title"></h2>
					<p class="ffc-qr-modal__url"></p>
					<div class="ffc-qr-modal__preview">
						<div class="ffc-qr-modal__spinner"><span class="spinner is-active"></span></div>
						<img class="ffc-qr-modal__img" src="" alt="<?php esc_attr_e( 'QR Code', 'ffcertificate' ); ?>" style="display:none;" />
					</div>
					<div class="ffc-qr-modal__actions">
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_button(
							array(
								'label' => __( 'Copy URL', 'ffcertificate' ),
								'icon'  => 'copy',
								'class' => 'ffc-copy-shorturl',
								'attrs' => array(
									'data-url' => '',
								),
							)
						);
						\FreeFormCertificate\Admin\AdminUI::render_button(
							array(
								'label' => 'PNG',
								'icon'  => 'download',
								'class' => 'ffc-download-qr',
								'attrs' => array(
									'data-format' => 'png',
									'data-code'   => '',
								),
							)
						);
						\FreeFormCertificate\Admin\AdminUI::render_button(
							array(
								'label' => 'SVG',
								'icon'  => 'download',
								'class' => 'ffc-download-qr',
								'attrs' => array(
									'data-format' => 'svg',
									'data-code'   => '',
								),
							)
						);
						?>
					</div>
				</div>
			</div>

			<!-- Edit Short URL Modal (manual short URLs only) -->
			<div id="ffc-edit-modal" class="ffc-qr-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ffc-edit-modal-title">
				<div class="ffc-qr-modal__backdrop"></div>
				<div class="ffc-qr-modal__content">
					<?php
					\FreeFormCertificate\Core\Button::render(
						array(
							'label'     => __( 'Close', 'ffcertificate' ),
							'category'  => 'neutral',
							'icon'      => 'cross',
							'icon_only' => true,
							'class'     => 'ffc-qr-modal__close',
						)
					);
					?>
					<h2 id="ffc-edit-modal-title" class="ffc-qr-modal__title"><?php esc_html_e( 'Edit Short URL', 'ffcertificate' ); ?></h2>
					<form id="ffc-edit-short-url">
						<?php wp_nonce_field( 'ffc_short_url_nonce', 'ffc_edit_short_url_nonce' ); ?>
						<input type="hidden" name="id" value="" />
						<div>
							<label for="ffc-edit-target"><strong><?php esc_html_e( 'Destination URL', 'ffcertificate' ); ?></strong></label><br>
							<input type="url" id="ffc-edit-target" name="target_url" class="widefat" required />
						</div>
						<div>
							<label for="ffc-edit-title"><strong><?php esc_html_e( 'Title (optional)', 'ffcertificate' ); ?></strong></label><br>
							<input type="text" id="ffc-edit-title" name="title" class="widefat" />
						</div>
						<div class="ffc-qr-modal__actions">
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label'    => __( 'Save', 'ffcertificate' ),
									'category' => 'primary',
									'icon'     => 'save',
									'type'     => 'submit',
								)
							);
							?>
						</div>
						<div id="ffc-edit-short-url-result"></div>
					</form>
				</div>
			</div>
		</div>
		<?php
