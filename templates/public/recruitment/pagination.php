<?php
/**
 * Template: Public recruitment listing — pagination nav.
 *
 * Extracted verbatim from RecruitmentPublicShortcodeRenderer::render_pagination();
 * markup byte-identical. The window math (start/end) and the $url_to closure are
 * computed by the caller and inherited into this file's scope.
 *
 * @var int      $current_page 1-indexed current page.
 * @var int      $total_pages  Total page count.
 * @var int      $start        First page in the sliding window.
 * @var int      $end          Last page in the sliding window.
 * @var callable $url_to       fn(int $p): string — builds a page URL.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '<nav class="ffc-recruitment-pagination">';

// First / prev arrows. Hidden when already at the start so the
// markup doesn't carry inert affordances.
if ( $current_page > 1 ) {
	echo '<a class="ffc-recruitment-pagination-arrow" href="' . esc_url( $url_to( 1 ) ) . '" aria-label="' . esc_attr__( 'First page', 'ffcertificate' ) . '" title="' . esc_attr__( 'First page', 'ffcertificate' ) . '">&laquo;</a>';
	echo '<a class="ffc-recruitment-pagination-arrow" href="' . esc_url( $url_to( $current_page - 1 ) ) . '" aria-label="' . esc_attr__( 'Previous page', 'ffcertificate' ) . '" title="' . esc_attr__( 'Previous page', 'ffcertificate' ) . '">&lsaquo;</a>';
}

for ( $p = $start; $p <= $end; $p++ ) {
	if ( $p === $current_page ) {
		echo '<span class="current" aria-current="page">' . esc_html( (string) $p ) . '</span>';
	} else {
		echo '<a href="' . esc_url( $url_to( $p ) ) . '">' . esc_html( (string) $p ) . '</a>';
	}
}

if ( $current_page < $total_pages ) {
	echo '<a class="ffc-recruitment-pagination-arrow" href="' . esc_url( $url_to( $current_page + 1 ) ) . '" aria-label="' . esc_attr__( 'Next page', 'ffcertificate' ) . '" title="' . esc_attr__( 'Next page', 'ffcertificate' ) . '">&rsaquo;</a>';
	echo '<a class="ffc-recruitment-pagination-arrow" href="' . esc_url( $url_to( $total_pages ) ) . '" aria-label="' . esc_attr__( 'Last page', 'ffcertificate' ) . '" title="' . esc_attr__( 'Last page', 'ffcertificate' ) . '">&raquo;</a>';
}

echo '</nav>';
