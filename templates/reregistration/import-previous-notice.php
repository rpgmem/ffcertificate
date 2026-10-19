<?php
/**
 * Template: the offer to import the last approved reregistration.
 *
 * The notice is only included when a source exists, so there is no conditional
 * here: the renderer is what decides. Nothing is fetched until the participant
 * clicks -- the button fires `ffc_import_previous_reregistration`.
 *
 * Expected in scope: $ffc_import_source_title.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php
$ffc_notice = \FreeFormCertificate\Core\Notice::render(
	'info',
	'history',
	array(
		sprintf(
			/* translators: %s: title of the previous approved campaign. */
			esc_html__( 'You have an approved reregistration from %s. Do you want to bring those answers into this form?', 'ffcertificate' ),
			'<strong>' . esc_html( $ffc_import_source_title ) . '</strong>'
		),
		esc_html__( 'Review every field afterwards — the data is from a previous cycle and may be out of date.', 'ffcertificate' ),
	),
	'',
	array(
		'role'    => 'status',
		'html'    => true,
		'hook'    => 'rereg-import',
		'actions' => \FreeFormCertificate\Core\Button::html(
			array(
				'label'    => __( 'Bring previous answers', 'ffcertificate' ),
				'category' => 'neutral',
				'icon'     => 'history',
				'class'    => 'ffc-rereg-import-btn',
			)
		)
			. '<span class="ffc-rereg-import-status" role="status"></span>',
	)
);
echo $ffc_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes every value.
