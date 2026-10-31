<?php
/**
 * Template: Public recruitment listing — section open (heading + table head).
 *
 * Extracted verbatim from RecruitmentPublicShortcodeRenderer::render_section();
 * markup byte-identical (echo replaces the prior `$html .=` concatenation,
 * captured back into $html via ob_start()/ob_get_clean() in the caller).
 *
 * @var string             $heading     Section title.
 * @var string             $count_label Post-filter count label.
 * @var array<string,string> $labels    Header of every visible column, in order.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '<section class="ffc-recruitment-section">';
echo '<h3 class="ffc-recruitment-section-heading">'
	. '<span class="ffc-recruitment-section-title">' . esc_html( $heading ) . '</span>'
	. '<span class="ffc-recruitment-section-count">' . esc_html( $count_label ) . '</span>'
	. '</h3>';
// A caption names the table for a screen reader, and every header cell
// is a column header (#2245).
echo '<table class="ffc-recruitment-table"><caption class="ffc-recruitment-caption">' . esc_html( $heading . ' — ' . $count_label ) . '</caption><thead><tr>';
foreach ( $labels as $label ) {
	echo '<th scope="col">' . esc_html( $label ) . '</th>';
}
echo '</tr></thead><tbody>';
