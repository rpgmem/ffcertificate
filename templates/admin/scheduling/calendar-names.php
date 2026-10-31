<?php
/**
 * Template: a calendar's own names (#2223), shared by the personal calendar
 * editor and the audience calendar settings.
 *
 * In-scope: $ffc_names_base (string, the input name prefix), $ffc_names
 * (array, the calendar's names from CalendarAppearance::clean_override()),
 * $ffc_names_booking (bool, whether the booking badge's two names are
 * offered here; the audience calendar keeps its own booking label fields).
 *
 * An empty field inherits the general name, which its placeholder shows.
 *
 * @package FreeFormCertificate
 */

declare(strict_types=1);

use FreeFormCertificate\Scheduling\CalendarAppearance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_names_cells = array(
	CalendarAppearance::AVAILABLE => array( CalendarAppearance::AVAILABLE ),
	CalendarAppearance::BOOKED    => $ffc_names_booking ? array( CalendarAppearance::BOOKING_SINGULAR, CalendarAppearance::BOOKING_PLURAL ) : array(),
	'holiday'                     => array( 'holiday' ),
	'optional'                    => array( 'optional' ),
	CalendarAppearance::CLOSED    => array( CalendarAppearance::CLOSED ),
);
?>
<p class="description">
	<?php esc_html_e( 'This calendar\'s own names. An empty field uses the general name, shown in it, set in Calendar Colors and Labels on the General tab of the Scheduling settings. Colors are general only.', 'ffcertificate' ); ?>
</p>
<table class="widefat striped ffc-calendar-names-table">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'State', 'ffcertificate' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Legend name', 'ffcertificate' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Day cell name', 'ffcertificate' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( CalendarAppearance::states() as $ffc_names_state ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( CalendarAppearance::default_legend( $ffc_names_state ) ); ?></th>
				<td>
					<input type="text" maxlength="40"
							name="<?php echo esc_attr( $ffc_names_base . '[legend][' . $ffc_names_state . ']' ); ?>"
							aria-label="<?php echo esc_attr( sprintf( /* translators: %s: calendar state, such as Available */ __( 'Legend name: %s', 'ffcertificate' ), CalendarAppearance::default_legend( $ffc_names_state ) ) ); ?>"
							value="<?php echo esc_attr( $ffc_names['legend'][ $ffc_names_state ] ?? '' ); ?>"
							placeholder="<?php echo esc_attr( CalendarAppearance::legend( $ffc_names_state ) ); ?>">
				</td>
				<td>
					<?php if ( empty( $ffc_names_cells[ $ffc_names_state ] ) ) : ?>
						<span class="description"><?php esc_html_e( 'Set in the Booking Badge Label field', 'ffcertificate' ); ?></span>
					<?php endif; ?>
					<?php foreach ( $ffc_names_cells[ $ffc_names_state ] as $ffc_names_cell ) : ?>
						<?php $ffc_names_general = CalendarAppearance::cell( $ffc_names_cell ); ?>
						<input type="text" maxlength="40" class="ffc-calendar-names-cell"
								name="<?php echo esc_attr( $ffc_names_base . '[cell][' . $ffc_names_cell . ']' ); ?>"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: calendar state, such as Holiday */ __( 'Day cell name: %s', 'ffcertificate' ), CalendarAppearance::default_legend( $ffc_names_state ) ) ); ?>"
								value="<?php echo esc_attr( $ffc_names['cell'][ $ffc_names_cell ] ?? '' ); ?>"
								placeholder="<?php echo esc_attr( '' !== $ffc_names_general ? $ffc_names_general : __( 'No text', 'ffcertificate' ) ); ?>">
					<?php endforeach; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php
\FreeFormCertificate\Admin\AdminUI::render_segmented(
	array(
		'name'         => $ffc_names_base . '[show_closed_label]',
		'id'           => $ffc_names_base . '_show_closed_label',
		/* translators: %s: the closed day's cell name, such as Closed */
		'legend'       => sprintf( __( 'Show "%s" in closed day cells', 'ffcertificate' ), CalendarAppearance::cell( CalendarAppearance::CLOSED, $ffc_names ) ),
		'options'      => array(
			CalendarAppearance::SHOW_INHERIT => __( 'General', 'ffcertificate' ),
			CalendarAppearance::SHOW         => __( 'Show', 'ffcertificate' ),
			CalendarAppearance::HIDE         => __( 'Hide', 'ffcertificate' ),
		),
		'descriptions' => array(
			CalendarAppearance::SHOW_INHERIT => CalendarAppearance::show_closed_label()
				? __( 'Follows the general switch, which shows the name.', 'ffcertificate' )
				: __( 'Follows the general switch, which hides the name.', 'ffcertificate' ),
			CalendarAppearance::SHOW         => __( 'This calendar shows the name on closed days.', 'ffcertificate' ),
			CalendarAppearance::HIDE         => __( 'This calendar leaves closed days without text.', 'ffcertificate' ),
		),
		'value'        => $ffc_names['show_closed_label'] ?? CalendarAppearance::SHOW_INHERIT,
		'size'         => 'sm',
	)
);
