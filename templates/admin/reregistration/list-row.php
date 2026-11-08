<?php
/**
 * Template: Reregistration admin — Campaign list row.
 *
 * Extracted verbatim from the matching ReregistrationAdminRenderer method
 * (rpgmem/ffcertificate#563 coverage hygiene); markup byte-identical. The
 * renderer prepares the locals and includes this file (method params + locals
 * + self:: sibling renderers resolve in the including method scope).
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.12.0
 */

use FreeFormCertificate\Reregistration\ReregistrationRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		<tr>
			<td class="column-title">
				<strong><a href="<?php echo esc_url( $title_url ); ?>"><?php echo esc_html( $item->title ); ?></a></strong>
			</td>
			<td class="column-audience">
				<?php if ( empty( $audiences ) ) : ?>
					—
				<?php else : ?>
					<?php foreach ( $audiences as $aud ) : ?>
						<span class="ffc-audience-badge">
							<span class="ffc-color-dot" style="--ffc-color:<?php echo esc_attr( $aud->color ); ?>"></span>
							<?php echo esc_html( $aud->name ); ?>
						</span>
					<?php endforeach; ?>
				<?php endif; ?>
			</td>
			<td class="column-status">
				<span class="ffc-reregistration-status-badge ffc-reregistration-status-<?php echo esc_attr( $item->status ); ?>">
					<?php echo esc_html( ReregistrationRepository::get_status_label( $item->status ) ); ?>
				</span>
			</td>
			<td class="column-period"><?php echo esc_html( $start . ' — ' . $end ); ?></td>
			<td class="column-submissions">
				<a href="<?php echo esc_url( $subs_url ); ?>">
					<?php
					printf(
						/* translators: 1: approved count 2: total count */
						esc_html__( '%1$d / %2$d', 'ffcertificate' ),
						absint( $stats['approved'] ),
						absint( $stats['total'] )
					);
					?>
				</a>
			</td>
			<td class="column-auto">
				<?php echo $item->auto_approve ? '<span class="ffc-icon-success ffc-rereg-yes" aria-hidden="true"></span>' : '<span class="ffc-icon-minus ffc-rereg-muted" aria-hidden="true"></span>'; ?>
			</td>
			<td class="column-actions">
				<div class="ffc-row-buttons">
					<?php
					// The row actions are buttons (#2498). A campaign has no on/off
					// switch: its status has four states, not two.
					if ( $can_edit ) {
						\FreeFormCertificate\Admin\AdminUI::render_button(
							array(
								'label' => __( 'Edit', 'ffcertificate' ),
								'icon'  => 'edit',
								'href'  => $edit_url,
							)
						);
					}
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label' => __( 'Submissions', 'ffcertificate' ),
							'icon'  => 'list',
							'href'  => $subs_url,
						)
					);
					if ( $can_edit ) {
						\FreeFormCertificate\Admin\AdminUI::render_button(
							array(
								'label'    => __( 'Delete', 'ffcertificate' ),
								'category' => 'danger',
								'icon'     => 'delete',
								'href'     => $delete_url,
								'attrs'    => array( 'data-confirm' => __( 'Are you sure you want to delete this reregistration? This will also delete all submissions.', 'ffcertificate' ) ),
							)
						);
					}
					?>
				</div>
			</td>
		</tr>
		<?php
