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
 * @var array<string,bool> $columns     Column visibility map.
 * @var bool               $show_date   Whether the date/time columns render.
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
echo '<table class="ffc-recruitment-table"><thead><tr>';
if ( $columns['rank'] ) {
	echo '<th>' . esc_html__( 'Rank', 'ffcertificate' ) . '</th>';
}
if ( $columns['name'] ) {
	echo '<th>' . esc_html__( 'Name', 'ffcertificate' ) . '</th>';
}
if ( $columns['adjutancy'] ) {
	echo '<th>' . esc_html__( 'Adjutancy', 'ffcertificate' ) . '</th>';
}
if ( $columns['cpf_masked'] ) {
	echo '<th>' . esc_html__( 'CPF', 'ffcertificate' ) . '</th>';
}
if ( $columns['rf_masked'] ) {
	echo '<th>' . esc_html__( 'RF', 'ffcertificate' ) . '</th>';
}
if ( $columns['email_masked'] ) {
	echo '<th>' . esc_html__( 'E-mail', 'ffcertificate' ) . '</th>';
}
if ( $columns['score'] ) {
	echo '<th>' . esc_html__( 'Score', 'ffcertificate' ) . '</th>';
}
if ( $columns['time_points'] ) {
	echo '<th>' . esc_html__( 'Time points', 'ffcertificate' ) . '</th>';
}
if ( $columns['hab_emebs'] ) {
	echo '<th>' . esc_html__( 'HAB. EMEBs', 'ffcertificate' ) . '</th>';
}
if ( $columns['status'] ) {
	echo '<th>' . esc_html__( 'Status', 'ffcertificate' ) . '</th>';
}
if ( $columns['pcd_badge'] ) {
	echo '<th>' . esc_html__( 'Subscription', 'ffcertificate' ) . '</th>';
}
if ( $show_date && $columns['date_to_assume'] ) {
	echo '<th>' . esc_html__( 'Date to assume', 'ffcertificate' ) . '</th>';
}
if ( $show_date && $columns['time_to_assume'] ) {
	echo '<th>' . esc_html__( 'Time', 'ffcertificate' ) . '</th>';
}
echo '</tr></thead><tbody>';
