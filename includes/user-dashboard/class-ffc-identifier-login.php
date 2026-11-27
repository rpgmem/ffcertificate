<?php
/**
 * Identifier Login
 *
 * Signing in, and resetting the password, with a CPF or an RF (#2960).
 *
 * @package FreeFormCertificate\UserDashboard
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

use FreeFormCertificate\Core\DataSanitizer;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets WordPress's login and lost-password forms take a CPF (11 digits) or an
 * RF (7 digits) where they ask for a username.
 *
 * The account is found by the identifier's hash, in the stores
 * {@see UserCreator::user_ids_by_hash()} asks; nothing new is stored. One
 * identifier can belong to several accounts (the identity audit lists them),
 * so the password decides: no account accepting it is WordPress's own error,
 * unchanged, so the answer says nothing about the identifier; one account is
 * that account; more than one is a message to ask an administrator, which
 * only someone who knows the password can see.
 */
final class IdentifierLogin {

	/**
	 * Error code of an identifier shared by more than one account.
	 */
	public const AMBIGUOUS = 'ffc_identifier_ambiguous';

	/**
	 * Hook the login.
	 *
	 * `authenticate` at 30: after WordPress's username (20) and e-mail (20)
	 * checks, so a real username, numeric or not, always wins, and before
	 * the spam check (99), which still sees the account this one resolves.
	 * `lostpassword_user_data` at the default priority: WordPress calls it
	 * only when its own lookup found nobody.
	 */
	public static function init(): void {
		add_filter( 'authenticate', array( self::class, 'authenticate' ), 30, 3 );
		add_filter( 'lostpassword_user_data', array( self::class, 'lost_password_user' ), 10, 2 );
		add_action( 'login_init', array( self::class, 'relabel' ) );
		add_filter( 'site_pwa_login_username_label', array( self::class, 'label' ) );
	}

	/**
	 * The hash column and the hash of a typed identifier, or null when the
	 * input is not a CPF or an RF: digits with at most the usual mask
	 * characters, 11 or 7 of them.
	 *
	 * @param string $input What was typed in the username field.
	 * @return array{0: string, 1: string}|null
	 */
	public static function identifier_hash( string $input ): ?array {
		$input = trim( $input );
		if ( '' === $input || ! preg_match( '/^[0-9.\-\/ ]+$/', $input ) ) {
			return null;
		}
		$digits = DataSanitizer::normalize_cpf_rf( $input );
		$length = strlen( $digits );
		if ( 11 !== $length && 7 !== $length ) {
			return null;
		}
		$key  = 11 === $length ? 'cpf' : 'rf';
		$hash = SensitiveFieldRegistry::hash_identifier( $key, $digits );
		return null === $hash ? null : array( $key . '_hash', $hash );
	}

	/**
	 * The accounts a typed identifier belongs to.
	 *
	 * @param string $input What was typed in the username field.
	 * @return list<WP_User>
	 */
	public static function accounts( string $input ): array {
		$found = self::identifier_hash( $input );
		if ( null === $found ) {
			return array();
		}
		$users = array();
		foreach ( UserCreator::user_ids_by_hash( $found[0], $found[1] ) as $id ) {
			$user = get_user_by( 'id', $id );
			if ( $user instanceof WP_User ) {
				$users[] = $user;
			}
		}
		return $users;
	}

	/**
	 * Sign in by CPF or RF where the username failed.
	 *
	 * @param WP_User|WP_Error|null $user     What the checks before this one decided.
	 * @param string                $username What was typed in the username field.
	 * @param string                $password The password.
	 * @return WP_User|WP_Error|null
	 */
	public static function authenticate( $user, $username, $password ) {
		if ( $user instanceof WP_User || '' === $password ) {
			return $user;
		}
		$accepted = array();
		foreach ( self::accounts( $username ) as $account ) {
			$result = wp_authenticate_username_password( null, $account->user_login, $password );
			if ( $result instanceof WP_User ) {
				$accepted[] = $result;
			}
		}
		if ( 1 === count( $accepted ) ) {
			return $accepted[0];
		}
		if ( count( $accepted ) > 1 ) {
			return self::ambiguous();
		}
		return $user;
	}

	/**
	 * The account "Lost your password?" resets, for a CPF or an RF.
	 *
	 * @param WP_User|false $user_data What WordPress found for the input.
	 * @param WP_Error      $errors    Errors the form shows.
	 * @return WP_User|false
	 */
	public static function lost_password_user( $user_data, $errors = null ) {
		if ( $user_data instanceof WP_User ) {
			return $user_data;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress's own lost-password form, which carries no nonce; read only to look the account up.
		$input    = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['user_login'] ) ) : '';
		$accounts = self::accounts( $input );
		if ( 1 === count( $accounts ) ) {
			return $accounts[0];
		}
		if ( count( $accounts ) > 1 && $errors instanceof WP_Error ) {
			$errors->add( self::AMBIGUOUS, self::ambiguous()->get_error_message() );
		}
		return $user_data;
	}

	/**
	 * The refusal for an identifier shared by more than one account.
	 */
	public static function ambiguous(): WP_Error {
		return new WP_Error( self::AMBIGUOUS, __( 'This CPF or RF belongs to more than one account. Ask an administrator.', 'ffcertificate' ) );
	}

	/**
	 * The username field's label, naming the identifiers it now takes.
	 */
	public static function label(): string {
		return __( 'Username, e-mail, CPF or RF', 'ffcertificate' );
	}

	/**
	 * Relabel the username field on WordPress's login and lost-password
	 * forms. Hooked from `login_init`, so the gettext filter runs only on
	 * the login screen.
	 */
	public static function relabel(): void {
		add_filter( 'gettext', array( self::class, 'login_label' ), 10, 3 );
	}

	/**
	 * The gettext filter `relabel()` adds.
	 *
	 * @param string $translation The translated text.
	 * @param string $text        The source text.
	 * @param string $domain      Its text domain.
	 */
	public static function login_label( $translation, $text = '', $domain = '' ): string {
		if ( 'default' === $domain && 'Username or Email Address' === $text ) {
			return self::label();
		}
		return (string) $translation;
	}
}
