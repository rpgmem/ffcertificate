<?php
/**
 * Template: Recruitment notice edit — CSV import section.
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer::render_csv_import_section()}
 * (rpgmem/ffcertificate#589 phase-2). Markup is byte-identical to the
 * pre-extraction inline body; the renderer prepares the locals below and
 * includes this file.
 *
 * Variables in scope (provided by the including method):
 *
 * @var int    $notice_id Notice id.
 * @var string $status    Notice status.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.7.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<div class="postbox ffc-rec-mt-20">';
echo '<h2 class="hndle"><span>' . esc_html__( 'Import candidates (CSV)', 'ffcertificate' ) . '</span></h2>';
echo '<div class="inside">';

if ( 'definitive' === $status || 'closed' === $status ) {
	echo '<p>' . esc_html__( 'Import is disabled for notices in `definitive` or `closed` status. Move the notice back to `preliminary` (allowed only when zero calls have been issued) to re-import.', 'ffcertificate' ) . '</p>';
	echo '</div></div>';
	return;
}

echo '<p>' . esc_html__( 'UTF-8 CSV (BOM optional). Required headers (English): name, cpf, rf, email, adjutancy, rank, score, pcd. Optional headers: phone, time_points, hab_emebs. At least one of cpf/rf required per row. Comma or semicolon delimiter is auto-detected.', 'ffcertificate' ) . '</p>';

$example_url = wp_nonce_url(
	add_query_arg(
		array( 'action' => 'ffc_recruitment_download_csv_example' ),
		admin_url( 'admin-post.php' )
	),
	'ffc_recruitment_download_csv_example'
);
echo '<p><a class="button" href="' . esc_url( $example_url ) . '">&darr; ' . esc_html__( 'Download example CSV', 'ffcertificate' ) . '</a> ';
echo '<span class="description ffc-rec-ml-half">' . esc_html__( 'Two-row sample with every column populated. Use it as a starting point for your own file.', 'ffcertificate' ) . '</span></p>';

echo '<form id="ffc-recruitment-edit-import" method="post" enctype="multipart/form-data" data-notice-id="' . esc_attr( (string) $notice_id ) . '" onsubmit="return ffcRecruitmentImportFromEdit(this);">';
echo '<table class="form-table"><tbody>';

echo '<tr><th><label>' . esc_html__( 'Target list', 'ffcertificate' ) . '</label></th><td>';
echo '<label class="ffc-rec-mr-1"><input type="radio" name="list_target" value="preliminary" checked> ' . esc_html__( 'Preliminary list', 'ffcertificate' ) . '</label>';
if ( 'preliminary' === $status ) {
	echo '<label><input type="radio" name="list_target" value="definitive"> ' . esc_html__( 'Definitive list (also transitions notice to `definitive`)', 'ffcertificate' ) . '</label>';
}
echo '</td></tr>';

echo '<tr><th><label for="ffc-edit-csv-file">' . esc_html__( 'CSV file', 'ffcertificate' ) . '</label></th><td>';
echo '<input id="ffc-edit-csv-file" name="csv_file" type="file" accept=".csv,text/csv" required>';
echo '</td></tr>';

echo '</tbody></table>';
echo '<p>';
echo '<button id="ffc-edit-csv-submit" type="submit" class="button button-primary">' . esc_html__( 'Import', 'ffcertificate' ) . '</button> ';
// Spinner + elapsed counter sits in the same line. Hidden until
// submit fires; revealed by the inline JS below. The importer is
// a single atomic request (no streaming progress hook) so this is
// an "activity indicator with elapsed seconds", not a real
// progress bar — the goal is to make it clear the request is in
// flight on large CSVs that may take 5–30s to commit.
// Progress widget — `<progress>` + counter for the batched preview
// flow; falls back to the spinner-only look for the definitive
// flow (which still posts in one shot to /promote-preview).
echo '<span id="ffc-edit-csv-progress" class="ffc-rec-progress-inline">';
echo '<span class="spinner is-active ffc-rec-spinner-flush"></span>';
echo '<progress id="ffc-edit-csv-progress-bar" max="1" value="0" class="ffc-rec-progress-bar"></progress>';
echo '<span id="ffc-edit-csv-progress-text"></span>';
echo '</span>';
echo '<span id="ffc-edit-csv-status" class="ffc-rec-mono-status"></span>';
echo '</p>';
// Per-line validation errors land here when /import-job/validate
// returns a non-empty list. Hidden by default; the orchestrator
// fills it in and the operator scrolls through what to fix.
echo '<ul id="ffc-edit-csv-errors" class="ffc-rec-csv-errors"></ul>';
echo '</form>';

// The submit handler (ffcRecruitmentImportFromEdit) ships in
// assets/js/ffc-recruitment-notice-edit.js. The preview flow hands
// off to window.ffcRecruitmentImportBatched.run() (start → loop batch
// → commit) so notices with hundreds of candidates stop racing the
// gateway timeout; the definitive flow keeps the single-request shape
// against /promote-preview. The notice id rides on the form's
// data-notice-id attribute; strings/nonce/REST root come from the
// localized ffcRecruitmentNoticeEdit object.

echo '</div></div>';
