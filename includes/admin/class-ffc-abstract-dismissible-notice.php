<?php
/**
 * AbstractDismissibleNotice
 *
 * Shared skeleton for FFC's persistent, dismissible admin notices (#849).
 * A concrete notice supplies only its specifics — the option key, the AJAX/
 * nonce action, when it should show, its dismiss signature, and the inner
 * markup — while this base owns the identical plumbing that used to be copy-
 * pasted into each notice: hook registration, the `<div>` wrapper (with the
 * `ffc-js-dismiss-notice` hook class + data-attrs the shared
 * `ffc-dismissible-notice.js` reads), the capability gate, the dismissed-state
 * gate, the AJAX dismiss endpoint, and the dismiss-script enqueue.
 *
 * Dismissal stores a *signature* string ({@see dismiss_signature()}): a plain
 * flag like `'1'` for a one-shot nudge, or a state fingerprint (e.g.
 * `status:keyfingerprint`) for a notice that must re-appear when the underlying
 * condition changes.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.18.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for dismissible admin notices.
 */
abstract class AbstractDismissibleNotice {

	// ---- Subclass contract -------------------------------------------------

	/** Option key the dismissed signature is stored under. */
	abstract protected static function option_key(): string;

	/** Nonce action, also the `wp_ajax_{action}` hook suffix. */
	abstract protected static function action(): string;

	/** Domain gate: should this notice show at all (capability + dismissal aside)? */
	abstract protected static function should_show(): bool;

	/** The signature the dismissal is keyed to (`'1'` for a one-shot; a state hash otherwise). */
	abstract protected static function dismiss_signature(): string;

	/** The inner notice HTML message (already escaped). The base wraps it. */
	abstract protected static function notice_message(): string;

	/** WordPress notice type: 'info' | 'error' | 'warning' | 'success'. */
	protected static function notice_type(): string {
		return 'info';
	}

	/** Extra stable class(es) on the wrapper (styling / test hooks). */
	protected static function extra_class(): string {
		return '';
	}

	// ---- Shared plumbing ---------------------------------------------------

	/**
	 * Register the admin-notice render + AJAX dismiss hooks.
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( static::class, 'maybe_render' ) );
		add_action( 'wp_ajax_' . static::action(), array( static::class, 'ajax_dismiss' ) );
	}

	/**
	 * Render the wrapper + body when the gates pass, then enqueue the shared
	 * dismiss handler.
	 */
	public static function maybe_render(): void {
		if ( ! static::should_render() ) {
			return;
		}

		// Render via the canonical WP 6.4+ helper: it builds the standard
		// `notice notice-{type} is-dismissible` markup and escapes the classes +
		// attributes. `ffc-js-dismiss-notice` is the hook class the shared
		// dismiss JS binds to; the data-attributes carry the AJAX action + nonce.
		// paragraph_wrap is off because each notice supplies its own <p> markup.
		wp_admin_notice(
			static::notice_message(),
			array(
				'type'               => static::notice_type(),
				'dismissible'        => true,
				'paragraph_wrap'     => false,
				'additional_classes' => array_values(
					array_filter( array( 'ffc-js-dismiss-notice', static::extra_class() ) )
				),
				'attributes'         => array(
					'data-ffc-action' => static::action(),
					'data-ffc-nonce'  => wp_create_nonce( static::action() ),
				),
			)
		);

		static::enqueue_dismiss_script();
	}

	/**
	 * AJAX dismiss endpoint — records the current dismiss signature so only that
	 * exact state stays hidden.
	 */
	public static function ajax_dismiss(): void {
		check_ajax_referer( static::action() );
		if ( ! static::user_can_manage() ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		update_option( static::option_key(), static::dismiss_signature(), false );
		wp_send_json_success();
	}

	/**
	 * Capability + domain + dismissed gates. Rendered only when all pass.
	 */
	protected static function should_render(): bool {
		if ( ! static::user_can_manage() ) {
			return false;
		}
		if ( ! static::should_show() ) {
			return false;
		}
		$dismissed = get_option( static::option_key(), '' );
		$dismissed = is_scalar( $dismissed ) ? (string) $dismissed : '';
		return static::dismiss_signature() !== $dismissed;
	}

	/**
	 * WP admin or an FFC settings manager.
	 */
	protected static function user_can_manage(): bool {
		return current_user_can( 'manage_options' )
			|| (
				class_exists( '\FreeFormCertificate\Core\Capabilities' )
				&& \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings' )
			);
	}

	/**
	 * Enqueue the generic persistent-dismiss handler.
	 */
	protected static function enqueue_dismiss_script(): void {
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			'ffc-dismissible-notice',
			FFC_PLUGIN_URL . "assets/js/ffc-dismissible-notice{$s}.js",
			array(),
			FFC_VERSION,
			true
		);
	}
}
