<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssSelectors;
use PHPUnit\Framework\TestCase;

/**
 * One standard for field feedback (#1844).
 *
 * An audit found five error-message styles, six "invalid input" classes,
 * three required markers (two of them a bare `*` that read as part of the
 * label) and three counter markups. They became one: `.ffc-field-message`
 * printed by `FFC.fieldMessage()` or `Core\FieldMessage`, the
 * `is-invalid` / `is-warning` / `is-valid` states, one `span.ffc-required`
 * and one `.ffc-field-counter`. This keeps it one:
 *
 * - none of the retired names comes back, in markup, script or sheet;
 * - every required marker is the hidden span, so a screen reader hears the
 *   control's `required` once rather than "star";
 * - the marker, the message and the counter are styled in `ffc-common.css`
 *   alone, the one sheet every screen that draws a field loads.
 *
 * It reads the source as text and sees presence, not rendering; the render
 * check lives in the PR that introduced the standard.
 *
 * @coversNothing
 */
class FieldFeedbackMarkupTest extends TestCase {

	/**
	 * The names the standard replaced. A match is a whole class token.
	 *
	 * @var array<int, string>
	 */
	private const RETIRED = array(
		'ffc-rereg-field-error',
		'ffc-wh-row-error',
		'ffc-wh-row-invalid',
		'ffc-input-invalid',
		'ffc-datetime-order-error',
		'ffc-extend-end-error',
		'ffc-extend-end-input-invalid',
		'ffc-transfer-error',
		'ffc-char-count',
		'ffc-length-counter',
		'ffc-select-counter',
	);

	/**
	 * Classes the standard styles, and the one sheet allowed to.
	 *
	 * @var array<int, string>
	 */
	private const OWNED = array( 'ffc-required', 'ffc-field-message', 'ffc-field-counter' );

	/**
	 * A sheet that may name one of those classes for layout, never for look,
	 * with the reason.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const LAYOUT_ONLY = array(
		'ffc-field-message' => array(
			'ffc-frontend.css' => 'pulls the message up into the bottom margin of the public form\'s own .ffc-input, a layout that sheet owns',
		),
	);

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Source files under includes/, templates/ and assets/, minified bundles
	 * and source maps left out.
	 *
	 * @return array<string, string> Relative path => contents.
	 */
	private static function sources(): array {
		$out = array();
		foreach ( array( 'includes', 'templates', 'assets/js', 'assets/css' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$path = (string) $file;
				if ( ! preg_match( '/\.(php|js|css)$/', $path ) || preg_match( '/\.min\.(js|css)$/', $path ) ) {
					continue;
				}
				$out[ substr( $path, strlen( self::root() ) + 1 ) ] = (string) file_get_contents( $path );
			}
		}
		ksort( $out );
		return $out;
	}

	public function test_no_retired_name_comes_back(): void {
		$found = array();
		foreach ( self::sources() as $path => $code ) {
			foreach ( self::RETIRED as $name ) {
				if ( preg_match( '/(?<![\w-])' . preg_quote( $name, '/' ) . '(?![\w-])/', $code ) ) {
					$found[] = $path . ': ' . $name;
				}
			}
		}
		$this->assertSame( array(), $found, 'Use the field-message standard: FFC.fieldMessage() / Core\FieldMessage, the is-invalid state, .ffc-field-counter.' );
	}

	public function test_every_required_marker_is_the_hidden_span(): void {
		$bad   = array();
		$count = 0;
		foreach ( self::sources() as $path => $code ) {
			if ( ! preg_match_all( '/<span class=\\\\?["\']ffc-required\\\\?["\'][^>]*>/', $code, $m ) ) {
				continue;
			}
			foreach ( $m[0] as $tag ) {
				++$count;
				if ( ! str_contains( $tag, 'aria-hidden=' ) ) {
					$bad[] = $path . ': ' . $tag;
				}
			}
		}
		$this->assertSame( array(), $bad, 'A required marker is <span class="ffc-required" aria-hidden="true">*</span>; the control carries `required`.' );

		// The scan reached the three places markers are written: a PHP
		// renderer, a template and a script.
		$sources = self::sources();
		foreach ( array( 'includes/frontend/class-ffc-form-field-renderer.php', 'templates/reregistration/field-working-hours.php', 'assets/js/ffc-user-dashboard-profile.js' ) as $member ) {
			$this->assertArrayHasKey( $member, $sources );
			$this->assertStringContainsString( 'ffc-required', $sources[ $member ], $member . ' no longer prints a marker: re-point this self-check.' );
		}
		$this->assertGreaterThan( 0, $count );
	}

	public function test_no_label_ends_in_a_bare_asterisk(): void {
		$found = array();
		foreach ( self::sources() as $path => $code ) {
			if ( preg_match_all( '/(?:\?>|[\'"])\s*\*\s*<\/(?:label|th|legend)>/', $code, $m ) ) {
				$found[] = $path . ': ' . implode( ' | ', $m[0] );
			}
			if ( preg_match_all( "/\\+ ' \\*'/", $code, $m ) ) {
				$found[] = $path . ': ' . implode( ' | ', $m[0] );
			}
		}
		$this->assertSame( array(), $found, 'A bare `*` reads as part of the label; use the .ffc-required span.' );
	}

	public function test_the_standard_is_styled_in_the_common_sheet_alone(): void {
		$owners = array();
		foreach ( CssSelectors::sheets() as $sheet ) {
			$css = (string) file_get_contents( $sheet );
			foreach ( CssSelectors::of( $css ) as $selector ) {
				foreach ( self::OWNED as $class ) {
					if ( preg_match( '/\.' . preg_quote( $class, '/' ) . '(?![\w-])/', $selector ) ) {
						$owners[ $class ][ basename( $sheet ) ] = true;
					}
				}
			}
		}
		foreach ( self::OWNED as $class ) {
			$sheets = array_diff( array_keys( $owners[ $class ] ?? array() ), array_keys( self::LAYOUT_ONLY[ $class ] ?? array() ) );
			$this->assertSame( array( 'ffc-common.css' ), array_values( $sheets ), '.' . $class . ' is styled in ffc-common.css only.' );
		}
		foreach ( self::LAYOUT_ONLY as $class => $sheets ) {
			foreach ( $sheets as $sheet => $reason ) {
				$this->assertGreaterThan( 15, strlen( $reason ) );
				$this->assertArrayHasKey( $sheet, $owners[ $class ] ?? array(), $sheet . ' no longer names .' . $class . ': drop it from LAYOUT_ONLY.' );
			}
		}
	}
}
