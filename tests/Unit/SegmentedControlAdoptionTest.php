<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The small unordered choices that moved to the segmented control (#1750)
 * stay segmented controls, post exactly the values their save path accepts,
 * keep their defaults, and keep autosaving where they did.
 *
 * Static on purpose: the General tab is a view, outside the coverage scope
 * and rendered by no unit test.
 *
 * @coversNothing
 */
class SegmentedControlAdoptionTest extends TestCase {

	/**
	 * File => [ field name => [ values, default, autosave key or null ] ].
	 */
	private const ADOPTED = array(
		'includes/settings/views/ffc-tab-general.php'               => array(
			'ffc_settings[dark_mode]'         => array( array( 'off', 'on', 'auto' ), 'off', 'dark_mode' ),
			'ffc_settings[code_editor_theme]' => array( array( 'auto', 'light', 'dark' ), 'dark', 'code_editor_theme' ),
		),
		'includes/admin/class-ffc-form-editor-geofence-metabox.php' => array(
			'ffc_geofence[time_mode]' => array( array( 'span', 'daily' ), 'daily', null ),
		),
	);

	private static function source( string $relative ): string {
		$path = dirname( __DIR__, 2 ) . '/' . $relative;
		self::assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	/**
	 * The render_segmented() call that names the field.
	 */
	private static function call_for( string $src, string $name ): string {
		$pos = strpos( $src, "'name'    => '{$name}'" );
		if ( false === $pos ) {
			$pos = strpos( $src, "'name'         => '{$name}'" );
		}
		self::assertNotFalse( $pos, "{$name} is passed to a control" );
		$start = strrpos( substr( $src, 0, $pos ), 'render_' );
		self::assertNotFalse( $start );
		$end = strpos( $src, "\n\t\t\t\t\t\t);", $pos );
		$end = false === $end ? strpos( $src, ');', $pos ) : $end;
		return substr( $src, $start, (int) $end - $start );
	}

	public function test_each_setting_is_a_segmented_control_with_its_values_default_and_autosave(): void {
		foreach ( self::ADOPTED as $file => $fields ) {
			$src = self::source( $file );
			foreach ( $fields as $name => $spec ) {
				list( $values, $default, $autosave ) = $spec;
				$call                                = self::call_for( $src, $name );

				$this->assertStringStartsWith( 'render_segmented(', $call, "{$file}: {$name}" );
				preg_match( "/'options'\s*=>\s*array\((.*?)\n\s*\),/s", $call, $m );
				$this->assertNotEmpty( $m, "{$file}: {$name} has options" );
				preg_match_all( "/^\s*'([a-z_]+)'\s*=>/m", $m[1], $keys );
				$this->assertSame( $values, $keys[1], "{$file}: {$name} posts exactly the values its save path accepts" );
				$this->assertMatchesRegularExpression( "/'default'\s*=>\s*'" . preg_quote( $default, '/' ) . "'/", $call, "{$file}: {$name} keeps its default" );
				if ( null !== $autosave ) {
					$this->assertStringContainsString( "'ffc-autosave-key' => '{$autosave}'", $call, "{$file}: {$name} still autosaves" );
				}
			}
		}
	}

	public function test_the_values_match_what_the_settings_save_handler_accepts(): void {
		$handler = self::source( 'includes/admin/class-ffc-settings-save-handler.php' );
		$this->assertStringContainsString( "\$allowed_modes      = array( 'off', 'on', 'auto' );", $handler );
		$this->assertStringContainsString( "\$allowed_themes             = array( 'auto', 'light', 'dark' );", $handler );
	}

	public function test_no_select_or_stacked_radio_is_left_for_the_adopted_settings(): void {
		foreach ( self::ADOPTED as $file => $fields ) {
			$src = self::source( $file );
			foreach ( array_keys( $fields ) as $name ) {
				$quoted = preg_quote( $name, '/' );
				$this->assertDoesNotMatchRegularExpression( '/<select[^>]*name="' . $quoted . '"/', $src, "{$file}: {$name}" );
				$this->assertDoesNotMatchRegularExpression( '/<input type="radio" name="' . $quoted . '"/', $src, "{$file}: {$name}" );
			}
		}
	}
}
