<?php
/**
 * QR code capacity, measured by the encoder itself.
 *
 * @package FreeFormCertificate\Generators
 * @since   6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How full a QR code is, and how much more it holds (#1584).
 *
 * A fixed byte table cannot answer this. The encoder packs digits and
 * upper-case runs more densely than bytes, and the bundled encoder gives up
 * a few bytes before the theoretical capacity, because its iterative version
 * estimate overshoots near the limit. So every answer here comes from running
 * the encoder's own split and `QRinput::convertData()`, the exact steps
 * `QRcode::text()` takes before masking. Masking is what makes encoding slow,
 * and it never changes whether the data fits.
 */
final class QrCapacity {

	/**
	 * From this version on the modules get small enough that a code printed
	 * at a usual size stops scanning reliably: a warning, not a refusal.
	 */
	public const DENSE_VERSION = 10;

	/** Largest QR code version. */
	private const MAX_VERSION = 40;

	/**
	 * How far below the bit-count bound the encoder's real limit can sit, in
	 * bytes. Measured at most 15; the margin only narrows the search.
	 */
	private const SEARCH_MARGIN = 48;

	/** A character the remaining room is counted in: one byte of plain text. */
	private const FILLER = 'a';

	/**
	 * Measure a payload at the error-correction level the code is drawn with.
	 *
	 * `remaining` counts ordinary characters (one byte each); an accented
	 * letter takes two. When the payload does not fit, `over` is roughly how
	 * many characters to remove.
	 *
	 * @param string $payload Encoded text.
	 * @param string $ecc     Error correction level actually used.
	 * @param int    $side    Modules per side of the drawn matrix (0 if none).
	 * @return array{bytes: int, capacity: int, remaining: int, over: int, percent: int, version: int, dense: bool, level: string}
	 */
	public static function measure( string $payload, string $ecc, int $side ): array {
		$ecc     = self::level_name( $ecc );
		$bytes   = strlen( $payload );
		$version = $side >= 21 ? (int) ( ( $side - 17 ) / 4 ) : 0;

		$remaining = 0;
		$over      = 0;
		if ( self::fits( $payload, $ecc ) ) {
			$remaining = self::remaining( $payload, $ecc );
		} else {
			$over = $bytes - self::longest_prefix( $payload, $ecc );
		}

		$capacity = max( 1, $bytes + $remaining );

		return array(
			'bytes'     => $bytes,
			'capacity'  => $capacity,
			'remaining' => $remaining,
			'over'      => $over,
			'percent'   => $over > 0 ? 100 : (int) min( 100, (int) ceil( $bytes * 100 / $capacity ) ),
			'version'   => $version,
			'dense'     => $version >= self::DENSE_VERSION,
			'level'     => $ecc,
		);
	}

	/**
	 * The highest error-correction level, at or above the one asked for,
	 * that draws the text on the same grid (#2648).
	 *
	 * A level's code grows only in whole versions, so a text usually leaves
	 * part of its version unused. When the text also fits that version at a
	 * higher level, the spare room becomes error correction at no cost in
	 * size: the code survives more damage and prints exactly as large. The
	 * version is read the way the encoder reads it, through
	 * `QRinput::convertData()`, without the masking that makes encoding slow.
	 *
	 * @param string $text Text to encode.
	 * @param string $ecc  The level asked for.
	 * @return string L, M, Q or H; the level asked for when nothing higher is free.
	 */
	public static function free_level( string $text, string $ecc ): string {
		$ecc     = self::level_name( $ecc );
		$order   = array( 'L', 'M', 'Q', 'H' );
		$version = self::version( $text, $ecc );
		if ( $version <= 0 ) {
			return $ecc;
		}
		$best = $ecc;
		foreach ( array_slice( $order, (int) array_search( $ecc, $order, true ) + 1 ) as $higher ) {
			if ( self::version( $text, $higher ) !== $version ) {
				break;
			}
			$best = $higher;
		}
		return $best;
	}

	/**
	 * The level to draw the text with: the one asked for, raised where the
	 * grid has room ({@see self::free_level()}), or lowered until the text
	 * fits (#2660).
	 *
	 * Lowering trades damage tolerance for room -- L holds about 2.3 times
	 * what H holds -- and a code that scans is worth more than none. Null
	 * when the text does not fit even at L, which the caller reports.
	 *
	 * @param string $text Text to encode.
	 * @param string $ecc  The level asked for.
	 * @return string|null L, M, Q or H, or null when nothing fits.
	 */
	public static function fit_level( string $text, string $ecc ): ?string {
		$ecc = self::level_name( $ecc );
		if ( self::fits( $text, $ecc ) ) {
			return self::free_level( $text, $ecc );
		}
		$order = array( 'L', 'M', 'Q', 'H' );
		foreach ( array_reverse( array_slice( $order, 0, (int) array_search( $ecc, $order, true ) ) ) as $lower ) {
			if ( self::fits( $text, $lower ) ) {
				return $lower;
			}
		}
		return null;
	}

