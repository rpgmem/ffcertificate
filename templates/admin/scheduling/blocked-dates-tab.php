<?php
/**
 * Template: a personal calendar's Blocked dates tab (#2244).
 *
 * In-scope: $calendar (the calendar row) and $blocks (its full-day and
 * time-range blocks, in date order), from CalendarBlockedDates::render().
 *
 * The add fields carry no `name` the calendar save reads and no
 * `required`, so updating the calendar ignores them; the editor script
 * posts them to `ffc_calendar_block_add` on its own.
 *
 * @package FreeFormCertificate\SelfScheduling
 */

declare(strict_types=1);

use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\SelfScheduling\CalendarBlockedDates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ffc-blocked-dates" data-post-id="<?php echo esc_attr( (string) (int) $calendar['post_id'] ); ?>">
	<p class="description"><?php esc_html_e( 'Close whole days or a time range on one date. Nobody can book what is blocked, and adding a block that reaches open appointments asks first, then cancels them. Global holidays apply on top of these.', 'ffcertificate' ); ?></p>

	<?php if ( array() === $blocks ) : ?>
		<p class="ffc-blocked-dates-empty"><?php esc_html_e( 'No blocked dates.', 'ffcertificate' ); ?></p>
	<?php else : ?>
		<table class="widefat striped ffc-blocked-dates-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Blocked', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reason', 'ffcertificate' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $blocks as $ffc_block ) : ?>
					<tr>
						<td><?php echo esc_html( CalendarBlockedDates::describe( $ffc_block ) ); ?></td>
						<td><?php echo esc_html( (string) ( $ffc_block['reason'] ?? '' ) ); ?></td>
						<td class="ffc-blocked-dates-actions">
							<?php
							AdminUI::render_button(
								array(
									'label'     => __( 'Remove block', 'ffcertificate' ),
									'category'  => 'danger',
									'icon'      => 'cross',
									'icon_only' => true,
									'class'     => 'ffc-block-delete',
									'attrs'     => array( 'data-block-id' => (string) (int) $ffc_block['id'] ),
								)
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<fieldset class="ffc-blocked-dates-add">
		<legend><?php esc_html_e( 'Add a block', 'ffcertificate' ); ?></legend>
		<?php CalendarBlockedDates::render_type_control(); ?>
		<div class="ffc-blocked-dates-fields">
			<label class="ffc-blocked-dates-field">
				<span><?php esc_html_e( 'Date', 'ffcertificate' ); ?></span>
				<input type="date" id="ffc-block-start-date">
			</label>
			<label class="ffc-blocked-dates-field ffc-block-days">
				<span><?php esc_html_e( 'Until (optional)', 'ffcertificate' ); ?></span>
				<input type="date" id="ffc-block-end-date">
			</label>
			<label class="ffc-blocked-dates-field ffc-block-times" hidden>
				<span><?php esc_html_e( 'From', 'ffcertificate' ); ?></span>
				<input type="time" id="ffc-block-start-time">
			</label>
			<label class="ffc-blocked-dates-field ffc-block-times" hidden>
				<span><?php esc_html_e( 'To', 'ffcertificate' ); ?></span>
				<input type="time" id="ffc-block-end-time">
			</label>
			<label class="ffc-blocked-dates-field ffc-blocked-dates-reason">
				<span><?php esc_html_e( 'Reason (optional, shown in the cancellation e-mail)', 'ffcertificate' ); ?></span>
				<input type="text" id="ffc-block-reason" maxlength="255">
			</label>
		</div>
		<p>
			<?php
			AdminUI::render_button(
				array(
					'label'    => __( 'Add block', 'ffcertificate' ),
					'category' => 'create',
					'icon'     => 'plus',
					'id'       => 'ffc-block-add',
				)
			);
			?>
		</p>
	</fieldset>
</div>
