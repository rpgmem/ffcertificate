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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (the include runs in the including controller method's scope).
?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Short URLs', 'ffcertificate' ); ?></h1>
			<?php if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_settings' ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ffc_form&page=ffc-settings&tab=url_shortener' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Settings', 'ffcertificate' ); ?>
				</a>
			<?php endif; ?>
			<?php if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_export_url_shortener' ) ) : ?>
				<?php // Batched CSV export (#772): the button drives the unified ffc_export_* dispatcher via window.FFCBatchedExport; carries the current search/status filters. ?>
				<button
					type="button"
					id="ffc-shorturl-export-btn"
					class="page-title-action"
					data-s="<?php echo esc_attr( $search ); ?>"
					data-status="<?php echo esc_attr( $status ); ?>"
				><?php esc_html_e( 'Export CSV', 'ffcertificate' ); ?></button>
				<span id="ffc-shorturl-export-progress" class="ffc-shorturl-export-progress" style="display:none;margin-left:8px;"></span>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php if ( 'trashed' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Short URL moved to Trash.', 'ffcertificate' ); ?></p></div>
			<?php elseif ( 'restored' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Short URL restored.', 'ffcertificate' ); ?></p></div>
			<?php elseif ( 'deleted' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Short URL permanently deleted.', 'ffcertificate' ); ?></p></div>
			<?php elseif ( 'emptied' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Trash emptied.', 'ffcertificate' ); ?></p></div>
			<?php elseif ( 'toggled' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Status updated.', 'ffcertificate' ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'trashed' !== $status ) : ?>
			<!-- Stats -->
			<div class="ffc-shorturl-stats">
				<div>
					<strong><?php echo esc_html( number_format_i18n( $stats['total_links'] ) ); ?></strong>
					<span class="ffc-stat-label"><?php esc_html_e( 'Total Links', 'ffcertificate' ); ?></span>
				</div>
				<div>
					<strong><?php echo esc_html( number_format_i18n( $stats['active_links'] ) ); ?></strong>
					<span class="ffc-stat-label"><?php esc_html_e( 'Active', 'ffcertificate' ); ?></span>
				</div>
				<div>
					<strong><?php echo esc_html( number_format_i18n( $stats['total_clicks'] ) ); ?></strong>
					<span class="ffc-stat-label"><?php esc_html_e( 'Total Clicks', 'ffcertificate' ); ?></span>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( 'trashed' !== $status ) : ?>
			<!-- Create New -->
			<div class="ffc-shorturl-create">
				<h3><?php esc_html_e( 'Create Short URL', 'ffcertificate' ); ?></h3>
				<form id="ffc-create-short-url">
					<?php wp_nonce_field( 'ffc_short_url_nonce', 'ffc_short_url_nonce' ); ?>
					<div>
						<label for="ffc-shorturl-target"><strong><?php esc_html_e( 'Destination URL', 'ffcertificate' ); ?></strong></label><br>
						<input type="url" id="ffc-shorturl-target" name="target_url" placeholder="https://example.com/long-page" required />
					</div>
					<div>
						<label for="ffc-shorturl-title"><strong><?php esc_html_e( 'Title (optional)', 'ffcertificate' ); ?></strong></label><br>
						<input type="text" id="ffc-shorturl-title" name="title" placeholder="<?php esc_attr_e( 'My Campaign', 'ffcertificate' ); ?>" />
					</div>
					<div>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create', 'ffcertificate' ); ?></button>
					</div>
					<div id="ffc-shorturl-result"></div>
				</form>
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
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ffcertificate' ); ?></button>
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
					<a href="<?php echo esc_url( $empty_trash_url ); ?>" class="button button-link-delete"
						onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to permanently delete all items in the trash?', 'ffcertificate' ); ?>');">
						<?php esc_html_e( 'Empty Trash', 'ffcertificate' ); ?>
					</a>
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
									<a href="<?php echo esc_url( $item['target_url'] ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $item['target_url'] ); ?>">
										<?php echo esc_html( \FreeFormCertificate\Core\Utils::truncate( $item['target_url'], 50, '...' ) ); ?>
									</a>
								</td>
								<td><strong><?php echo esc_html( number_format_i18n( (int) $item['click_count'] ) ); ?></strong></td>
								<td><span class="<?php echo esc_attr( $status_css_class ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
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
										<a href="<?php echo esc_url( $restore_url ); ?>" class="button button-small">
											<?php esc_html_e( 'Restore', 'ffcertificate' ); ?>
										</a>
										<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Delete permanently?', 'ffcertificate' ); ?>');">
											<?php esc_html_e( 'Delete Permanently', 'ffcertificate' ); ?>
										</a>
									<?php else : ?>
										<?php
										$toggle_url = wp_nonce_url(
											admin_url( 'admin.php?page=ffc-short-urls&ffc_action=toggle&id=' . $item['id'] ),
											'ffc_short_url_toggle_' . $item['id']
										);
										$trash_url  = wp_nonce_url(
											admin_url( 'admin.php?page=ffc-short-urls&ffc_action=trash&id=' . $item['id'] ),
											'ffc_short_url_trash_' . $item['id']
										);
										?>
										<button type="button" class="button button-small ffc-show-qr-modal"
												data-code="<?php echo esc_attr( $item['short_code'] ); ?>"
												data-url="<?php echo esc_attr( $short_url ); ?>"
												data-title="<?php echo esc_attr( $item['title'] ? $item['title'] : $item['short_code'] ); ?>">
											<span class="dashicons dashicons-screenoptions ffc-dashicon-sm-inline"></span>
											QR
										</button>
										<a href="<?php echo esc_url( $toggle_url ); ?>" class="button button-small">
											<?php echo 'active' === $item['status'] ? esc_html__( 'Disable', 'ffcertificate' ) : esc_html__( 'Enable', 'ffcertificate' ); ?>
										</a>
										<a href="<?php echo esc_url( $trash_url ); ?>" class="button button-small button-link-delete">
											<?php esc_html_e( 'Trash', 'ffcertificate' ); ?>
										</a>
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
					<button type="button" class="ffc-qr-modal__close" aria-label="<?php esc_attr_e( 'Close', 'ffcertificate' ); ?>">&times;</button>
					<h2 id="ffc-qr-modal-title" class="ffc-qr-modal__title"></h2>
					<p class="ffc-qr-modal__url"></p>
					<div class="ffc-qr-modal__preview">
						<div class="ffc-qr-modal__spinner"><span class="spinner is-active"></span></div>
						<img class="ffc-qr-modal__img" src="" alt="<?php esc_attr_e( 'QR Code', 'ffcertificate' ); ?>" style="display:none;" />
					</div>
					<div class="ffc-qr-modal__actions">
						<button type="button" class="button ffc-copy-shorturl" data-url="">
							<span class="dashicons dashicons-clipboard ffc-dashicon-valign"></span>
							<?php esc_html_e( 'Copy URL', 'ffcertificate' ); ?>
						</button>
						<button type="button" class="button ffc-download-qr" data-format="png" data-code="">
							<span class="dashicons dashicons-download ffc-dashicon-valign"></span>
							PNG
						</button>
						<button type="button" class="button ffc-download-qr" data-format="svg" data-code="">
							<span class="dashicons dashicons-download ffc-dashicon-valign"></span>
							SVG
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
