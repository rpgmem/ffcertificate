<?php
/**
 * Template: Public recruitment queue — notice picker (#1646).
 *
 * Rendered by RecruitmentPublicShortcodeRenderer::render_notice_picker() when
 * the shortcode has no `notice=` attribute. Submits on change; the button is
 * the path without JavaScript.
 *
 * @var string                $preserved Hidden inputs re-emitting the other query parameters (already escaped).
 * @var array<string, string> $options   Notice code => label.
 * @var string                $selected  Code currently shown ('' for none).
 * @var string                $param     Query parameter name.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.35.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form class="ffc-recruitment-filters ffc-recruitment-notice-picker" method="get">
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hidden inputs built with esc_attr() by the caller.
	echo $preserved;
	?>
	<label>
		<?php esc_html_e( 'Notice', 'ffcertificate' ); ?>
		<select name="<?php echo esc_attr( $param ); ?>" onchange="this.form.submit()">
			<option value=""<?php selected( '', $selected ); ?>><?php esc_html_e( 'Select a notice', 'ffcertificate' ); ?></option>
			<?php foreach ( $options as $ffc_code => $ffc_label ) : ?>
				<option value="<?php echo esc_attr( (string) $ffc_code ); ?>"<?php selected( (string) $ffc_code, $selected ); ?>><?php echo esc_html( $ffc_label ); ?></option>
			<?php endforeach; ?>
		</select>
	</label>
	<noscript><button type="submit" class="ffc-recruitment-search-btn"><?php esc_html_e( 'View', 'ffcertificate' ); ?></button></noscript>
</form>
