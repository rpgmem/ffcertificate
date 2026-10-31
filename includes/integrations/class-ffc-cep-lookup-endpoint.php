<?php
/**
 * CEP Lookup Endpoint
 *
 * The two AJAX actions behind the address lookup (#2234): the form's lookup
 * and the Settings → General test.
 *
 * @package FreeFormCertificate\Integrations
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Integrations;

use FreeFormCertificate\Core\CepRule;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Core\WindowThrottle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the lookup's AJAX actions and hands the browser its settings.
 */
class CepLookupEndpoint {

	/**
	 * The form's lookup action.
	 */
	public const ACTION = 'ffc_cep_lookup';

	/**
	 * The Settings → General test action.
	 */
	public const TEST_ACTION = 'ffc_cep_lookup_test';

	/**
	 * Lookups allowed per caller per window. The form asks once per CEP typed,
	 * so a person correcting a few CEPs stays far below it; the cap is what
	 * keeps the site from being used as a CEP proxy.
	 */
	private const CAP = 30;

	/**
	 * Window of the cap, in seconds.
	 */
	private const WINDOW = 600;

	/**
	 * Throttle transient prefix.
	 */
	private const THROTTLE_PREFIX = 'ffc_cep_rl_';

	/**
	 * Register the actions and the script configuration.
	 *
	 * Logged-in only (`wp_ajax_`, no `nopriv`): the reregistration form and the
	 * admin user screen, the only forms with an address, are behind a login.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle_lookup' ) );
		add_action( 'wp_ajax_' . self::TEST_ACTION, array( __CLASS__, 'handle_test' ) );
		add_filter( 'ffcertificate_cep_lookup_script_config', array( __CLASS__, 'script_config' ) );
	}

	/**
	 * What the browser needs to run the lookup, or null when it must not.
	 *
	 * @return array{url: string, action: string, nonce: string, strings: array<string, string>}|null
	 */
	public static function script_config(): ?array {
		if ( ! CepLookup::enabled() || ! is_user_logged_in() ) {
			return null;
		}

		return array(
			'url'     => admin_url( 'admin-ajax.php' ),
			'action'  => self::ACTION,
			'nonce'   => wp_create_nonce( self::ACTION ),
			'strings' => array(
				'searching' => __( 'Looking up the address…', 'ffcertificate' ),
				'filled'    => __( 'Address filled from the CEP. Check the number.', 'ffcertificate' ),
				'cityOnly'  => __( 'City found. Fill in the street and the neighbourhood.', 'ffcertificate' ),
				'notFound'  => __( 'CEP not found. Correct the CEP or fill in the address manually.', 'ffcertificate' ),
			),
		);
	}

	/**
	 * Answer one lookup from the form.
	 *
	 * @return void
	 */
	public static function handle_lookup(): void {
		check_ajax_referer( self::ACTION, 'nonce' );

		if ( ! CepLookup::enabled() ) {
			wp_send_json_error( array( 'status' => 'disabled' ), 403 );
		}

		$cep = RequestInput::get_post_string( 'cep' );
		// The offline rule runs before the throttle, so a malformed request
		// spends nothing.
		if ( CepRule::OK !== CepRule::check( $cep ) ) {
			wp_send_json_success( CepLookup::lookup( $cep ) );
		}

		if ( ! WindowThrottle::take( self::THROTTLE_PREFIX, self::CAP, self::WINDOW, true ) ) {
			wp_send_json_error( array( 'retryAfter' => WindowThrottle::seconds_left( self::WINDOW ) ), 429 );
		}

		wp_send_json_success( CepLookup::lookup( $cep ) );
	}

	/**
	 * Ask both providers for one CEP, bypassing the cache, for the Settings test.
	 *
	 * @return void
	 */
	public static function handle_test(): void {
		check_ajax_referer( self::TEST_ACTION, 'nonce' );

		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'ffcertificate' ) ), 403 );
		}

		$cep = RequestInput::get_post_string( 'cep' );
		if ( CepRule::OK !== CepRule::check( $cep ) ) {
			wp_send_json_error( array( 'message' => __( 'Type a valid CEP to test.', 'ffcertificate' ) ), 400 );
		}

		$digits  = str_replace( '-', '', trim( $cep ) );
		$results = array();
		foreach ( CepLookup::PROVIDERS as $provider ) {
			$results[] = CepLookup::query( $provider, $digits, CepLookup::TIMEOUTS['primary'] );
		}

		wp_send_json_success( array( 'results' => $results ) );
	}
}
