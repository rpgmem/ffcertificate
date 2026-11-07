<?php
/**
 * Template: Recruitment admin page — the New notice dialog (#2486).
 *
 * @var string $suggested Code suggested for the new notice, or ''.
 * @var string $latest    Code of the most recent notice, or ''.
 * @var string $open_url  The notice editor, with `__ID__` for the new id.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.12.0
 */

use FreeFormCertificate\Recruitment\RecruitmentAdminPageRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

RecruitmentAdminPageRenderer::open_dialog(
	RecruitmentAdminPageRenderer::NOTICE_DIALOG,
	__( 'New notice', 'ffcertificate' ),
	'ffc-create-notice',
	'notices',
	// "Create and open": the new draft opens in its editor, where the
	// adjutancies are attached and the CSV imported.
	array( 'data-ffc-create-open' => $open_url )
);
RecruitmentAdminPageRenderer::dialog_field(
	__( 'Code', 'ffcertificate' ),
	'<input id="ffc-notice-code" name="code" type="text" class="regular-text" required value="' . esc_attr( $suggested ) . '">',
	'ffc-notice-code',
	'' !== $suggested && '' !== $latest
		/* translators: %s: code of the most recent notice */
		? sprintf( __( 'Suggested from the latest notice, %s. You can change it.', 'ffcertificate' ), $latest )
		: ''
);
RecruitmentAdminPageRenderer::dialog_field(
	__( 'Name', 'ffcertificate' ),
	'<input id="ffc-notice-name" name="name" type="text" class="regular-text" required>',
	'ffc-notice-name'
);
RecruitmentAdminPageRenderer::close_dialog( __( 'Create and open', 'ffcertificate' ) );
