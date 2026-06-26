<?php
/**
 * RateLimitSupport
 *
 * Shared dependencies the per-dimension rate-limit strategies need (#563
 * Sprint 4, A4): the resolved settings array and the message formatter.
 * Injected into each strategy's constructor so the strategies stay pure and
 * unit-testable — pass a fixture settings array in tests, or let it lazily
 * resolve the live settings in production.
 *
 * @package FreeFormCertificate\Security
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared support for rate-limit strategies.
 */
class RateLimitSupport {

	/**
	 * Resolved settings, or null until first accessed.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $settings;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null $settings Pre-resolved settings (tests),
	 *                                            or null to resolve lazily from
	 *                                            the live RateLimitChecker config.
	 */
	public function __construct( ?array $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * The resolved rate-limit settings array.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		if ( null === $this->settings ) {
			$this->settings = RateLimitChecker::get_settings();
		}
		return $this->settings;
	}

	/**
	 * Interpolate a rate-limit message template.
	 *
	 * @param string               $template Message template with {time}/{count}/{max}/{remaining} tokens.
	 * @param array<string, mixed> $data     Token values.
	 * @return string
	 */
	public function format_message( string $template, array $data ): string {
		if ( '' === trim( $template ) ) {
			$template = __( 'Submission limit reached. Please wait {time} and try again.', 'ffcertificate' );
		}
		return str_replace(
			array( '{time}', '{count}', '{max}', '{remaining}' ),
			array(
				(string) ( $data['time'] ?? '' ),
				(string) ( $data['count'] ?? 0 ),
				(string) ( $data['max'] ?? 0 ),
				(string) ( ( $data['max'] ?? 0 ) - ( $data['count'] ?? 0 ) ),
			),
			$template
		);
	}
}
