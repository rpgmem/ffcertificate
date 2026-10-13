<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The ordered three-level settings that moved from a select to the
 * steps control (#1745) stay steps controls, keep posting the values the
 * select posted, and list them mildest first.
 *
 * Static on purpose: two of the three files are views, outside the coverage
 * scope and rendered by no unit test. The geofence metabox and the calendar
 * tabs are also asserted rendered, in their own tests.
 *
 * @coversNothing
 */
class StepsControlAdoptionTest extends TestCase {

	/**
	 * File => [ field name => values, mildest first ].
	 */
	private const ADOPTED = array(
		'includes/admin/class-ffc-form-editor-geofence-metabox.php' => array(
			"'ffc_geofence[' . \$key . ']'" => array( 'title_message', 'message', 'hide' ),
		),
		'templates/admin/audience/self-scheduling-tab.php'          => array(
			"'ffc_ss_private_display_mode'" => array( 'show_title_message', 'show_message', 'hide' ),
		),
		'templates/admin/audience/audience-tab.php'                 => array(
			"'ffc_aud_private_display_mode'" => array( 'show_title_message', 'show_message', 'hide' ),
		),
		'includes/settings/views/ffc-tab-geolocation.php'           => array(
			"'api_fallback'" => array( 'allow', 'gps_only', 'block' ),
		),
	);

	/**
	 * The select names that must not come back.
	 */
	private const RETIRED_SELECTS = array(
		'ffc_geofence[datetime_hide_mode_before]',
		'ffc_geofence[datetime_hide_mode_during]',
		'ffc_geofence[datetime_hide_mode_after]',
		'ffc_geofence[geo_hide_mode]',
		'ffc_ss_private_display_mode',
		'ffc_aud_private_display_mode',
		'api_fallback',
	);

	private static function source( string $relative ): string {
		$path = dirname( __DIR__, 2 ) . '/' . $relative;
		self::assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_each_setting_is_a_switch_with_its_values_in_order(): void {
		foreach ( self::ADOPTED as $file => $fields ) {
			$src = self::source( $file );
			$this->assertStringContainsString( 'render_steps(', $src, $file );
			foreach ( $fields as $name => $values ) {
				$this->assertMatchesRegularExpression( "/'name'\s*=>\s*" . preg_quote( $name, '/' ) . '/', $src, "{$file}: {$name}" );
				$pattern = "/'options'\s*=>\s*array\(\s*'" . implode( "'\s*=>[^\n]*\n\s*'", array_map( static fn( $v ) => preg_quote( $v, '/' ), $values ) ) . "'\s*=>/";
				$this->assertMatchesRegularExpression( $pattern, $src, "{$file}: {$name} lists " . implode( ', ', $values ) . ' in that order' );
			}
		}
	}

	public function test_no_select_is_left_for_the_adopted_settings(): void {
		$found = array();
		foreach ( array_keys( self::ADOPTED ) as $file ) {
			$src = self::source( $file );
			foreach ( self::RETIRED_SELECTS as $name ) {
				if ( preg_match( '/<select[^>]*name="' . preg_quote( $name, '/' ) . '"/', $src ) ) {
					$found[] = "{$file}: {$name}";
				}
			}
			$this->assertDoesNotMatchRegularExpression( '/<select[^>]*name="ffc_geofence\[(datetime_hide_mode_|geo_hide_mode)/', $src, $file );
		}
		$this->assertSame( array(), $found );
	}

	public function test_a_stored_value_the_switch_does_not_know_shows_the_old_default(): void {
		$this->assertStringContainsString( "'default'      => 'message'", self::source( 'includes/admin/class-ffc-form-editor-geofence-metabox.php' ) );
		$this->assertStringContainsString( "'default'      => 'show_message'", self::source( 'templates/admin/audience/self-scheduling-tab.php' ) );
		$this->assertStringContainsString( "'default'      => 'show_message'", self::source( 'templates/admin/audience/audience-tab.php' ) );
		$this->assertStringContainsString( "'default'      => 'gps_only'", self::source( 'includes/settings/views/ffc-tab-geolocation.php' ) );
	}

	public function test_only_the_access_scale_reads_as_a_severity(): void {
		// Blocking access is the harsh end of the IP fallback; the display
		// modes have no worse end, so they keep the neutral primary palette.
		$this->assertStringContainsString( "'palette'      => 'severity'", self::source( 'includes/settings/views/ffc-tab-geolocation.php' ) );
		foreach ( array( 'includes/admin/class-ffc-form-editor-geofence-metabox.php', 'templates/admin/audience/self-scheduling-tab.php', 'templates/admin/audience/audience-tab.php' ) as $file ) {
			$this->assertStringNotContainsString( "'palette'", self::source( $file ), $file );
		}
	}
}
