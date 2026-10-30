<?php
/**
 * VerificationResult
 *
 * The result shape every verification lookup answers with when nothing was
 * found, shared by the per-kind lookups (#2241).
 *
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verification result shapes.
 */
final class VerificationResult {

	/**
	 * The result when nothing was found.
	 *
	 * @return array{found: false, submission: null, data: array<string, mixed>}
	 */
	public static function not_found(): array {
		return array(
			'found'      => false,
			'submission' => null,
			'data'       => array(),
		);
	}
}
