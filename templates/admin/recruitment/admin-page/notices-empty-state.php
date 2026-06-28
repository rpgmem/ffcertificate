<?php
/**
 * Template: Recruitment admin page — Notices first-run empty state.
 *
 * Extracted verbatim from the matching RecruitmentAdminPageRenderer method
 * (rpgmem/ffcertificate#563 coverage extraction); markup byte-identical.
 *
 * @var string $adjutancies_url URL to the Adjutancies tab.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<div class="notice notice-info inline ffc-rec-welcome-notice">';
echo '<h3 class="ffc-rec-mt-0">' . esc_html__( 'Welcome to Recruitment', 'ffcertificate' ) . '</h3>';
echo '<p>' . esc_html__( 'No notices yet. The typical path to your first call is:', 'ffcertificate' ) . '</p>';
echo '<ol class="ffc-rec-ml-20">';
echo '<li>' . sprintf(
	/* translators: %s: link to the Adjutancies tab */
	wp_kses_post( __( 'Define at least one <a href="%s">adjutancy</a> (subject / role) — these are reusable across notices.', 'ffcertificate' ) ),
	esc_url( $adjutancies_url )
) . '</li>';
echo '<li>' . esc_html__( 'Create your first notice (Code + Name) using the form below this list.', 'ffcertificate' ) . '</li>';
echo '<li>' . esc_html__( 'Open the new notice and attach the relevant adjutancies + import the candidate CSV.', 'ffcertificate' ) . '</li>';
echo '<li>' . esc_html__( 'Promote the preliminary list to definitive once you\'re ready, and call candidates per row or in bulk.', 'ffcertificate' ) . '</li>';
echo '</ol>';
echo '</div>';
