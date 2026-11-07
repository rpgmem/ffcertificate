<?php
/**
 * Template: Recruitment admin page — the New adjutancy dialog (#2486).
 *
 * @var string $default_color Default badge color.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.12.0
 */

use FreeFormCertificate\Recruitment\RecruitmentAdminPageRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

RecruitmentAdminPageRenderer::open_dialog(
	RecruitmentAdminPageRenderer::ADJUTANCY_DIALOG,
	__( 'New adjutancy', 'ffcertificate' ),
	'ffc-create-adjutancy',
	'adjutancies'
);
RecruitmentAdminPageRenderer::dialog_field(
	__( 'Slug', 'ffcertificate' ),
	'<input id="ffc-adj-slug" name="slug" type="text" class="regular-text" required>',
	'ffc-adj-slug'
);
RecruitmentAdminPageRenderer::dialog_field(
	__( 'Name', 'ffcertificate' ),
	'<input id="ffc-adj-name" name="name" type="text" class="regular-text" required>',
	'ffc-adj-name'
);
RecruitmentAdminPageRenderer::dialog_field(
	__( 'Badge color', 'ffcertificate' ),
	'<input id="ffc-adj-color" name="color" type="color" value="' . esc_attr( $default_color ) . '">',
	'ffc-adj-color',
	__( 'Background color for this adjutancy badge on the public shortcode. Accepts #RGB / #RRGGBB / #RRGGBBAA. Bad values silently fall back to the default.', 'ffcertificate' )
);
RecruitmentAdminPageRenderer::close_dialog( __( 'Create', 'ffcertificate' ) );
