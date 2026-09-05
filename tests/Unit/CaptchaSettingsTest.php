<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Captcha\AltchaCaptcha;
use FreeFormCertificate\Core\Captcha\CaptchaSettings;

/**
 * Tests for the captcha settings guardrail (#1053 PR4).
 *
 * @covers \FreeFormCertificate\Core\Captcha\CaptchaSettings
 */
class CaptchaSettingsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $settings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Core\Captcha\CaptchaSettings' );

		$this->settings = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = false ) => 'ffc_settings' === $key ? $this->settings : $default
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// Work factor
	// ==================================================================

	/**
	 * @dataProvider complexities
	 * @param mixed $stored   Value in the option.
	 * @param int   $expected Value the runtime should see.
	 */
	public function test_the_work_factor_is_bounded_on_read( $stored, int $expected ): void {
		// Bounded on read, not only on save: a value written before a bound
		// moved — or by anything other than the settings form — still has to
		// land somewhere the widget can cope with.
		$this->settings = array( 'captcha_altcha_complexity' => $stored );

		$this->assertSame( $expected, CaptchaSettings::complexity() );
	}

	/**
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function complexities(): array {
		return array(
			'in range'          => array( 50000, 50000 ),
			'below the floor'   => array( 1, CaptchaSettings::COMPLEXITY_MIN ),
			'above the ceiling' => array( 999999999, CaptchaSettings::COMPLEXITY_MAX ),
			'zero'              => array( 0, CaptchaSettings::COMPLEXITY_MIN ),
			'negative'          => array( -5, CaptchaSettings::COMPLEXITY_MIN ),
			'numeric string'    => array( '75000', 75000 ),
			'not a number'      => array( 'muitos', 200000 ),
			'array'             => array( array( 1 ), 200000 ),
		);
	}

	public function test_the_ceiling_keeps_the_work_inside_the_widget_timeout(): void {
		// The widget gives up after 90 seconds. The ceiling is what stops a
		// typo from leaving visitors grinding into a timeout they cannot
		// diagnose, so it has to be a real bound, not a suggestion.
		$this->settings = array( 'captcha_altcha_complexity' => PHP_INT_MAX );

		$this->assertSame( CaptchaSettings::COMPLEXITY_MAX, CaptchaSettings::complexity() );
		$this->assertLessThan( PHP_INT_MAX, CaptchaSettings::COMPLEXITY_MAX );
	}

	public function test_the_issued_challenge_uses_the_configured_work_factor(): void {
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		$this->settings = array( 'captcha_altcha_complexity' => 20000 );

		$this->assertSame( 20000, AltchaCaptcha::create_challenge()['maxnumber'] );
	}

	// ==================================================================
	// Lifetime
	// ==================================================================

	public function test_the_lifetime_is_bounded_the_same_way(): void {
		$this->settings = array( 'captcha_altcha_ttl' => 5 );
		$this->assertSame( CaptchaSettings::TTL_MIN, CaptchaSettings::ttl() );

		$this->settings = array( 'captcha_altcha_ttl' => 99999 );
		$this->assertSame( CaptchaSettings::TTL_MAX, CaptchaSettings::ttl() );
	}

	// ==================================================================
	// Widget options
	// ==================================================================

	public function test_unknown_attribute_values_fall_back_rather_than_reaching_the_page(): void {
		// These end up in HTML attributes the widget parses; an unrecognised
		// value is not rejected by the element, it is simply ignored, so the
		// safe default has to be substituted here.
		$this->settings = array(
			'captcha_altcha_type'    => 'radio',
			'captcha_altcha_auto'    => 'sometimes',
			'captcha_altcha_display' => 'sideways',
			'captcha_altcha_theme'   => 'neon',
		);

		$this->assertSame(
			array(
				'type'    => 'checkbox',
				'auto'    => 'off',
				'display' => 'standard',
				'theme'   => '',
			),
			CaptchaSettings::widget_attributes()
		);
	}

	public function test_configured_attribute_values_are_honoured(): void {
		$this->settings = array(
			'captcha_altcha_type'    => 'switch',
			'captcha_altcha_auto'    => 'onfocus',
			'captcha_altcha_display' => 'bar',
			'captcha_altcha_theme'   => 'dark',
		);

		$this->assertSame(
			array(
				'type'    => 'switch',
				'auto'    => 'onfocus',
				'display' => 'bar',
				'theme'   => 'dark',
			),
			CaptchaSettings::widget_attributes()
		);
	}

	public function test_the_privacy_choices_are_not_configurable(): void {
		// An administrator turning `humanInteractionSignature` back on would
		// change what the site has to disclose under the LGPD without being
		// told so, which is why it is not a setting.
		$this->settings = array(
			'captcha_altcha_hide_logo'  => 1,
			'humanInteractionSignature' => 1,
			'setCookie'                 => 'ffc',
		);

		$config = CaptchaSettings::widget_configuration();

		$this->assertFalse( $config['humanInteractionSignature'] );
		$this->assertNull( $config['setCookie'] );
		$this->assertTrue( $config['hideLogo'] );
	}

	public function test_the_configuration_carries_only_non_attribute_options(): void {
		// The 3.x element accepts nine attributes and these are not among
		// them; putting an actual attribute in here would silently do nothing.
		$config = CaptchaSettings::widget_configuration();

		foreach ( array( 'type', 'auto', 'display', 'theme', 'name', 'challenge', 'language', 'workers' ) as $attribute ) {
			$this->assertArrayNotHasKey( $attribute, $config );
		}
	}
}
