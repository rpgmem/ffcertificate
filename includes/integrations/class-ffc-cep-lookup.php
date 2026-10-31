<?php
/**
 * CEP Lookup
 *
 * Asks a public postal-code service for the address of a CEP, from the
 * server, so the visitor's browser never talks to a third party (#2234).
 *
 * @package FreeFormCertificate\Integrations
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Integrations;

use FreeFormCertificate\Core\CepRule;
use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ViaCEP and BrasilAPI behind one shape.
 *
 * The primary provider answers; the other is asked only when the primary is
 * unreachable (a timeout, a transport error or a 5xx). A "not found" from the
 * primary is final: BrasilAPI was measured returning an address for
 * `99999999`, which is not a CEP, so a fallback after "not found" could only
 * invent one. Each answer -- found or not found -- is cached for 30 days; an
 * outage is not cached.
 *
 * @phpstan-type CepAddress array{street: string, neighborhood: string, city: string, uf: string, complement: string}
 * @phpstan-type CepAnswer array{status: string, address: CepAddress, provider: string}
 */
class CepLookup {

	/**
	 * The provider returned an address.
	 */
	public const FOUND = 'found';

	/**
	 * The provider says the CEP does not exist.
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * No provider answered.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Providers in the order the setting can choose them.
	 */
	public const PROVIDERS = array( 'viacep', 'brasilapi' );

	/**
	 * Seconds each provider is given; the primary's share is the larger, the
	 * whole lookup stays within five seconds.
	 */
	public const TIMEOUTS = array(
		'primary'  => 3,
		'fallback' => 2,
	);

	/**
	 * Cache prefix, `ffc_…` so the uninstall sweep of `ffc` transients clears it.
	 */
	private const CACHE_PREFIX = 'ffc_cep_';

	/**
	 * How long an answer is kept.
	 */
	private const CACHE_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Settings key of the toggle.
	 */
	public const SETTING_ENABLED = 'cep_lookup_enabled';

	/**
	 * Settings key of the primary provider.
	 */
	public const SETTING_PRIMARY = 'cep_lookup_primary';

	/**
	 * Whether the lookup is turned on (Settings → General).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return SettingsReader::get_bool( self::SETTING_ENABLED, false );
	}

	/**
	 * The primary provider; anything unknown reads as ViaCEP.
	 *
	 * @return string
	 */
	public static function primary(): string {
		$primary = SettingsReader::get_string( self::SETTING_PRIMARY, 'viacep' );
		return in_array( $primary, self::PROVIDERS, true ) ? $primary : 'viacep';
	}

	/**
	 * The address of a CEP, through the cache, the primary and, when the
	 * primary is unreachable, the fallback.
	 *
	 * A CEP the offline rule refuses (#2233) is answered as not found without
	 * any outbound request.
	 *
	 * @param string $cep CEP, masked or bare.
	 * @return array{status: string, address: array{street: string, neighborhood: string, city: string, uf: string, complement: string}, provider: string}
	 */
	public static function lookup( string $cep ): array {
		if ( CepRule::OK !== CepRule::check( $cep ) ) {
			return self::answer( self::NOT_FOUND, '' );
		}
		$digits = str_replace( '-', '', trim( $cep ) );

		$cached = get_transient( self::CACHE_PREFIX . $digits );
		if ( is_array( $cached ) && isset( $cached['status'], $cached['address'], $cached['provider'] ) ) {
			/**
			 * An answer this class cached.
			 *
			 * @var array{status: string, address: array{street: string, neighborhood: string, city: string, uf: string, complement: string}, provider: string} $cached
			 */
			return $cached;
		}

		$primary  = self::primary();
		$fallback = 'viacep' === $primary ? 'brasilapi' : 'viacep';

		$answer = self::query( $primary, $digits, self::TIMEOUTS['primary'] );
		if ( self::UNAVAILABLE === $answer['status'] ) {
			$answer = self::query( $fallback, $digits, self::TIMEOUTS['fallback'] );
		}

		if ( self::UNAVAILABLE !== $answer['status'] ) {
			set_transient( self::CACHE_PREFIX . $digits, $answer, self::CACHE_TTL );
		}

		return $answer;
	}

	/**
	 * One provider's answer, without the cache.
	 *
	 * @param string $provider One of {@see self::PROVIDERS}.
	 * @param string $digits   Eight digits.
	 * @param int    $timeout  Seconds.
	 * @return array{status: string, address: array{street: string, neighborhood: string, city: string, uf: string, complement: string}, provider: string}
	 */
	public static function query( string $provider, string $digits, int $timeout ): array {
		$url = 'brasilapi' === $provider
			? 'https://brasilapi.com.br/api/cep/v2/' . $digits
			: 'https://viacep.com.br/ws/' . $digits . '/json/';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::answer( self::UNAVAILABLE, $provider );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 500 || 0 === $code ) {
			return self::answer( self::UNAVAILABLE, $provider );
		}
		if ( 400 === $code || 404 === $code ) {
			return self::answer( self::NOT_FOUND, $provider );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			return self::answer( self::UNAVAILABLE, $provider );
		}

		// ViaCEP answers a CEP it does not know with 200 and `"erro": "true"` --
		// a string, not a boolean -- so both spellings are read.
		if ( isset( $body['erro'] ) && ( true === $body['erro'] || 'true' === $body['erro'] ) ) {
			return self::answer( self::NOT_FOUND, $provider );
		}

		$address = 'brasilapi' === $provider
			? array(
				'street'       => self::text( $body['street'] ?? '' ),
				'neighborhood' => self::text( $body['neighborhood'] ?? '' ),
				'city'         => self::text( $body['city'] ?? '' ),
				'uf'           => strtoupper( self::text( $body['state'] ?? '' ) ),
				'complement'   => '',
			)
			: array(
				'street'       => self::text( $body['logradouro'] ?? '' ),
				'neighborhood' => self::text( $body['bairro'] ?? '' ),
				'city'         => self::text( $body['localidade'] ?? '' ),
				'uf'           => strtoupper( self::text( $body['uf'] ?? '' ) ),
				'complement'   => self::text( $body['complemento'] ?? '' ),
			);

		// An answer with neither a city nor a UF names no place.
		if ( '' === $address['city'] && '' === $address['uf'] ) {
			return self::answer( self::UNAVAILABLE, $provider );
		}

		return array(
			'status'   => self::FOUND,
			'address'  => $address,
			'provider' => $provider,
		);
	}

	/**
	 * An answer with an empty address.
	 *
	 * @param string $status   One of the status constants.
	 * @param string $provider Provider that answered, or ''.
	 * @return array{status: string, address: array{street: string, neighborhood: string, city: string, uf: string, complement: string}, provider: string}
	 */
	private static function answer( string $status, string $provider ): array {
		return array(
			'status'   => $status,
			'address'  => array(
				'street'       => '',
				'neighborhood' => '',
				'city'         => '',
				'uf'           => '',
				'complement'   => '',
			),
			'provider' => $provider,
		);
	}

	/**
	 * A provider's value as plain text: a third party's response is never
	 * trusted as markup.
	 *
	 * @param mixed $value Decoded JSON value.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}
}
