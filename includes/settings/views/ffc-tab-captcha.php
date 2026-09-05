<?php
/**
 * Captcha Settings Tab View
 *
 * @package FreeFormCertificate\Settings\Views
 * @since   6.23.0
 */

use FreeFormCertificate\Core\Captcha\AltchaCaptcha;
use FreeFormCertificate\Core\Captcha\CaptchaSettings;
use FreeFormCertificate\Core\Captcha\CompositeCaptcha;
use FreeFormCertificate\Core\Captcha\MathCaptcha;
use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_settings = SettingsReader::all();

$ffc_provider    = CaptchaSettings::one_of( $ffc_settings['captcha_provider'] ?? '', array( MathCaptcha::ID, AltchaCaptcha::ID, CompositeCaptcha::ID ), MathCaptcha::ID );
$ffc_attributes  = CaptchaSettings::widget_attributes();
$ffc_complexity  = CaptchaSettings::complexity();
$ffc_ttl         = CaptchaSettings::ttl();
$ffc_hide_logo   = (int) ( $ffc_settings['captcha_altcha_hide_logo'] ?? 0 );
$ffc_hide_footer = (int) ( $ffc_settings['captcha_altcha_hide_footer'] ?? 0 );
$ffc_is_ssl      = is_ssl();

/*
 * Each mode carries a consequence, not a preference, so the warning travels
 * with the option instead of living in a paragraph above the group where it
 * would be read once and forgotten.
 */
$ffc_modes = array(
	MathCaptcha::ID      => array(
		'label'   => __( 'Math challenge only', 'ffcertificate' ),
		'summary' => __( 'An arithmetic question the visitor answers. Works everywhere — no JavaScript, no HTTPS required.', 'ffcertificate' ),
		'warning' => __( 'Weakest option against automation: the answer space is small, so a script that solves arithmetic gets through. Signed, expiring and single-use tokens stop a captured answer from being replayed, but they do not make the question harder.', 'ffcertificate' ),
		'level'   => 'warning',
	),
	CompositeCaptcha::ID => array(
		'label'   => __( 'ALTCHA, with the math challenge as fallback', 'ffcertificate' ),
		'summary' => __( 'Visitors with JavaScript solve a proof of work; everyone else answers the arithmetic question.', 'ffcertificate' ),
		'warning' => __( 'This is reach, not strength. The server accepts either proof, so an attacker simply picks the cheaper one — the effective security equals the math challenge alone. Choose it when some of your visitors cannot run the widget.', 'ffcertificate' ),
		'level'   => 'warning',
	),
	AltchaCaptcha::ID    => array(
		'label'   => __( 'ALTCHA only', 'ffcertificate' ),
		'summary' => __( 'Every visitor solves a proof of work in the browser. Nothing is sent to any third party.', 'ffcertificate' ),
		'warning' => __( 'Requires JavaScript and HTTPS. A visitor with JavaScript disabled cannot submit the form at all — acceptable when the audience is known, such as staff filling in forms on a workstation.', 'ffcertificate' ),
		'level'   => 'info',
	),
);
?>

<div class="ffc-settings-wrap">

