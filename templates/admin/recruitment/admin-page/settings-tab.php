<?php
/**
 * Template: Recruitment admin page — Settings tab.
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentAdminPageRenderer::render_settings_tab()}
 * (rpgmem/ffcertificate#563 coverage extraction). Markup is byte-identical to
 * the pre-extraction inline body; the renderer prepares the locals below and
 * includes this file.
 *
 * Variables in scope (provided by the including method):
 *
 * @var array<string,mixed> $settings Current recruitment settings (RecruitmentSettings::all()).
 * @var bool                $can_edit Whether the current user may change settings.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.12.0
 */

use FreeFormCertificate\Recruitment\RecruitmentSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<h2>' . esc_html__( 'Settings', 'ffcertificate' ) . '</h2>';
echo '<p>' . esc_html__( 'Email templates and public shortcode tuning. Saved values populate the convocation email and the public shortcode cache/rate-limit/page-size knobs.', 'ffcertificate' ) . '</p>';

if ( ! $can_edit ) {
	echo '<p class="description"><em>' . esc_html__( 'Read-only — you do not have permission to change recruitment settings.', 'ffcertificate' ) . '</em></p>';
}

echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
settings_fields( RecruitmentSettings::OPTION_GROUP );
if ( ! $can_edit ) {
	// A disabled fieldset blocks every input + submission inside it.
	echo '<fieldset disabled>';
}

$opt = RecruitmentSettings::OPTION_NAME;

echo '<div class="card">';
echo '<h2 class="ffc-icon-email">' . esc_html__( 'Email template', 'ffcertificate' ) . '</h2>';
\FreeFormCertificate\Core\EmailDisabledNotice::render();
echo '<table class="form-table"><tbody>';

echo '<tr><th><label for="ffc-rs-subject">' . esc_html__( 'Subject', 'ffcertificate' ) . '</label></th><td>';
echo '<input id="ffc-rs-subject" type="text" class="large-text" name="' . esc_attr( $opt ) . '[email_subject]" value="' . esc_attr( (string) $settings['email_subject'] ) . '">';
echo '<p class="description">' . esc_html__( 'Placeholders: {{notice_code}}, {{notice_name}}, {{adjutancy}}, {{name}}, {{rank}}, {{score}}, {{date_to_assume}}, {{time_to_assume}}, {{is_pcd}}, {{site_name}}, {{site_url}}, {{notes}}, and the masked variants {{cpf_masked}}, {{rf_masked}}, {{email_masked}}.', 'ffcertificate' ) . '</p>';
echo '</td></tr>';

echo '<tr><th><label for="ffc-rs-from-address">' . esc_html__( 'From address', 'ffcertificate' ) . '</label></th><td>';
echo '<input id="ffc-rs-from-address" type="email" class="regular-text" name="' . esc_attr( $opt ) . '[email_from_address]" value="' . esc_attr( (string) $settings['email_from_address'] ) . '" placeholder="(falls back to wp_mail default)">';
echo '</td></tr>';

echo '<tr><th><label for="ffc-rs-from-name">' . esc_html__( 'From name', 'ffcertificate' ) . '</label></th><td>';
echo '<input id="ffc-rs-from-name" type="text" class="regular-text" name="' . esc_attr( $opt ) . '[email_from_name]" value="' . esc_attr( (string) $settings['email_from_name'] ) . '" placeholder="(falls back to site name)">';
echo '</td></tr>';

echo '<tr><th><label for="ffc_rs_body">' . esc_html__( 'Body (HTML)', 'ffcertificate' ) . '</label></th><td>';
wp_editor(
	(string) $settings['email_body_html'],
	'ffc_rs_body',
	array(
		'textarea_name' => $opt . '[email_body_html]',
		'textarea_rows' => 12,
		'media_buttons' => false,
		'teeny'         => true,
		'tinymce'       => array(
			'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
			'toolbar2' => '',
		),
		'quicktags'     => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ),
	)
);
echo '<p class="description">' . esc_html__( 'The message content ("miolo") only — the shared Email Model chrome (header/footer) is added automatically. Same placeholder set as the subject; the text/plain alternative is auto-derived.', 'ffcertificate' ) . '</p>';
echo '<p><button type="button" class="button ffc-email-restore-default" data-editor="ffc_rs_body" data-default-key="recruitment_body">' . esc_html__( 'Restore Default Text', 'ffcertificate' ) . '</button></p>';
echo '</td></tr>';
echo '</tbody></table>';
echo '</div>';

echo '<div class="card">';
echo '<h2 class="ffc-icon-link">' . esc_html__( 'Public shortcode', 'ffcertificate' ) . '</h2>';
echo '<table class="form-table"><tbody>';

