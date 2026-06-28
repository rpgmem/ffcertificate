<?php
/**
 * Template: Recruitment admin page — REST endpoints pointer.
 *
 * Extracted verbatim from the matching RecruitmentAdminPageRenderer method
 * (rpgmem/ffcertificate#563 coverage extraction); markup byte-identical.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '<details class="ffc-rec-mt-1"><summary>' . esc_html__( 'Available REST endpoints', 'ffcertificate' ) . '</summary>';
echo '<pre class="ffc-rec-pre-block">'
	. esc_html(
		"GET    /wp-json/ffcertificate/v1/recruitment/notices\n"
		. "POST   /wp-json/ffcertificate/v1/recruitment/notices\n"
		. "PATCH  /wp-json/ffcertificate/v1/recruitment/notices/{id}\n"
		. "GET    /wp-json/ffcertificate/v1/recruitment/notices/{id}/classifications\n"
		. "POST   /wp-json/ffcertificate/v1/recruitment/notices/{id}/import\n"
		. "POST   /wp-json/ffcertificate/v1/recruitment/notices/{id}/promote-preview\n"
		. "POST   /wp-json/ffcertificate/v1/recruitment/classifications/{id}/call\n"
		. "POST   /wp-json/ffcertificate/v1/recruitment/classifications/bulk-call\n"
		. "PATCH  /wp-json/ffcertificate/v1/recruitment/classifications/{id}/status\n"
		. "DELETE /wp-json/ffcertificate/v1/recruitment/classifications/{id}\n"
		. "GET    /wp-json/ffcertificate/v1/recruitment/adjutancies\n"
		. "DELETE /wp-json/ffcertificate/v1/recruitment/adjutancies/{id}\n"
		. "GET    /wp-json/ffcertificate/v1/recruitment/candidates?cpf={digits}\n"
		. "GET    /wp-json/ffcertificate/v1/recruitment/candidates/{id}\n"
		. "PATCH  /wp-json/ffcertificate/v1/recruitment/candidates/{id}\n"
		. "DELETE /wp-json/ffcertificate/v1/recruitment/candidates/{id}\n"
		. "GET    /wp-json/ffcertificate/v1/recruitment/me/recruitment\n"
	)
	. '</pre>';
echo '<p>' . esc_html__( 'All admin endpoints require the ffc_manage_recruitment capability.', 'ffcertificate' ) . '</p>';
echo '</details>';