<div class="card">
	<h2 class="ffc-icon-shield"><?php esc_html_e( 'Captcha', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'The challenge that guards public forms: certificate submissions, certificate verification, appointment booking and the public CSV download. It applies to all of them at once.', 'ffcertificate' ); ?>
	</p>

	<?php if ( ! $ffc_is_ssl ) : ?>
		<?php
		wp_admin_notice(
			esc_html__( 'This site is not being served over HTTPS. The ALTCHA widget refuses to run outside a secure connection, so the ALTCHA-only mode cannot be selected here — the fallback mode remains available.', 'ffcertificate' ),
			array(
				'type'               => 'warning',
				'additional_classes' => array( 'inline' ),
			)
		);
		?>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
		<input type="hidden" name="_ffc_tab" value="captcha">

		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Mode', 'ffcertificate' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Captcha mode', 'ffcertificate' ); ?></legend>
						<?php foreach ( $ffc_modes as $ffc_mode_id => $ffc_mode ) : ?>
							<?php $ffc_blocked = ( AltchaCaptcha::ID === $ffc_mode_id && ! $ffc_is_ssl ); ?>
							<p class="ffc-captcha-mode">
								<label>
									<input type="radio" name="ffc_settings[captcha_provider]"
										value="<?php echo esc_attr( $ffc_mode_id ); ?>"
										<?php checked( $ffc_provider, $ffc_mode_id ); ?>
										<?php disabled( $ffc_blocked ); ?> />
									<strong><?php echo esc_html( $ffc_mode['label'] ); ?></strong>
								</label>
								<span class="description ffc-captcha-mode-summary"><?php echo esc_html( $ffc_mode['summary'] ); ?></span>
								<span class="description ffc-captcha-mode-note ffc-captcha-mode-note--<?php echo esc_attr( $ffc_mode['level'] ); ?>">
									<?php echo esc_html( $ffc_mode['warning'] ); ?>
								</span>
							</p>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'ALTCHA widget', 'ffcertificate' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'These apply to the two modes that show the widget. They have no effect while the math challenge is the only one in use.', 'ffcertificate' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row">
					<label for="captcha_altcha_complexity"><?php esc_html_e( 'Work factor', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<input type="number" name="ffc_settings[captcha_altcha_complexity]" id="captcha_altcha_complexity"
						value="<?php echo esc_attr( (string) $ffc_complexity ); ?>"
						min="<?php echo esc_attr( (string) CaptchaSettings::COMPLEXITY_MIN ); ?>"
						max="<?php echo esc_attr( (string) CaptchaSettings::COMPLEXITY_MAX ); ?>"
						step="1000" class="regular-text" />
					<p class="description">
						<?php
						printf(
							/* translators: 1: minimum work factor, 2: maximum work factor */
							esc_html__( 'How much computation the visitor\'s browser spends proving it is not a script. The browser searches for a secret number up to this value, so it does about half this many hash operations. Allowed range: %1$s to %2$s.', 'ffcertificate' ),
							esc_html( number_format_i18n( CaptchaSettings::COMPLEXITY_MIN ) ),
							esc_html( number_format_i18n( CaptchaSettings::COMPLEXITY_MAX ) )
						);
						?>
					</p>
					<p class="description">
						<?php esc_html_e( 'Higher costs an attacker more per attempt, and costs an honest visitor more waiting. The range is bounded on both sides on purpose: too low and the proof means nothing, too high and a slow phone grinds until the widget gives up after 90 seconds.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="captcha_altcha_ttl"><?php esc_html_e( 'Challenge lifetime', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<input type="number" name="ffc_settings[captcha_altcha_ttl]" id="captcha_altcha_ttl"
						value="<?php echo esc_attr( (string) $ffc_ttl ); ?>"
						min="<?php echo esc_attr( (string) CaptchaSettings::TTL_MIN ); ?>"
						max="<?php echo esc_attr( (string) CaptchaSettings::TTL_MAX ); ?>"
						step="30" class="small-text" />
					<span><?php esc_html_e( 'seconds', 'ffcertificate' ); ?></span>
					<p class="description">
						<?php esc_html_e( 'How long a challenge stays valid after it is issued. Long enough to fill in the form without rushing; short enough that a solved challenge is not worth stockpiling.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="captcha_altcha_type"><?php esc_html_e( 'Control', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<select name="ffc_settings[captcha_altcha_type]" id="captcha_altcha_type">
						<option value="checkbox" <?php selected( $ffc_attributes['type'], 'checkbox' ); ?>><?php esc_html_e( 'Checkbox', 'ffcertificate' ); ?></option>
						<option value="switch" <?php selected( $ffc_attributes['type'], 'switch' ); ?>><?php esc_html_e( 'Switch', 'ffcertificate' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="captcha_altcha_auto"><?php esc_html_e( 'When to verify', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<select name="ffc_settings[captcha_altcha_auto]" id="captcha_altcha_auto">
						<option value="off" <?php selected( $ffc_attributes['auto'], 'off' ); ?>><?php esc_html_e( 'When the visitor activates the control', 'ffcertificate' ); ?></option>
						<option value="onfocus" <?php selected( $ffc_attributes['auto'], 'onfocus' ); ?>><?php esc_html_e( 'When the form is focused', 'ffcertificate' ); ?></option>
						<option value="onload" <?php selected( $ffc_attributes['auto'], 'onload' ); ?>><?php esc_html_e( 'As soon as the page loads', 'ffcertificate' ); ?></option>
						<option value="onsubmit" <?php selected( $ffc_attributes['auto'], 'onsubmit' ); ?>><?php esc_html_e( 'When the form is submitted', 'ffcertificate' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Verifying on page load spends the work before anyone decides to submit, and a challenge started too early can expire while the form is still being filled in.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="captcha_altcha_display"><?php esc_html_e( 'Layout', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<select name="ffc_settings[captcha_altcha_display]" id="captcha_altcha_display">
						<option value="standard" <?php selected( $ffc_attributes['display'], 'standard' ); ?>><?php esc_html_e( 'Standard', 'ffcertificate' ); ?></option>
						<option value="bar" <?php selected( $ffc_attributes['display'], 'bar' ); ?>><?php esc_html_e( 'Bar', 'ffcertificate' ); ?></option>
						<option value="floating" <?php selected( $ffc_attributes['display'], 'floating' ); ?>><?php esc_html_e( 'Floating', 'ffcertificate' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="captcha_altcha_theme"><?php esc_html_e( 'Theme', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<select name="ffc_settings[captcha_altcha_theme]" id="captcha_altcha_theme">
						<option value="" <?php selected( $ffc_attributes['theme'], '' ); ?>><?php esc_html_e( 'Follow the visitor\'s system preference', 'ffcertificate' ); ?></option>
						<option value="light" <?php selected( $ffc_attributes['theme'], 'light' ); ?>><?php esc_html_e( 'Light', 'ffcertificate' ); ?></option>
						<option value="dark" <?php selected( $ffc_attributes['theme'], 'dark' ); ?>><?php esc_html_e( 'Dark', 'ffcertificate' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Attribution', 'ffcertificate' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Widget attribution', 'ffcertificate' ); ?></legend>
						<label>
							<input type="checkbox" name="ffc_settings[captcha_altcha_hide_logo]" value="1" <?php checked( $ffc_hide_logo, 1 ); ?> />
							<?php esc_html_e( 'Hide the ALTCHA logo', 'ffcertificate' ); ?>
						</label><br />
						<label>
							<input type="checkbox" name="ffc_settings[captcha_altcha_hide_footer]" value="1" <?php checked( $ffc_hide_footer, 1 ); ?> />
							<?php esc_html_e( 'Hide the "Protected by ALTCHA" footer', 'ffcertificate' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
			</tbody>
		</table>

		<?php submit_button(); ?>
	</form>
</div>

<div class="card">
	<h2><?php esc_html_e( 'What ALTCHA does, and does not, send', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'The widget is served from this site — nothing is loaded from a content delivery network. The challenge is issued by this WordPress installation, the computation happens in the visitor\'s browser, and the answer is checked here. No request reaches any third party, and no behavioural signal is collected: the widget can record pointer and keyboard timings, and that is switched off.', 'ffcertificate' ); ?>
	</p>
</div>

</div>
