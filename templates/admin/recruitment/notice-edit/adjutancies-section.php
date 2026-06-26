<?php
/**
 * Template: Recruitment notice edit — Adjutancies section (attached pills +
 * attach selector).
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer::render_adjutancies_section()}
 * (rpgmem/ffcertificate#589 phase-2). Markup is byte-identical to the
 * pre-extraction inline body; the renderer prepares the locals below and
 * includes this file.
 *
 * Variables in scope (provided by the including method):
 *
 * @var int                  $notice_id        Notice id.
 * @var array<int, object>   $adjutancies      All adjutancy rows.
 * @var array<int, int>      $attached_set     Flip-map of attached adjutancy ids.
 * @var array<int, object>   $attached_objects Attached adjutancy rows.
 * @var array<int, object>   $detached_objects Detached adjutancy rows.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.7.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<div class="postbox ffc-rec-mt-20">';
echo '<h2 class="hndle"><span>' . esc_html__( 'Adjutancies', 'ffcertificate' ) . '</span></h2>';
echo '<div class="inside">';
echo '<p>' . esc_html__( 'Adjutancies referenced by CSV imports must be attached to the notice via this section.', 'ffcertificate' ) . '</p>';

if ( empty( $attached_objects ) ) {
	echo '<p><em>' . esc_html__( 'No adjutancies attached yet.', 'ffcertificate' ) . '</em></p>';
} else {
	echo '<p><span class="ffc-attached-list">';
	foreach ( $attached_objects as $a ) {
		echo '<span class="ffc-attached">';
		echo esc_html( (string) $a->slug );
		echo ' <a href="#" data-notice="' . esc_attr( (string) $notice_id ) . '" data-adjutancy="' . esc_attr( (string) $a->id ) . '" onclick="return ffcDetachAdjutancy(this);" title="' . esc_attr__( 'Detach', 'ffcertificate' ) . '">×</a>';
		echo '</span>';
	}
	echo '</span></p>';
}

if ( ! empty( $detached_objects ) ) {
	echo '<form onsubmit="return ffcAttachAdjutancy(this);" data-notice="' . esc_attr( (string) $notice_id ) . '">';
	echo '<select name="adjutancy_id">';
	foreach ( $detached_objects as $a ) {
		echo '<option value="' . esc_attr( (string) $a->id ) . '">' . esc_html( (string) $a->slug ) . ' — ' . esc_html( (string) $a->name ) . '</option>';
	}
	echo '</select>';
	echo ' <button type="submit" class="button button-secondary">' . esc_html__( 'Attach', 'ffcertificate' ) . '</button>';
	echo '</form>';
}

// ffcAttachAdjutancy / ffcDetachAdjutancy ship in
// ffc-recruitment-notice-edit.js; they read the notice/adjutancy ids
// from the data-notice / data-adjutancy attributes already on the
// form and the detach link.

echo '</div></div>';
