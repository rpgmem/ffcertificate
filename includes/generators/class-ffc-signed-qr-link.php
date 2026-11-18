<?php
/**
 * The verification link a signed certificate's QR code carries.
 *
 * @package FreeFormCertificate\Generators
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

use FreeFormCertificate\Core\CertificateRecord;
use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Submissions\CertificateSignatureRepository;
use FreeFormCertificate\Submissions\CertificateSigner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the signed payload to a certificate's verification link (#2639).
 *
 * The link stays the one the QR code always carried; the payload and its
 * signature ride in the fragment as `&s=<payload>.<signature>`, both base64url,
 * so a browser never sends them to the server. They are rebuilt from the
 * certificate's current signed record: what the QR code says is exactly what
 * the database holds signed, and a QR code printed before signing, or for a
 * form on Link only, keeps the plain link.
 *
 * The full payload is used only where it can be read: a placeholder that
 * prints the code too small for it gets the compact one, and one too small for
 * either keeps the plain link. A certificate is never printed with a QR code
 * nobody can scan.
 */
final class SignedQrLink {

	/** The fragment key the payload rides under. */
	public const FRAGMENT_KEY = 's';

	/**
	 * The signed fragments a certificate can carry, by mode, or null when
	 * its QR code carries the link only.
	 *
	 * @param int $submission_id Submission id.
	 * @return array{full: string, compact: string}|null Fragments without the `&s=`; full is '' on a compact form.
	 */
	public static function fragments( int $submission_id ): ?array {
		if ( $submission_id <= 0 || array() === SigningKeys::public_keys() ) {
			return null;
		}
		$signed = CertificateSignatureRepository::current( $submission_id );
		if ( null === $signed ) {
			return null;
		}
		$fields = CertificateRecord::fields_of( $signed['record'] );
		$mode   = CertificateSigner::mode( (int) ( $fields['form_id'] ?? 0 ) );
		if ( array() === $fields || CertificateSigner::MODE_LINK === $mode ) {
			return null;
		}

		$compact = SigningKeys::b64( CertificateRecord::compact_payload( $signed['key_id'], $fields, $signed['record_hash'] ) ) . '.' . $signed['signature_compact'];
		$full    = CertificateSigner::MODE_FULL === $mode
			? SigningKeys::b64( CertificateRecord::full_payload( $signed['key_id'], $fields, $signed['record_hash'] ) ) . '.' . $signed['signature_full']
			: '';

		return array(
			'full'    => $full,
			'compact' => $compact,
		);
	}

	/**
	 * The link one QR placeholder should encode: full when it is readable at
	 * the size the placeholder prints, else compact, else the plain link.
	 *
	 * @param string                                    $url       The verification link.
	 * @param array{full: string, compact: string}|null $fragments From fragments().
	 * @param int                                       $size      Printed width, in page pixels.
	 * @param int                                       $margin    Quiet zone, in modules.
	 * @param string                                    $ecc       Error level the code is drawn with.
	 * @return string
	 */
	public static function for_placement( string $url, ?array $fragments, int $size, int $margin, string $ecc ): string {
		if ( null === $fragments || '' === $url || false === strpos( $url, '#' ) ) {
			return $url;
		}
		foreach ( array( $fragments['full'], $fragments['compact'] ) as $fragment ) {
			if ( '' === $fragment ) {
				continue;
			}
			$signed = $url . '&' . self::FRAGMENT_KEY . '=' . $fragment;
			if ( SignedQrSize::px_for( SignedQrSize::modules( $signed, $ecc ), $margin ) <= $size ) {
				return $signed;
			}
		}
		return $url;
	}

	/**
	 * Whether a link carries a signed payload.
	 *
	 * @param string $url Link.
	 */
	public static function is_signed( string $url ): bool {
		return false !== strpos( $url, '&' . self::FRAGMENT_KEY . '=' );
	}
}