echo '<tr><th><label for="ffc-rs-cache">' . esc_html__( 'Cache TTL (seconds)', 'ffcertificate' ) . '</label></th><td>';
echo '<input id="ffc-rs-cache" type="number" min="0" name="' . esc_attr( $opt ) . '[public_cache_seconds]" value="' . esc_attr( (string) $settings['public_cache_seconds'] ) . '">';
echo '<p class="description">' . esc_html__( 'Transient cache for the public shortcode. 0 disables.', 'ffcertificate' ) . '</p>';
echo '</td></tr>';

echo '<tr><th><label for="ffc-rs-rate">' . esc_html__( 'Rate limit (requests / minute / IP)', 'ffcertificate' ) . '</label></th><td>';
echo '<input id="ffc-rs-rate" type="number" min="0" name="' . esc_attr( $opt ) . '[public_rate_limit_per_minute]" value="' . esc_attr( (string) $settings['public_rate_limit_per_minute'] ) . '">';
echo '<p class="description">' . esc_html__( '0 disables the per-IP rate limit.', 'ffcertificate' ) . '</p>';
echo '</td></tr>';

echo '<tr><th><label for="ffc-rs-pagesize">' . esc_html__( 'Default page size', 'ffcertificate' ) . '</label></th><td>';
echo '<input id="ffc-rs-pagesize" type="number" min="1" max="500" name="' . esc_attr( $opt ) . '[public_default_page_size]" value="' . esc_attr( (string) $settings['public_default_page_size'] ) . '">';
echo '</td></tr>';
echo '</tbody></table>';
echo '</div>';

echo '<div class="card">';
echo '<h2 class="ffc-icon-palette">' . esc_html__( 'Status badge colors', 'ffcertificate' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'Background color used for each classification status pill on the public shortcode. Accepts #RGB / #RRGGBB / #RRGGBBAA. Bad values silently fall back to defaults.', 'ffcertificate' ) . '</p>';
echo '<table class="form-table"><tbody>';

$status_color_rows = array(
	'status_color_empty'     => __( 'Waiting (empty)', 'ffcertificate' ),
	'status_color_called'    => __( 'Called / Accepted', 'ffcertificate' ),
	'status_color_hired'     => __( 'Hired', 'ffcertificate' ),
	'status_color_not_shown' => __( 'Did not show up', 'ffcertificate' ),
	'status_color_withdrew'  => __( 'Withdrew', 'ffcertificate' ),
);
foreach ( $status_color_rows as $field => $label ) {
	echo '<tr><th><label for="ffc-rs-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td>';
	echo '<input id="ffc-rs-' . esc_attr( $field ) . '" type="color" name="' . esc_attr( $opt ) . '[' . esc_attr( $field ) . ']" value="' . esc_attr( (string) $settings[ $field ] ) . '">';
	echo ' <code class="ffc-rec-ml-half">' . esc_html( (string) $settings[ $field ] ) . '</code>';
	echo '</td></tr>';
}
echo '</tbody></table>';
echo '</div>';

echo '<div class="card">';
echo '<h2 class="ffc-icon-palette">' . esc_html__( 'Preliminary list — badge colors', 'ffcertificate' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'Background color used for each preliminary-list visual status on the public shortcode. These statuses do not change the candidate flow; they only affect the badge color.', 'ffcertificate' ) . '</p>';
echo '<table class="form-table"><tbody>';

$preview_color_rows = array(
	'preview_color_empty'          => __( 'Empty (no decision)', 'ffcertificate' ),
	'preview_color_denied'         => __( 'Denied', 'ffcertificate' ),
	'preview_color_granted'        => __( 'Granted', 'ffcertificate' ),
	'preview_color_appeal_denied'  => __( 'Appeal denied', 'ffcertificate' ),
	'preview_color_appeal_granted' => __( 'Appeal granted', 'ffcertificate' ),
);
foreach ( $preview_color_rows as $field => $label ) {
	echo '<tr><th><label for="ffc-rs-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td>';
	echo '<input id="ffc-rs-' . esc_attr( $field ) . '" type="color" name="' . esc_attr( $opt ) . '[' . esc_attr( $field ) . ']" value="' . esc_attr( (string) $settings[ $field ] ) . '">';
	echo ' <code class="ffc-rec-ml-half">' . esc_html( (string) $settings[ $field ] ) . '</code>';
	echo '</td></tr>';
}
echo '</tbody></table>';
echo '</div>';

echo '<div class="card">';
echo '<h2 class="ffc-icon-clipboard">' . esc_html__( 'Preliminary list — reason required?', 'ffcertificate' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'Per-status flag controlling whether a reason from the Reasons catalog must be supplied when an admin sets that preliminary status on a row.', 'ffcertificate' ) . '</p>';
echo '<table class="form-table"><tbody>';