	/**
	 * The version the encoder picks for the text at a level, or 0 when the
	 * text does not fit.
	 *
	 * @param string $text Text.
	 * @param string $ecc  Level.
	 * @return int
	 */
	private static function version( string $text, string $ecc ): int {
		$input = self::input( $text, $ecc );
		if ( null === $input ) {
			return 0;
		}
		try {
			return 0 === $input->convertData() ? (int) $input->getVersion() : 0;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Whether the encoder accepts the text at a level.
	 *
	 * @param string $text Text to encode.
	 * @param string $ecc  L, M, Q or H.
	 * @return bool
	 */
	public static function fits( string $text, string $ecc ): bool {
		$input = self::input( $text, $ecc );
		if ( null === $input ) {
			return false;
		}
		try {
			return 0 === $input->convertData();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * How many more filler characters fit after the payload.
	 *
	 * @param string $payload Text that fits.
	 * @param string $ecc     Level.
	 * @return int
	 */
	private static function remaining( string $payload, string $ecc ): int {
		$spare = (int) floor( ( self::data_bits( $ecc ) - self::bits( $payload, $ecc ) ) / 8 );

		return self::largest(
			static fn( int $k ): bool => self::fits( $payload . str_repeat( self::FILLER, $k ), $ecc ),
			max( 0, $spare + 2 )
		);
	}

	/**
	 * Length in bytes of the longest prefix of the payload that fits.
	 *
	 * @param string $payload Text that does not fit.
	 * @param string $ecc     Level.
	 * @return int
	 */
	private static function longest_prefix( string $payload, string $ecc ): int {
		$excess = (int) ceil( ( self::bits( $payload, $ecc ) - self::data_bits( $ecc ) ) / 8 );

		return self::largest(
			static fn( int $length ): bool => self::fits( substr( $payload, 0, $length ), $ecc ),
			max( 0, min( strlen( $payload ) - 1, strlen( $payload ) - max( 0, $excess ) ) )
		);
	}

	/**
	 * The largest k in [0, $upper] for which $fits holds, given that it holds
	 * for 0 and stops holding at some point. The bit-count bound is close to
	 * the real limit, so the search starts just below it.
	 *
	 * @param callable(int): bool $fits  Monotone predicate.
	 * @param int                 $upper Upper bound.
	 * @return int
	 */
	private static function largest( callable $fits, int $upper ): int {
		if ( $upper <= 0 ) {
			return 0;
		}
		$low = max( 0, $upper - self::SEARCH_MARGIN );
		if ( ! $fits( $low ) ) {
			$low = 0;
		}
		$high = $upper;
		while ( $low < $high ) {
			$mid = intdiv( $low + $high + 1, 2 );
			if ( $fits( $mid ) ) {
				$low = $mid;
			} else {
				$high = $mid - 1;
			}
		}
		return $low;
	}

	/**
	 * Bits the text takes at the largest version (its length fields are the
	 * widest there), or PHP_INT_MAX when it cannot be split.
	 *
	 * @param string $text Text.
	 * @param string $ecc  Level.
	 * @return int
	 */
	private static function bits( string $text, string $ecc ): int {
		$input = self::input( $text, $ecc );
		if ( null === $input ) {
			return PHP_INT_MAX;
		}
		try {
			$input->setVersion( self::MAX_VERSION );
			$bits = $input->createBitStream();
		} catch ( \Throwable $e ) {
			return PHP_INT_MAX;
		}
		return $bits < 0 ? PHP_INT_MAX : (int) $bits;
	}

	/**
	 * Data bits a version-40 code holds at a level.
	 *
	 * @param string $ecc Level.
	 * @return int
	 */
	private static function data_bits( string $ecc ): int {
		self::load();
		return (int) \QRspec::getDataLength( self::MAX_VERSION, self::level( $ecc ) ) * 8;
	}

	/**
	 * The encoder's input for the text, split into modes the way
	 * `QRcode::text()` splits it.
	 *
	 * @param string $text Text.
	 * @param string $ecc  Level.
	 * @return \QRinput|null
	 */
	private static function input( string $text, string $ecc ): ?\QRinput {
		if ( '' === $text ) {
			return null;
		}
		self::load();
		try {
			$input = new \QRinput( 0, self::level( $ecc ) );
			if ( \QRsplit::splitStringToQRinput( $text, $input, \QR_MODE_8, true ) < 0 ) {
				return null;
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return $input;
	}

	/**
	 * Normalise a level name.
	 *
	 * @param string $ecc Candidate.
	 * @return string L, M, Q or H.
	 */
	private static function level_name( string $ecc ): string {
		$ecc = strtoupper( $ecc );
		return in_array( $ecc, array( 'L', 'M', 'Q', 'H' ), true ) ? $ecc : 'M';
	}

	/**
	 * The encoder's constant for a level.
	 *
	 * @param string $ecc Level name.
	 * @return int
	 */
	private static function level( string $ecc ): int {
		$levels = array(
			'L' => \QR_ECLEVEL_L,
			'M' => \QR_ECLEVEL_M,
			'Q' => \QR_ECLEVEL_Q,
			'H' => \QR_ECLEVEL_H,
		);
		return $levels[ self::level_name( $ecc ) ];
	}

	/**
	 * Load the bundled encoder.
	 */
	private static function load(): void {
		if ( ! class_exists( '\\QRcode' ) ) {
			require_once FFC_PLUGIN_DIR . 'libs/phpqrcode/qrlib.php';
		}
	}
}
