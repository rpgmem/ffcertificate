<?php
/**
 * Tests for the readable-foreground helper (#1126).
 *
 * The defect this replaces is not "a hardcoded colour" in the abstract: it is
 * that `color:#333` was written INLINE over a background an administrator
 * picks. Inline beats the stylesheet, so tokenising those badge classes had no
 * effect at all while it was there — and `#333` over a navy or a dark green is
 * unreadable.
 *
 * @covers \FreeFormCertificate\Core\ContrastColor
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ContrastColor;

final class ContrastColorTest extends TestCase {

	private const DARK  = '#000000';
	private const LIGHT = '#ffffff';

	protected function setUp(): void {
		parent::setUp();
		// pcov attribution (CLAUDE.md gotcha).
		class_exists( '\FreeFormCertificate\Core\ContrastColor' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provider_backgrounds(): array {
		return array(
			// Light grounds take the dark text.
			'branco'            => array( '#ffffff', self::DARK ),
			'amarelo pálido'    => array( '#fff8e5', self::DARK ),
			'verde claro'       => array( '#e7f5ec', self::DARK ),
			// Dark grounds take white — the cases `#333` got wrong.
			'azul-marinho'      => array( '#123a63', self::LIGHT ),
			'verde escuro'      => array( '#0a5c1a', self::LIGHT ),
			'quase preto'       => array( '#111111', self::LIGHT ),
			// Shorthand hex is a legitimate value in a colour field.
			'curto claro'       => array( '#fff', self::DARK ),
			'curto escuro'      => array( '#036', self::LIGHT ),
			// Without the leading hash, as a settings field may well store it.
			'sem cerquilha'     => array( '2c3338', self::LIGHT ),
		);
	}

	/**
	 * @dataProvider provider_backgrounds
	 *
	 * @param string $background Background colour.
	 * @param string $expected   Expected foreground.
	 */
	public function test_it_picks_the_readable_end( string $background, string $expected ): void {
		$this->assertSame( $expected, ContrastColor::on( $background ) );
	}

	/**
	 * Garbage in must not produce a colour that hides the text.
	 *
	 * The dark end is the safe fallback because it is what both call sites used
	 * before this class existed: an unparseable value keeps the old behaviour
	 * rather than inventing a new one.
	 */
	public function test_an_unparseable_value_falls_back_to_the_dark_end(): void {
		foreach ( array( '', 'transparent', 'var(--ffc-primary)', '#12345', 'rgb(1,2,3)' ) as $bad ) {
			$this->assertSame( self::DARK, ContrastColor::on( $bad ), "Entrada: {$bad}" );
		}
	}

	/**
	 * The chosen end must actually clear the WCAG floor for normal text.
	 *
	 * This is the assertion that makes the class worth having: it measures the
	 * result instead of trusting the branch. Mid-tones are the interesting part
	 * — that is exactly where a fixed `#333` stops working.
	 */
	public function test_the_choice_clears_the_contrast_floor_for_normal_text(): void {
		$luminance = static function ( string $hex ): float {
			$hex = ltrim( $hex, '#' );
			$out = 0.0;
			foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
				$c    = ( (int) hexdec( substr( $hex, $i * 2, 2 ) ) ) / 255;
				$lin  = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
				$out += $weight * $lin;
			}
			return $out;
		};
		$ratio = static function ( string $a, string $b ) use ( $luminance ): float {
			$la = $luminance( $a );
			$lb = $luminance( $b );
			return $la > $lb ? ( $la + 0.05 ) / ( $lb + 0.05 ) : ( $lb + 0.05 ) / ( $la + 0.05 );
		};

		$worst = 21.0;
		$at    = '';
		foreach ( range( 0, 255, 15 ) as $r ) {
			foreach ( range( 0, 255, 15 ) as $g ) {
				foreach ( range( 0, 255, 15 ) as $b ) {
					$bg  = sprintf( '#%02x%02x%02x', $r, $g, $b );
					$got = $ratio( ContrastColor::on( $bg ), $bg );
					if ( $got < $worst ) {
						$worst = $got;
						$at    = $bg;
					}
				}
			}
		}

		// The floor a two-candidate chooser can guarantee over the whole cube is
		// the ratio at the crossover: 4,58:1 for pure black/white. It is NOT a
		// free number — with the palette's #1d2327 as the dark end this sweep
		// reports 3,99:1 at #d25a3c, which is why the class uses #000000.
		$this->assertGreaterThanOrEqual(
			4.5,
			$worst,
			"O pior fundo do cubo ({$at}) fica em " . round( $worst, 2 ) . ':1, abaixo do mínimo.'
		);
	}

	/**
	 * The fixed `#333` this replaced fails the same sweep — by a lot.
	 *
	 * Without this the test above would prove only that *some* answer passes,
	 * not that the change was needed.
	 */
	public function test_the_fixed_colour_it_replaced_would_fail_the_same_sweep(): void {
		$luminance = static function ( string $hex ): float {
			$hex = ltrim( $hex, '#' );
			$out = 0.0;
			foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
				$c    = ( (int) hexdec( substr( $hex, $i * 2, 2 ) ) ) / 255;
				$lin  = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
				$out += $weight * $lin;
			}
			return $out;
		};

		$l_fixed = $luminance( '#333333' );
		$failed  = 0;
		foreach ( range( 0, 255, 15 ) as $r ) {
			foreach ( range( 0, 255, 15 ) as $g ) {
				foreach ( range( 0, 255, 15 ) as $b ) {
					$l   = $luminance( sprintf( '#%02x%02x%02x', $r, $g, $b ) );
					$rat = $l > $l_fixed ? ( $l + 0.05 ) / ( $l_fixed + 0.05 ) : ( $l_fixed + 0.05 ) / ( $l + 0.05 );
					if ( $rat < 4.5 ) {
						++$failed;
					}
				}
			}
		}

		$this->assertGreaterThan( 500, $failed, 'O #333 fixo deveria reprovar em centenas de fundos.' );
	}
}
