<?php
/**
 * Template: Recruitment admin page — Create-reason form.
 *
 * Extracted verbatim from the matching RecruitmentAdminPageRenderer method
 * (rpgmem/ffcertificate#563 coverage extraction); markup byte-identical.
 *
 * @var string $default_color Default reason badge color.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<h3>' . esc_html__( 'Create new reason', 'ffcertificate' ) . '</h3>';
echo '<form id="ffc-create-reason" method="post" data-ffc-create-endpoint="reasons">';
echo '<table class="form-table"><tbody>';
echo '<tr><th><label for="ffc-reason-slug">' . esc_html__( 'Slug', 'ffcertificate' ) . '</label></th>';
echo '<td><input id="ffc-reason-slug" name="slug" type="text" class="regular-text" required></td></tr>';
echo '<tr><th><label for="ffc-reason-label">' . esc_html__( 'Label', 'ffcertificate' ) . '</label></th>';
echo '<td><input id="ffc-reason-label" name="label" type="text" class="regular-text" required></td></tr>';
echo '<tr><th><label for="ffc-reason-color">' . esc_html__( 'Badge color', 'ffcertificate' ) . '</label></th>';
echo '<td><input id="ffc-reason-color" name="color" type="color" value="' . esc_attr( $default_color ) . '">';
echo '<p class="description">' . esc_html__( 'Background color for the reason badge when surfaced. Accepts #RGB / #RRGGBB / #RRGGBBAA.', 'ffcertificate' ) . '</p>';
echo '</td></tr>';

$applies_options = array(
	'denied'         => __( 'Denied', 'ffcertificate' ),
	'granted'        => __( 'Granted', 'ffcertificate' ),
	'appeal_denied'  => __( 'Appeal denied', 'ffcertificate' ),
	'appeal_granted' => __( 'Appeal granted', 'ffcertificate' ),
);
echo '<tr><th>' . esc_html__( 'Applies to', 'ffcertificate' ) . '</th><td>';
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
echo '</td></tr>';

echo '</tbody></table>';
echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create', 'ffcertificate' ) . '</button></p>';
echo '</form>';
