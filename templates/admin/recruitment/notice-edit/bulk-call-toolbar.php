<?php
/**
 * Template: Recruitment notice edit — bulk-call toolbar (Definitive tab).
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer::render_bulk_call_toolbar()}
 * (rpgmem/ffcertificate#589 phase-2). Markup is byte-identical to the
 * pre-extraction inline body; the renderer includes this file.
 *
 * No template variables — static markup only.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.7.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '<div class="ffc-cls-bulk-toolbar ffc-rec-bulk-toolbar">';
echo '<strong>' . esc_html__( 'Bulk call', 'ffcertificate' ) . '</strong> ';
echo '<span class="ffc-rec-ml-half">' . esc_html__( 'Date:', 'ffcertificate' ) . ' </span>';
echo '<input type="date" id="ffc-bulk-date" class="ffc-rec-mr-half">';
echo '<span>' . esc_html__( 'Time:', 'ffcertificate' ) . ' </span>';
echo '<input type="time" id="ffc-bulk-time" class="ffc-rec-mr-half">';
echo '<button type="button" class="button button-primary" onclick="ffcRecruitmentBulkCall();">' . esc_html__( 'Call selected', 'ffcertificate' ) . '</button>';
echo '<span id="ffc-bulk-status" class="ffc-rec-mono-status"></span>';
// §6 — bulk call is atomic; any single race-loss rolls back the entire batch.
echo '<p class="description ffc-rec-mt-6px">' . esc_html__( 'Select rows in waiting status to bulk-call them with the same date and time. The operation is atomic — any single conflict rolls back the entire batch. Date and time are remembered from the previous successful call.', 'ffcertificate' ) . '</p>';
echo '</div>';

// The date/time inputs are pre-filled from localStorage by
// ffc-recruitment-notice-edit.js (init → prefillBulkDateTime) so a
// follow-up bulk-call doesn't make the operator retype the same
// values; ffcRecruitmentBulkCall() writes them on a successful submit.
