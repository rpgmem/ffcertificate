<?php
/**
 * Password-set link for an invited account.
 *
 * @package FreeFormCertificate\Core
 * @since 6.25.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits and consumes the link that lets an invited user define their password.
 *
 * **Por que isto existe.** `UserCreator::create_ffc_user()` cria a conta com
 * `wp_generate_password( 24 )` e nunca conta essa senha a ninguém. Quem é
 * convidado para um recadastramento, portanto, não tem caminho de entrada
 * nenhum: o botão do e-mail leva ao painel, que exige login. Isto é a metade
 * que faltava da criação de usuário, não uma conveniência (#1212).
 *
 * **O link NÃO cria sessão.** Ele abre a definição de senha; a sessão só nasce
 * depois que a senha existe e foi escolhida pelo próprio usuário, na mesma
 * requisição. Um link que logasse direto seria um token equivalente a
 * credencial viajando por e-mail, num sistema sob LGPD com CPF e RF
 * criptografados.
 *
 * **O token é o do WordPress, de propósito.** `get_password_reset_key()` grava
 * `time() . ':' . $wp_hasher->HashPassword( $key )` em `user_activation_key`
 * -- ou seja, HASHEADO e com carimbo de emissão --, `check_password_reset_key()`
 * compara contra a expiração, e `reset_password()` chama `wp_set_password()`,
 * que grava `user_activation_key = ''`. Uso único sai de graça. Um token
 * próprio guardaria o segredo EM CLARO onde o core guarda um hash: estritamente
 * pior, além de ser a armadilha da fachada que o `CLAUDE.md` descreve.
 *
 * **O filtro de expiração é global, e por isso é escopado.**
 * `password_reset_expiration` não recebe o usuário
 * (`apply_filters( 'password_reset_expiration', DAY_IN_SECONDS )`, verificado no
 * core 6.4), então registrá-lo de forma permanente mudaria a expiração de
 * QUALQUER reset do site -- inclusive o de um administrador usando o
 * "perdi minha senha" do WordPress. Ele é registrado imediatamente antes da
 * nossa chamada e removido logo depois: o raio de ação é uma chamada de função.
 *
 * **Quando o token morre.** Na redefinição bem-sucedida, não na exibição do
 * formulário. Uma validação que falha -- as duas senhas não conferem -- deixa
 * o token vivo de propósito, senão um erro de digitação tornaria o convite
 * irrecuperável. É o mesmo princípio que o `CLAUDE.md` já fixa para o captcha:
 * *o desafio é consumido pela ação que ele autoriza, não pela leitura que a
 * precede*.
 */
class PasswordInvite {

	/** Action name for the `admin_post` submit. */
	public const ACTION = 'ffc_set_invite_password';

	/** Query arg carrying the reset key. */
	public const ARG_KEY = 'ffc_key';

	/** Query arg carrying the user login the key belongs to. */
	public const ARG_LOGIN = 'ffc_login';

	/** Nonce action for the form. */
	private const NONCE = 'ffc_set_invite_password';

	/** Menor janela aceita. Abaixo de uma hora o convite morre antes de ser lido. */
	public const MIN_HOURS = 1;

	/** Maior janela aceita: 30 dias. Acima disso o link deixa de ser um convite. */
	public const MAX_HOURS = 720;

	/** Default window, in hours. Declarado em `Settings::get_default_settings()`. */
	public const DEFAULT_HOURS = 48;

	/** Menor senha aceita. O WordPress não impõe mínimo; este impõe. */
	public const MIN_PASSWORD_LENGTH = 8;

	/**
	 * Register the submit handler.
	 *
	 * `nopriv` é o caminho normal -- quem chega aqui não tem sessão. O par
	 * com sessão existe porque um usuário já logado pode abrir o link do
	 * próprio e-mail, e um 400 mudo ali seria pior que redirecionar.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_submit' ) );
	}

	/**
	 * Expiration window for the link, in hours.
	 *
	 * Limitado na LEITURA além da escrita, como o `CLAUDE.md` exige: um valor
	 * gravado antes de um limite se mover ainda precisa cair onde o código
	 * consegue usar.
	 *
	 * @return int
	 */
	public static function expiration_hours(): int {
		return self::clamp_hours( SettingsReader::invite_password_link_hours() );
	}

	/**
	 * Clamp an hours value into the supported range.
	 *
	 * @param int $hours Raw value.
	 * @return int
	 */
	public static function clamp_hours( int $hours ): int {
		return max( self::MIN_HOURS, min( self::MAX_HOURS, $hours ) );
	}

	/**
	 * Issue a password-set link for a user.
	 *
	 * Emitir uma chave INVALIDA qualquer chave anterior daquele usuário --
	 * `user_activation_key` é uma coluna só. Um "perdi minha senha" pedido
	 * minutos antes do convite morre aqui; é o comportamento do próprio
	 * WordPress e não vale contornar.
	 *
	 * @param int $user_id User to invite.
	 * @return string URL, or '' when the key could not be issued.
	 */
	public static function issue_for( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return '';
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return '';
		}

		self::log( 'password_invite_issued', $user_id );

