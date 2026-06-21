<?php
/**
 * RateLimitStrategy
 *
 * Contract for a single rate-limit dimension (IP / email / CPF / …) extracted
 * from the RateLimitChecker god-class (#563 Sprint 4, A4). Each strategy is an
 * instance with its dependencies injected (a {@see RateLimitSupport}), so it
 * can be unit-tested in isolation — the pattern this sprint sets for the wider
 * instance-deps + facade direction (B2/B3).
 *
 * @package FreeFormCertificate\Security
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One rate-limit dimension keyed by a string identifier.
 */
interface RateLimitStrategy {

	/**
	 * Evaluate the limit for an identifier (IP / email / CPF digits).
	 *
	 * @param string   $identifier The dimension's identifier value.
	 * @param int|null $form_id    Optional form scope.
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public function check( string $identifier, ?int $form_id = null ): array;
}
