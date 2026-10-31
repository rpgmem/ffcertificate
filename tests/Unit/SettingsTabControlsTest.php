<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The controls and wording #1758 gave the Captcha, Cache and SMTP tabs.
 *
 * Static on purpose, like the other adoption guards: the views are outside
 * the coverage scope and rendered by no unit test. The save paths behind
 * them are asserted in `SettingsSaveHandlerTest`, `TabGeolocationTest`,
 * `TabIpDiagnosticsTest` and `EmailHandlerTest`.
 *
 * @coversNothing
 */
class SettingsTabControlsTest extends TestCase {

	private static function source( string $relative ): string {
		$path = dirname( __DIR__, 2 ) . '/' . $relative;
		self::assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_the_captcha_mode_is_traffic_steps_from_math_to_altcha_only(): void {
		$src = self::source( 'includes/settings/views/ffc-tab-captcha.php' );

		$this->assertStringContainsString( 'render_steps(', $src );
		$this->assertStringContainsString( "'name'             => 'ffc_settings[captcha_provider]'", $src );
		$this->assertStringContainsString( "'palette'          => 'traffic'", $src );
		$this->assertMatchesRegularExpression(
			'/\$ffc_modes = array\(\s*MathCaptcha::ID\s*=>.*?CompositeCaptcha::ID\s*=>.*?AltchaCaptcha::ID\s*=>/s',
			$src,
			'Left to right: Math (red), ALTCHA + Math (amber), ALTCHA only (green).'
		);
		$this->assertStringNotContainsString( 'type="radio"', $src, 'No stacked radio is left for the mode.' );
	}

	public function test_the_altcha_only_stop_is_disabled_without_https(): void {
		$this->assertStringContainsString(
			"'disabled_options' => \$ffc_is_ssl ? array() : array( AltchaCaptcha::ID ),",
			self::source( 'includes/settings/views/ffc-tab-captcha.php' )
		);
		$this->assertStringContainsString(
			'AltchaCaptcha::ID === $requested && ! is_ssl()',
			self::source( 'includes/admin/class-ffc-settings-save-handler.php' ),
			'The save-time refusal stays: a disabled stop is not a guard.'
		);
	}

	public function test_the_display_toggles_post_what_is_shown(): void {
		$src = self::source( 'includes/settings/views/ffc-tab-captcha.php' );

		$this->assertStringContainsString( "esc_html_e( 'Display', 'ffcertificate' )", $src );
		$this->assertStringNotContainsString( "'Attribution'", $src );
		// Whitespace-tolerant: the array alignment moves when a key is added.
		$this->assertMatchesRegularExpression( "/'name'\\s+=> 'ffc_settings\\[captcha_altcha_show_logo\\]'/", $src );
		$this->assertMatchesRegularExpression( "/'name'\\s+=> 'ffc_settings\\[captcha_altcha_show_footer\\]'/", $src );
		$this->assertMatchesRegularExpression( "/'checked'\\s+=> 1 !== \\\$ffc_hide_logo/", $src );
		$this->assertMatchesRegularExpression( "/'checked'\\s+=> 1 !== \\\$ffc_hide_footer/", $src );
		$this->assertStringNotContainsString( 'name="ffc_settings[captcha_altcha_hide_', $src, 'The stored keys are no longer posted directly.' );
	}

	public function test_the_smtp_mode_locks_while_total_mail_queue_is_active(): void {
		$src = self::source( 'includes/settings/views/ffc-tab-smtp.php' );

		$this->assertStringContainsString( '$ffcertificate_mail_queue = \FreeFormCertificate\Integrations\MailQueue::is_active();', $src );
		$this->assertStringContainsString( '<fieldset id="smtp-mode-options"<?php disabled( $ffcertificate_mail_queue ); ?>>', $src );
		$this->assertStringContainsString( 'Total Mail Queue is active and manages email delivery', $src );
	}
}
