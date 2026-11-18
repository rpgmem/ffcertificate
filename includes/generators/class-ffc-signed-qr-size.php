<?php
/**
 * The size a signed QR code needs to be read.
 *
 * @package FreeFormCertificate\Generators
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

use FreeFormCertificate\Core\CertificateRecord;
use FreeFormCertificate\Core\SigningKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How large a certificate's QR code must print for each QR mode (#2639).
 *
 * The rule is a module size, not a pixel count: about 0.3 mm per module is
 * where phone cameras read printed codes reliably. The page is 1123 px for
 * 297 mm, so a pixel is 297/1123 mm. A mode's minimum is measured on a
 * representative payload (a long name and course), encoded by the same
 * library that draws the code, so the figure moves with the payload format
 * rather than with a number written here.
 */
final class SignedQrSize {

	/** Module size phones read reliably on paper, in millimetres. */
	public const MODULE_MM = 0.3;

	/** Page pixels per millimetre: 1123 px across 297 mm. */
	public const PX_PER_MM = 1123 / 297;

	/** Quiet zone, in modules, the mode minimums assume. */
	public const QUIET_ZONE = 2;

	/**
	 * Minimums already measured this request, by mode and level.
	 *
	 * @var array<string, int>
	 */
	private static array $cache = array();

	/**
	 * The modules across a code that carries a text.
	 *
	 * @param string $text Payload.
	 * @param string $ecc  Error level.
	 */
	public static function modules( string $text, string $ecc = 'M' ): int {
		return count( QrSvgRenderer::matrix( $text, $ecc ) );
	}

	/**
	 * The smallest box, in page pixels, that prints a code of this many
	 * modules at a readable module size.
	 *
	 * @param int $modules Modules across, without the quiet zone.
	 * @param int $margin  Quiet zone the box includes, in modules.
	 */
	public static function px_for( int $modules, int $margin ): int {
		return (int) ceil( ( $modules + 2 * $margin ) * self::MODULE_MM * self::PX_PER_MM );
	}

	/**
	 * A mode's minimum, in page pixels, at the error level the code is drawn
	 * with and with the standard quiet zone.
	 *
	 * @param string $mode One of the CertificateSigner modes.
	 * @param string $ecc  Error level.
	 */
	public static function minimum_px( string $mode, string $ecc = 'M' ): int {
		$key = $mode . '|' . $ecc;
		if ( ! isset( self::$cache[ $key ] ) ) {
			self::$cache[ $key ] = self::px_for( self::modules( self::sample( $mode ), $ecc ), self::QUIET_ZONE );
		}
		return self::$cache[ $key ];
	}

	/**
	 * The link a QR code of this mode would carry for a long name and
	 * course: what the minimum is measured on.
	 *
	 * @param string $mode One of the CertificateSigner modes.
	 */
	public static function sample( string $mode ): string {
		$link = 'https://www.exemplo.sp.gov.br/valid/#token=' . str_repeat( 'a', 32 );
		if ( 'compact' !== $mode && 'full' !== $mode ) {
			return $link;
		}
		$fields  = array(
			'auth_code' => 'A1B2C3D4E5F6',
			'issued'    => '2026-11-17',
			'name'      => 'Maria Aparecida da Silva Santos Oliveira',
			'course'    => 'Formação Continuada em Língua Brasileira de Sinais',
			'workload'  => '120',
		);
		$hash    = str_repeat( '0', 64 );
		$payload = 'full' === $mode
			? CertificateRecord::full_payload( 'k1', $fields, $hash )
			: CertificateRecord::compact_payload( 'k1', $fields, $hash );
		return $link . '&s=' . SigningKeys::b64( $payload ) . '.' . str_repeat( 'A', 86 );
	}
}
