<?php
/**
 * Template: Recruitment admin page — Create-adjutancy form.
 *
 * Extracted verbatim from the matching RecruitmentAdminPageRenderer method
 * (rpgmem/ffcertificate#563 coverage extraction); markup byte-identical.
 *
 * @var string $default_color Default badge color.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<h3>' . esc_html__( 'Create new adjutancy', 'ffcertificate' ) . '</h3>';
echo '<form id="ffc-create-adjutancy" method="post" data-ffc-create-endpoint="adjutancies">';
echo '<table class="form-table"><tbody>';
echo '<tr><th><label for="ffc-adj-slug">' . esc_html__( 'Slug', 'ffcertificate' ) . '</label></th>';
echo '<td><input id="ffc-adj-slug" name="slug" type="text" class="regular-text" required></td></tr>';
echo '<tr><th><label for="ffc-adj-name">' . esc_html__( 'Name', 'ffcertificate' ) . '</label></th>';
echo '<td><input id="ffc-adj-name" name="name" type="text" class="regular-text" required></td></tr>';
echo '<tr><th><label for="ffc-adj-color">' . esc_html__( 'Badge color', 'ffcertificate' ) . '</label></th>';
echo '<td><input id="ffc-adj-color" name="color" type="color" value="' . esc_attr( $default_color ) . '">';
echo '<p class="description">' . esc_html__( 'Background color for this adjutancy badge on the public shortcode. Accepts #RGB / #RRGGBB / #RRGGBBAA. Bad values silently fall back to the default.', 'ffcertificate' ) . '</p>';
echo '</td></tr>';
echo '</tbody></table>';
echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create', 'ffcertificate' ) . '</button></p>';
echo '</form>';
