<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceCheckinSettings;
use FreeFormCertificate\Audience\AudienceTerminalPairing;
use PHPUnit\Framework\TestCase;

/**
 * The check-in settings (#2747, #2748): read from `ffc_settings`, clamped on
 * read, and what the terminal limits and the auto-end follow.
 *
 * @covers \FreeFormCertificate\Audience\AudienceCheckinSettings
 * @covers \FreeFormCertificate\Audience\AudienceTerminalPairing::limits
 */
class AudienceCheckinSettingsTest extends TestCase {

	/** @var array<string, mixed> The `ffc_settings` option. */
	private array $settings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias( fn ( $key, $default = false ) => 'ffc_settings' === $key ? $this->settings : $default );
		Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $value );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_defaults_apply_when_nothing_is_stored(): void {
		$this->assertSame( 10, AudienceCheckinSettings::site_limit() );
		$this->assertSame( 3, AudienceCheckinSettings::event_limit() );
		$this->assertSame( 2 * HOUR_IN_SECONDS, AudienceCheckinSettings::auto_end_seconds() );
		$this->assertFalse( AudienceCheckinSettings::camera_allowed() );
		$this->assertTrue( AudienceCheckinSettings::default_path_qr(), 'A new event takes the projected code by default.' );
		$this->assertTrue( AudienceCheckinSettings::default_path_terminal(), 'A new event takes terminals by default.' );
	}

	public function test_stored_values_are_read_and_clamped(): void {
		$this->settings = array(
			'terminal_site_limit'     => '25',
			'terminal_event_limit'    => 500,
			'terminal_auto_end_hours' => 0,
			'terminal_camera'         => '1',
		);
		$this->assertSame( 25, AudienceCheckinSettings::site_limit() );
		$this->assertSame( AudienceCheckinSettings::EVENT_LIMIT_MAX, AudienceCheckinSettings::event_limit(), 'above the bound' );
		$this->assertSame( HOUR_IN_SECONDS, AudienceCheckinSettings::auto_end_seconds(), 'below the floor' );
		$this->assertTrue( AudienceCheckinSettings::camera_allowed() );

		$this->settings = array(
			'checkin_path_qr'       => '0',
			'checkin_path_terminal' => 0,
		);
		$this->assertFalse( AudienceCheckinSettings::default_path_qr() );
		$this->assertFalse( AudienceCheckinSettings::default_path_terminal() );

		$this->settings = array( 'terminal_site_limit' => 'lots' );
		$this->assertSame( AudienceCheckinSettings::SITE_LIMIT, AudienceCheckinSettings::site_limit(), 'not a number' );
	}

	public function test_the_terminal_limits_follow_the_settings_and_then_the_filter(): void {
		$this->settings = array(
			'terminal_site_limit'  => 4,
			'terminal_event_limit' => 2,
		);
		$this->assertSame(
			array(
				'site'    => 4,
				'booking' => 2,
			),
			AudienceTerminalPairing::limits()
		);

		Functions\when( 'apply_filters' )->alias(
			static fn ( $hook, $value ) => 'ffc_terminal_limits' === $hook ? array( 'site' => 7 ) : $value
		);
		$this->assertSame(
			array(
				'site'    => 7,
				'booking' => 2,
			),
			AudienceTerminalPairing::limits(),
			'a filter may still change one and leave the other'
		);
	}
}