$reason_required_rows = array(
	'preview_reason_required_denied'         => __( 'Denied requires a reason', 'ffcertificate' ),
	'preview_reason_required_granted'        => __( 'Granted requires a reason', 'ffcertificate' ),
	'preview_reason_required_appeal_denied'  => __( 'Appeal denied requires a reason', 'ffcertificate' ),
	'preview_reason_required_appeal_granted' => __( 'Appeal granted requires a reason', 'ffcertificate' ),
);
foreach ( $reason_required_rows as $field => $label ) {
	echo '<tr><th>' . esc_html( $label ) . '</th><td>';
	\FreeFormCertificate\Admin\AdminUI::render_toggle(
		array(
			'name'    => $opt . '[' . $field . ']',
			'id'      => 'ffc-rs-' . $field,
			'checked' => ! empty( $settings[ $field ] ),
			'data'    => array( 'ffc-autosave-key' => 'recruitment_' . $field ),
		)
	);
	echo '</td></tr>';
}
echo '</tbody></table>';
echo '</div>';

echo '<div class="card">';
echo '<h2 class="ffc-icon-palette">' . esc_html__( 'Subscription type — badge colors', 'ffcertificate' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'Background color used on the public + admin subscription-type badges. Each candidate is either PCD (pessoa com deficiência) or GERAL — these two knobs paint the corresponding pill.', 'ffcertificate' ) . '</p>';
echo '<table class="form-table"><tbody>';

$subscription_color_rows = array(
	'subscription_color_pcd'   => __( 'PCD', 'ffcertificate' ),
	'subscription_color_geral' => __( 'GERAL', 'ffcertificate' ),
);
foreach ( $subscription_color_rows as $field => $label ) {
	echo '<tr><th><label for="ffc-rs-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td>';
	echo '<input id="ffc-rs-' . esc_attr( $field ) . '" type="color" name="' . esc_attr( $opt ) . '[' . esc_attr( $field ) . ']" value="' . esc_attr( (string) $settings[ $field ] ) . '">';
	echo ' <code class="ffc-rec-ml-half">' . esc_html( (string) $settings[ $field ] ) . '</code>';
	echo '</td></tr>';
}
echo '</tbody></table>';
echo '</div>';

echo '<div class="card">';
echo '<h2 class="ffc-icon-palette">' . esc_html__( 'Notice status — badge colors', 'ffcertificate' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'Background color used for each notice lifecycle status (Draft / Preliminary / Definitive / Closed). Drives both the admin Notices list table and the public shortcode banner so both surfaces share one palette.', 'ffcertificate' ) . '</p>';
echo '<table class="form-table"><tbody>';

$notice_status_color_rows = array(
	'notice_status_color_draft'       => __( 'Draft', 'ffcertificate' ),
	'notice_status_color_preliminary' => __( 'Preliminary', 'ffcertificate' ),
	'notice_status_color_definitive'  => __( 'Definitive', 'ffcertificate' ),
	'notice_status_color_closed'      => __( 'Closed', 'ffcertificate' ),
);
foreach ( $notice_status_color_rows as $field => $label ) {
	echo '<tr><th><label for="ffc-rs-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td>';
	echo '<input id="ffc-rs-' . esc_attr( $field ) . '" type="color" name="' . esc_attr( $opt ) . '[' . esc_attr( $field ) . ']" value="' . esc_attr( (string) $settings[ $field ] ) . '">';
	echo ' <code class="ffc-rec-ml-half">' . esc_html( (string) $settings[ $field ] ) . '</code>';
	echo '</td></tr>';
}
echo '</tbody></table>';
echo '</div>';

// PII / audit toggle (#330). Lives at the bottom of the Settings
// tab because it's a security knob, not a visual one — operators
// who land here are usually adjusting palettes. The default is
// `true` so the first save after the upgrade keeps auditing on.
echo '<div class="card">';
echo '<h2 class="ffc-icon-shield">' . esc_html__( 'PII access audit', 'ffcertificate' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'When enabled, every reveal of CPF / RF on the candidate detail screen by a non-admin user writes a row to the activity log (with a 60-second dedup per user + candidate + field). Recommended ON for compliance.', 'ffcertificate' ) . '</p>';
echo '<table class="form-table"><tbody>';
echo '<tr><th>' . esc_html__( 'Audit PII reveals', 'ffcertificate' ) . '</th><td>';
\FreeFormCertificate\Admin\AdminUI::render_toggle(
	array(
		'name'    => $opt . '[audit_pii_reveals]',
		'id'      => 'ffc-rs-audit-pii-reveals',
		'checked' => ! empty( $settings['audit_pii_reveals'] ),
		'data'    => array( 'ffc-autosave-key' => 'recruitment_audit_pii_reveals' ),
	)
);
echo '</td></tr>';
echo '</tbody></table>';
echo '</div>';

if ( $can_edit ) {
	submit_button();
} else {
	echo '</fieldset>';
}
echo '</form>';
