<?php
/**
 * Quiz graded, not passed — default body (#1732).
 *
 * Sent when a person finishes grading an attempt's open answers and the
 * attempt stays below the passing score. Wrapped by the configurable chrome
 * (layout.php) at send. `{{result_line}}` carries the score only when the form
 * shows it; `{{attempts_line}}` says whether another attempt is possible.
 *
 * @package FreeFormCertificate\Core
 * @since   6.39.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Your answers were graded: {{form_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;">' . __( 'Your answers were graded', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Your answers to {{form_title}} were graded.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #fcf9e8; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dba617;">'
		. '<p style="margin: 0 0 10px 0;">{{result_line}}</p>'
		. '<p style="margin: 0;">{{attempts_line}}</p>'
		. '</div>',
);
