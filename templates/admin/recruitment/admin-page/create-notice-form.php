<?php
/**
 * Template: Recruitment admin page — Create-notice form.
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

echo '<h3>' . esc_html__( 'Create new notice', 'ffcertificate' ) . '</h3>';
echo '<form id="ffc-create-notice" method="post" data-ffc-create-endpoint="notices">';
echo '<table class="form-table"><tbody>';
echo '<tr><th><label for="ffc-notice-code">' . esc_html__( 'Code', 'ffcertificate' ) . '</label></th>';
echo '<td><input id="ffc-notice-code" name="code" type="text" class="regular-text" required></td></tr>';
echo '<tr><th><label for="ffc-notice-name">' . esc_html__( 'Name', 'ffcertificate' ) . '</label></th>';
echo '<td><input id="ffc-notice-name" name="name" type="text" class="regular-text" required></td></tr>';
echo '</tbody></table>';
echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create', 'ffcertificate' ) . '</button></p>';
echo '</form>';
