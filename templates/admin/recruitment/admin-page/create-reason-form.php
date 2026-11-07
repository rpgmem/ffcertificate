<?php
/**
 * Template: Recruitment admin page — the New reason dialog (#2486).
 *
 * @var string $default_color Default reason badge color.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.12.0
 */

use FreeFormCertificate\Recruitment\RecruitmentAdminPageRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

RecruitmentAdminPageRenderer::open_dialog(
	RecruitmentAdminPageRenderer::REASON_DIALOG,
	__( 'New reason', 'ffcertificate' ),
	'ffc-create-reason',
	'reasons'
);
RecruitmentAdminPageRenderer::dialog_field(
	__( 'Slug', 'ffcertificate' ),
	'<input id="ffc-reason-slug" name="slug" type="text" class="regular-text" required>',
	'ffc-reason-slug'
);
RecruitmentAdminPageRenderer::dialog_field(
	__( 'Label', 'ffcertificate' ),
	'<input id="ffc-reason-label" name="label" type="text" class="regular-text" required>',
	'ffc-reason-label'
);
RecruitmentAdminPageRenderer::dialog_field(
	__( 'Badge color', 'ffcertificate' ),
	'<input id="ffc-reason-color" name="color" type="color" value="' . esc_attr( $default_color ) . '">',
	'ffc-reason-color',
	__( 'Background color for the reason badge when surfaced. Accepts #RGB / #RRGGBB / #RRGGBBAA.', 'ffcertificate' )
);

$applies_options = array(
	'denied'         => __( 'Denied', 'ffcertificate' ),
	'granted'        => __( 'Granted', 'ffcertificate' ),
	'appeal_denied'  => __( 'Appeal denied', 'ffcertificate' ),
	'appeal_granted' => __( 'Appeal granted', 'ffcertificate' ),
);
echo '<fieldset class="ffc-create-dialog__field"><legend>' . esc_html__( 'Applies to', 'ffcertificate' ) . '</legend>';
echo '<div class="ffc-rec-flex-wrap">';
foreach ( $applies_options as $key => $label ) {
	$id_attr = 'ffc-reason-applies-' . $key;
	echo '<label for="' . esc_attr( $id_attr ) . '" class="ffc-rec-flex-center-6">';
	echo '<input id="' . esc_attr( $id_attr ) . '" type="checkbox" name="applies_to[]" value="' . esc_attr( $key ) . '">';
	echo esc_html( $label );
	echo '</label>';
}
echo '</div>';
echo '<p class="description">' . esc_html__( 'Leave all unchecked to make this reason applicable to every preliminary status.', 'ffcertificate' ) . '</p>';
echo '</fieldset>';
RecruitmentAdminPageRenderer::close_dialog( __( 'Create', 'ffcertificate' ) );
