<?php
/**
 * AdminLayoutTokensTest
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssSelectors;
use PHPUnit\Framework\TestCase;

/**
 * The admin layout reads its widths from tokens (#2486).
 *
 * Two vertical menus (200 and 220 px) and three side columns (280, 320 and
 * 406 px) had drifted apart because each component wrote its own number. The
 * widths now live once on `:root` in `ffc-common.css`, and every component
 * that draws the menu or the side column reads them, so changing the
 * standard is one edit. A width written as a literal in one of those rules
 * is the drift coming back.
 *
 * It reads the named components only, on purpose: a content grid that
 * happens to use 200 or 220 px (a capability list, a search field) is not a
 * layout column, and a blanket ban would allowlist them into silence.
 */
final class AdminLayoutTokensTest extends TestCase {

	/**
	 * Sheet => selector => property => the token it must read.
	 */
	private const READERS = array(
		'ffc-admin-components.css'      => array(
			'.ffc-vtabs__nav' => array( 'flex' => '--ffc-admin-nav-width' ),
			'.ffc-editor'     => array(
				'grid-template-columns' => '--ffc-admin-side-width',
				'gap'                   => '--ffc-admin-gap',
			),
			'.ffc-editor__side' => array(
				'top'        => '--ffc-admin-side-top',
				'max-height' => '--ffc-admin-side-max-height',
			),
		),
		'ffc-admin-settings.css'        => array(
			'.ffc-settings-tabs__nav'  => array( 'flex' => '--ffc-admin-nav-width' ),
			'.ffc-settings-tabs__body' => array(
				'grid-template-columns' => '--ffc-admin-side-width',
				'gap'                   => '--ffc-admin-gap',
			),
			'.ffc-settings-tabs__side' => array(
				'top'        => '--ffc-admin-side-top',
				'max-height' => '--ffc-admin-side-max-height',
			),
		),
		'ffc-admin-submission-edit.css' => array(
			'.ffc-edit-layout' => array(
				'grid-template-columns' => '--ffc-admin-side-width',
				'gap'                   => '--ffc-admin-gap',
			),
			'.ffc-edit-side'   => array(
				'top'        => '--ffc-admin-side-top',
				'max-height' => '--ffc-admin-side-max-height',
			),
		),
		'ffc-qr-generator.css'          => array(
			'.ffc-page-qr-generator .ffc-qr-generator__side' => array(
				'flex'       => '--ffc-admin-side-width',
				'top'        => '--ffc-admin-side-top',
				'max-height' => '--ffc-admin-side-max-height',
			),
		),
	);

	private const TOKENS = array( '--ffc-admin-nav-width', '--ffc-admin-side-width', '--ffc-admin-gap', '--ffc-admin-side-top', '--ffc-admin-side-max-height' );

	private static function css( string $sheet ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/' . $sheet );
	}

	/**
	 * The top-level rule whose selector is exactly `$selector` (outside any
	 * media query), or null.
	 */
	private static function body( string $sheet, string $selector ): ?string {
		$css = self::css( $sheet );
		// Top level only: a narrow-screen override inside @media restates the
		// column on purpose (one column), and is not the rule under test.
		$top = (string) preg_replace( '/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/s', '', $css );
		foreach ( CssSelectors::rules( $top ) as $rule ) {
			if ( trim( (string) preg_replace( '/\s+/', ' ', $rule['selector'] ) ) === $selector ) {
				return $rule['body'];
			}
		}
		return null;
	}

	public function test_the_layout_components_read_the_layout_tokens(): void {
		foreach ( self::READERS as $sheet => $rules ) {
			foreach ( $rules as $selector => $props ) {
				$body = self::body( $sheet, $selector );
				$this->assertNotNull( $body, "{$sheet}: no rule for {$selector}; re-point the guard if the component moved." );
				foreach ( $props as $prop => $token ) {
					$this->assertMatchesRegularExpression(
						'/(?:^|[;\s{])' . preg_quote( $prop, '/' ) . '\s*:[^;]*var\(\s*' . preg_quote( $token, '/' ) . '\s*\)/',
						(string) $body,
						"{$sheet} {$selector} must size its {$prop} with var({$token})."
					);
				}
			}
		}
	}

	public function test_the_tokens_are_declared_once_on_root(): void {
		$common = self::css( 'ffc-common.css' );
		foreach ( self::TOKENS as $token ) {
			$this->assertSame( 1, preg_match_all( '/' . preg_quote( $token, '/' ) . '\s*:/', $common ), "{$token} is declared once in ffc-common.css." );
		}
		foreach ( CssSelectors::sheets() as $path ) {
			if ( 'ffc-common.css' === basename( $path ) ) {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression( '/--ffc-admin-[a-z-]+\s*:/', (string) file_get_contents( $path ), basename( $path ) . ' redeclares a layout token.' );
		}
	}

	public function test_the_card_is_flat_with_the_large_radius(): void {
		$body = (string) self::body( 'ffc-admin-components.css', '.ffc-settings-wrap .card, .ffc-boxed .card' );
		$this->assertStringContainsString( 'var(--ffc-radius-lg)', $body );
		$this->assertStringNotContainsString( 'box-shadow', $body, 'The card is flat, like the collapsible section.' );
	}
}
