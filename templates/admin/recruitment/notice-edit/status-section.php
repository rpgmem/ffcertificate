<?php
/**
 * Template: Recruitment notice edit — Status section (badge + transition
 * buttons).
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer::render_status_section()}.
 * Markup is byte-identical to the pre-extraction inline body; the renderer
 * prepares the locals below (including the pre-rendered private-helper HTML)
 * and includes this file.
 *
 * Variables in scope (provided by the including method):
 *
 * @var object                    $notice              Notice row.
 * @var string                    $current             Current notice status.
 * @var string                    $nonce_action        Transition nonce action key.
 * @var string                    $status_badge        Pre-rendered (escaped) current-state badge HTML.
 * @var array<string, string>     $transitions         Allowed target-status => label map.
 * @var array<string, array>      $modal_config        Per-target confirm-modal copy.
 * @var string                    $prelim_options_html Pre-rendered (escaped) preliminary→definitive dual-path UI ('' unless preliminary).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<div class="postbox ffc-rec-mt-20">';
echo '<h2 class="hndle"><span>' . esc_html__( 'Status', 'ffcertificate' ) . '</span></h2>';
echo '<div class="inside">';

echo '<p><strong>' . esc_html__( 'Current state:', 'ffcertificate' ) . '</strong> ';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
echo $status_badge;
if ( '1' === (string) $notice->was_reopened ) {
	echo ' <em>(' . esc_html__( 'previously reopened — hired/withdrew/not_shown classifications are frozen', 'ffcertificate' ) . ')</em>';
}
echo '</p>';

// Special-case the preliminary → definitive transition: it has two paths
// per §5.1 (snapshot the preview list, or import a new definitive CSV),
// pre-rendered by the including method. Empty string unless preliminary.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered, already-escaped HTML.
echo $prelim_options_html;

if ( empty( $transitions ) ) {
	if ( 'preliminary' !== $current ) {
		echo '<p>' . esc_html__( 'No transitions available from this state.', 'ffcertificate' ) . '</p>';
	}
} else {
	echo '<p>';
	foreach ( $transitions as $target => $label ) {
		$cfg          = isset( $modal_config[ $target ] ) ? $modal_config[ $target ] : null;
		$consequences = wp_json_encode( $cfg ? $cfg['consequences'] : array() );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ffc-rec-inline-form"';
		if ( $cfg ) {
			echo ' data-ffc-confirm'
				. ' data-ffc-confirm-title="' . esc_attr( $cfg['title'] ) . '"'
				. ' data-ffc-confirm-body="' . esc_attr( $cfg['body'] ) . '"'
				. ' data-ffc-confirm-consequences="' . esc_attr( (string) $consequences ) . '"'
				. ' data-ffc-confirm-cta="' . esc_attr( $cfg['cta'] ) . '"'
				. ' data-ffc-confirm-style="' . esc_attr( $cfg['style'] ) . '"';
			if ( ! empty( $cfg['reason_label'] ) ) {
				echo ' data-ffc-confirm-reason-label="' . esc_attr( $cfg['reason_label'] ) . '"';
				echo ' data-ffc-confirm-reason-name="reason"';
			}
			if ( ! empty( $cfg['countdown'] ) ) {
				echo ' data-ffc-confirm-countdown="' . esc_attr( (string) (int) $cfg['countdown'] ) . '"';
			}
		}
		echo '>';
		echo '<input type="hidden" name="action" value="ffc_recruitment_transition_notice">';
		echo '<input type="hidden" name="notice_id" value="' . esc_attr( (string) $notice->id ) . '">';
		echo '<input type="hidden" name="target_status" value="' . esc_attr( $target ) . '">';
		wp_nonce_field( $nonce_action );
		echo '<button type="submit" class="button button-secondary">';
		echo esc_html( $label );
		echo '</button>';
		echo '</form>';
	}
	echo '</p>';
}

echo '</div></div>';