		return add_query_arg(
			array(
				self::ARG_KEY   => rawurlencode( (string) $key ),
				self::ARG_LOGIN => rawurlencode( $user->user_login ),
			),
			self::dashboard_url()
		);
	}

	/**
	 * Validate a key/login pair.
	 *
	 * @param string $key   Reset key from the request.
	 * @param string $login User login from the request.
	 * @return \WP_User|\WP_Error
	 */
	public static function validate( string $key, string $login ) {
		if ( '' === $key || '' === $login ) {
			return new \WP_Error( 'invalid_key', __( 'This link is not valid.', 'ffcertificate' ) );
		}

		$seconds = self::expiration_hours() * HOUR_IN_SECONDS;
		$filter  = static function () use ( $seconds ): int {
			return $seconds;
		};

		// Escopado: o filtro do core não recebe o usuário, então só pode
		// valer durante a nossa própria chamada. Ver o docblock da classe.
		add_filter( 'password_reset_expiration', $filter );
		$user = check_password_reset_key( $key, $login );
		remove_filter( 'password_reset_expiration', $filter );

		return $user;
	}

	/**
	 * Whether the current request carries a password-set link.
	 *
	 * @return bool
	 */
	public static function request_has_link(): bool {
		return '' !== RequestInput::get_get_string( self::ARG_KEY )
			&& '' !== RequestInput::get_get_string( self::ARG_LOGIN );
	}

	/**
	 * The key and login the current request carries.
	 *
	 * @return array{key: string, login: string}
	 */
	public static function request_pair(): array {
		return array(
			'key'   => RequestInput::get_get_string( self::ARG_KEY ),
			'login' => RequestInput::get_get_string( self::ARG_LOGIN ),
		);
	}

	/**
	 * Nonce field markup for the form.
	 *
	 * @return string
	 */
	public static function nonce_field(): string {
		return wp_nonce_field( self::NONCE, '_ffc_nonce', true, false );
	}

	/**
	 * Handle the submitted password.
	 *
	 * @return void
	 */
	public static function handle_submit(): void {
		$redirect = self::dashboard_url();

		if ( ! wp_verify_nonce( RequestInput::get_post_string( '_ffc_nonce' ), self::NONCE ) ) {
			self::bail( $redirect, 'nonce' );
		}

		$key   = RequestInput::get_post_string( self::ARG_KEY );
		$login = RequestInput::get_post_string( self::ARG_LOGIN );

		// O usuário vem do TOKEN, nunca de um id da requisição.
		$user = self::validate( $key, $login );
		if ( is_wp_error( $user ) ) {
			self::bail( $redirect, 'expired_key' === $user->get_error_code() ? 'expired' : 'invalid' );
		}

		// `get_post_string()` sanitiza; a senha não pode ser sanitizada sem
		// ser alterada, então é lida crua e validada por comprimento.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- uma senha não pode ser sanitizada sem ser alterada; o nonce e a chave de reset já foram verificados acima, e esta leitura é exatamente o valor que eles autorizam.
		$pass1 = isset( $_POST['ffc_pass1'] ) ? (string) wp_unslash( $_POST['ffc_pass1'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- mesma razão da linha acima: sanitizar a confirmação mudaria o valor comparado.
		$pass2 = isset( $_POST['ffc_pass2'] ) ? (string) wp_unslash( $_POST['ffc_pass2'] ) : '';

		if ( '' === $pass1 || $pass1 !== $pass2 ) {
			// O token segue vivo: um erro de digitação não pode queimar o
			// convite. Ver o docblock da classe.
			self::bail( self::link_url( $key, $login ), 'mismatch' );
		}

		if ( strlen( $pass1 ) < self::MIN_PASSWORD_LENGTH ) {
			self::bail( self::link_url( $key, $login ), 'short' );
		}

		reset_password( $user, $pass1 );
		self::log( 'password_invite_consumed', (int) $user->ID );

		// A sessão nasce AQUI, depois que a senha existe e foi escolhida pelo
		// próprio usuário nesta mesma requisição (#1212).
		wp_set_current_user( (int) $user->ID );
		wp_set_auth_cookie( (int) $user->ID, false );

		wp_safe_redirect( add_query_arg( 'ffc_password', 'set', $redirect ) );
		exit;
	}

	/**
	 * Rebuild the link URL for a retry.
	 *
	 * @param string $key   Reset key.
	 * @param string $login User login.
	 * @return string
	 */
	private static function link_url( string $key, string $login ): string {
		return add_query_arg(
			array(
				self::ARG_KEY   => rawurlencode( $key ),
				self::ARG_LOGIN => rawurlencode( $login ),
			),
			self::dashboard_url()
		);
	}

	/**
	 * Redirect with an error code and stop.
	 *
	 * @param string $url    Destination.
	 * @param string $reason Error slug rendered by the form.
	 * @return never
	 */
	private static function bail( string $url, string $reason ): void {
		wp_safe_redirect( add_query_arg( 'ffc_password_error', $reason, $url ) );
		exit;
	}

	/**
	 * The dashboard page URL.
	 *
	 * Mesma resolução que `ReregistrationEmailHandler::send_to_user()` usa: a
	 * opção é gravada pelo activator como id de post, e o que não for número
	 * cai no fallback em vez de ser convertido (#1060).
	 *
	 * @return string
	 */
	public static function dashboard_url(): string {
		$page_id = get_option( 'ffc_dashboard_page_id' );
		if ( is_numeric( $page_id ) && (int) $page_id > 0 ) {
			$url = get_permalink( (int) $page_id );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return home_url( '/dashboard' );
	}

	/**
	 * Record an action on a credential.
	 *
	 * @param string $action  Action slug.
	 * @param int    $user_id Subject.
	 * @return void
	 */
	private static function log( string $action, int $user_id ): void {
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			ActivityLog::log( $action, ActivityLog::LEVEL_INFO, array(), $user_id );
		}
	}
}
