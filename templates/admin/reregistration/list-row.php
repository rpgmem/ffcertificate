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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including renderer method scope).
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
							<span class="ffc-color-dot" style="background:<?php echo esc_attr( $aud->color ); ?>"></span>
							<?php echo esc_html( $aud->name ); ?>
						</span>
					<?php endforeach; ?>
				<?php endif; ?>
			</td>
			<td class="column-status">
				<span class="ffc-status-badge ffc-status-<?php echo esc_attr( $item->status ); ?>">
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
				<?php echo $item->auto_approve ? '<span class="dashicons dashicons-yes-alt ffc-rereg-yes"></span>' : '<span class="dashicons dashicons-minus ffc-rereg-muted"></span>'; ?>
			</td>
			<td class="column-actions">
				<?php if ( $can_edit ) : ?>
				<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'ffcertificate' ); ?></a> |
				<?php endif; ?>
				<a href="<?php echo esc_url( $subs_url ); ?>"><?php esc_html_e( 'Submissions', 'ffcertificate' ); ?></a>
				<?php if ( $can_edit ) : ?>
				|
				<a href="<?php echo esc_url( $delete_url ); ?>" class="delete-link"
					onclick="return confirm(ffcReregistrationAdmin?.strings?.confirmDelete || 'Delete?');">
					<?php esc_html_e( 'Delete', 'ffcertificate' ); ?>
				</a>
				<?php endif; ?>
			</td>
		</tr>
		<?php
