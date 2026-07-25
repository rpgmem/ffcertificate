<?php
/**
 * Email source identifiers for mail-queue attribution.
 *
 * The canonical list of the plugin's outbound-email "functions" and their
 * human labels, used to tag each email with a distinct source so a mail-queue
 * plugin (the sibling `rpgmem/total-mail-queue`) can attribute it per feature
 * instead of lumping every message under one `plugin:ffcertificate` row.
 *
 * Because every plugin email funnels through the single `EmailService::send()`
 * chokepoint, the queue's backtrace detection can only ever see one
 * `plugin:ffcertificate` frame. `EmailService::send()` therefore stamps an
 * `X-TMQ-Source-Key` / `X-TMQ-Source-Label` header (which the queue reads and
 * strips) built from these constants when a caller declares its function.
 *
 * Keys follow the queue's contract: `plugin:ffcertificate_<function>`.
 *
 * @package FreeFormCertificate\Core
 * @since 6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical email-source keys + their display labels.
 */
final class EmailSource {

	/**
	 * Certificate issuance emails (user delivery + issuance admin notice).
	 */
	public const CERTIFICATE = 'plugin:ffcertificate_certificate';

	/**
	 * Self-scheduling appointment emails (confirmation, cancellation, reminder).
	 */
	public const SCHEDULING = 'plugin:ffcertificate_scheduling';

	/**
	 * Audience booking / group notifications.
	 */
	public const AUDIENCE = 'plugin:ffcertificate_audience';

	/**
	 * Re-registration campaign emails.
	 */
	public const REREGISTRATION = 'plugin:ffcertificate_reregistration';

	/**
	 * Recruitment / call-up emails.
	 */
	public const RECRUITMENT = 'plugin:ffcertificate_recruitment';

	/**
	 * Account / capability notifications (access granted, etc.).
	 */
	public const ACCOUNT = 'plugin:ffcertificate_account';

	/**
	 * Administrative emails (test email, admin-facing notices).
	 */
	public const ADMIN = 'plugin:ffcertificate_admin';

	/**
	 * The human label a mail-queue plugin shows for a given source key.
	 *
	 * Falls back to the bare product name for an unknown key so a future
	 * caller that forgets to register its label still reads sensibly.
	 *
	 * @param string $key One of the class constants.
	 * @return string Translatable display label.
	 */
	public static function label( string $key ): string {
		switch ( $key ) {
			case self::CERTIFICATE:
				return __( 'FFCertificate — Certificate', 'ffcertificate' );
			case self::SCHEDULING:
				return __( 'FFCertificate — Scheduling', 'ffcertificate' );
			case self::AUDIENCE:
				return __( 'FFCertificate — Audience', 'ffcertificate' );
			case self::REREGISTRATION:
				return __( 'FFCertificate — Re-registration', 'ffcertificate' );
			case self::RECRUITMENT:
				return __( 'FFCertificate — Recruitment', 'ffcertificate' );
			case self::ACCOUNT:
				return __( 'FFCertificate — Account', 'ffcertificate' );
			case self::ADMIN:
				return __( 'FFCertificate — Admin', 'ffcertificate' );
		}
		return __( 'FFCertificate', 'ffcertificate' );
	}
}
