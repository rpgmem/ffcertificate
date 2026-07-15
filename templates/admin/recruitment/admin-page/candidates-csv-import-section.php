<?php
/**
 * Template: Recruitment admin page — Candidates CSV import section.
 *
 * Extracted verbatim from the matching RecruitmentAdminPageRenderer method
 * (rpgmem/ffcertificate#563 coverage extraction); markup byte-identical.
 *
 * @var array<int,object> $eligible Notices eligible for import (draft/preliminary).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including renderer method's function scope, not global).

echo '<div class="postbox ffc-rec-mt-20">';
echo '<h2 class="hndle"><span>' . esc_html__( 'Import candidates (CSV)', 'ffcertificate' ) . '</span></h2>';
echo '<div class="inside">';

if ( empty( $eligible ) ) {
	echo '<p>' . esc_html__( 'No notices in `draft` or `preliminary` status are available for CSV import. Create a notice (Notices tab) or move an existing one back to `preliminary` (allowed only when zero calls have been issued).', 'ffcertificate' ) . '</p>';
	echo '</div></div>';
	return;
}

$example_url = wp_nonce_url(
	add_query_arg(
		array( 'action' => 'ffc_recruitment_download_csv_example' ),
		admin_url( 'admin-post.php' )
	),
	'ffc_recruitment_download_csv_example'
);

echo '<p>' . esc_html__( 'Pick a notice, select the target list, and upload your CSV. The notice picker only lists notices where import is allowed.', 'ffcertificate' ) . '</p>';
echo '<p><a class="button" href="' . esc_url( $example_url ) . '">&darr; ' . esc_html__( 'Download example CSV', 'ffcertificate' ) . '</a> ';
echo '<span class="description ffc-rec-ml-half">' . esc_html__( 'UTF-8 CSV (BOM optional). Required headers (English): name, cpf, rf, email, adjutancy, rank, score, pcd. Optional: phone, time_points, hab_emebs.', 'ffcertificate' ) . '</span></p>';

echo '<form id="ffc-recruitment-candidates-import" method="post" enctype="multipart/form-data" onsubmit="return ffcRecruitmentImportFromCandidates(this);">';
echo '<table class="form-table"><tbody>';

echo '<tr><th><label for="ffc-cand-import-notice">' . esc_html__( 'Target notice', 'ffcertificate' ) . '</label></th><td>';
echo '<select id="ffc-cand-import-notice" name="notice_id" required onchange="ffcRecruitmentImportNoticeChanged(this);">';
echo '<option value="" data-status="">' . esc_html__( '— Select a notice —', 'ffcertificate' ) . '</option>';
foreach ( $eligible as $n ) {
	$nid    = (int) $n->id;
	$code   = isset( $n->code ) ? (string) $n->code : '';
	$name   = isset( $n->name ) ? (string) $n->name : '';
	$status = isset( $n->status ) ? (string) $n->status : '';
	/* translators: 1: notice code, 2: notice name, 3: notice status */
	$label = sprintf( _x( '%1$s — %2$s (%3$s)', 'recruitment notice picker', 'ffcertificate' ), $code, $name, $status );
	echo '<option value="' . esc_attr( (string) $nid ) . '" data-status="' . esc_attr( $status ) . '">' . esc_html( $label ) . '</option>';
}
echo '</select>';
echo '</td></tr>';

// Target list radios. The "definitive" option is rendered but
// disabled by default — the onchange handler enables it only
// when the selected notice's status is `preliminary`.
echo '<tr><th><label>' . esc_html__( 'Target list', 'ffcertificate' ) . '</label></th><td>';
echo '<label class="ffc-rec-mr-1"><input type="radio" name="list_target" value="preliminary" checked> ' . esc_html__( 'Preliminary list', 'ffcertificate' ) . '</label>';
echo '<label><input type="radio" name="list_target" value="definitive" disabled> ' . esc_html__( 'Definitive list (also transitions notice to `definitive`)', 'ffcertificate' ) . '</label>';
echo '<p class="description" id="ffc-cand-import-target-help">' . esc_html__( 'Pick a notice above to see which lists can receive the import.', 'ffcertificate' ) . '</p>';
echo '</td></tr>';

echo '<tr><th><label for="ffc-cand-csv-file">' . esc_html__( 'CSV file', 'ffcertificate' ) . '</label></th><td>';
echo '<input id="ffc-cand-csv-file" name="csv_file" type="file" accept=".csv,text/csv" required>';
echo '</td></tr>';

echo '</tbody></table>';
echo '<p>';
echo '<button id="ffc-cand-csv-submit" type="submit" class="button button-primary">' . esc_html__( 'Import', 'ffcertificate' ) . '</button> ';
echo '<span id="ffc-cand-csv-progress" class="ffc-rec-progress-inline">';
echo '<span class="spinner is-active ffc-rec-spinner-flush"></span>';
echo '<span id="ffc-cand-csv-progress-text"></span>';
echo '</span>';
echo '<span id="ffc-cand-csv-status" class="ffc-rec-mono-status"></span>';
echo '</p>';
echo '</form>';

// The CSV-import handlers (ffcRecruitmentImportNoticeChanged /
// ffcRecruitmentImportFromCandidates) ship in
// assets/js/ffc-recruitment-candidates-import.js, enqueued +
// localized by RecruitmentAdminAssetsManager. They mirror
// ffcRecruitmentImportFromEdit on the Notice Edit page, reusing the
// same REST endpoints (no new backend) so the importer service and
// activity logger fire unchanged.
echo '</div></div>';
