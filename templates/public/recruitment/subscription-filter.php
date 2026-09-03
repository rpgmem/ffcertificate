<?php
/**
 * Template: Public recruitment filter bar — subscription-type <select>.
 *
 * Extracted verbatim from
 * RecruitmentPublicShortcodeRenderer::render_subscription_filter_inputs();
 * markup byte-identical. Auto-submits on change (no wrapping <form> — the
 * caller owns it).
 *
 * @var string $subscription Current `?subscription=` value.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '<label class="ffc-recruitment-subscription-filter">';
echo esc_html__( 'Subscription:', 'ffcertificate' ) . ' ';
echo '<select name="subscription" onchange="this.form.submit()">';
echo '<option value=""' . selected( '', $subscription, false ) . '>' . esc_html__( 'All', 'ffcertificate' ) . '</option>';
echo '<option value="pcd"' . selected( 'pcd', $subscription, false ) . '>' . esc_html__( 'PCD', 'ffcertificate' ) . '</option>';
echo '<option value="geral"' . selected( 'geral', $subscription, false ) . '>' . esc_html__( 'GERAL', 'ffcertificate' ) . '</option>';
echo '</select></label>';
