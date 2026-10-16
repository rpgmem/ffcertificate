<?php
/**
 * AudienceAccountEmail
 *
 * The WordPress account email of a person listed on an audience screen —
 * an audience's members and a calendar's user-access rows — drawn masked,
 * with an audited Reveal button for whoever may reveal it (#1824).
 *
 * @package FreeFormCertificate\Audience
 * @since   6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Masked account email plus reveal control for the audience admin screens.
 *
 * WHICH GATE. The audience domain owns no PII capability and no `_admin`
 * role: `ffc_view_audiences` / `ffc_manage_audiences` decide who sees and
 * edits a group, not who reads a person's address. Rather than invent a
 * capability for one field, the reveal reuses the appointments domain's
 * (`ffc_view_appointments_pii` / `ffc_appointments_admin`): audiences live
 * under the same Scheduling menu, and that cap is already the one that
 * reveals a booker's email there. The cost is stated, not hidden: an
 * audiences-only role (viewer, operator, manager) sees the address masked
 * with no button, while WordPress administrators, `ffc_administrator`, the
 * appointments manager and admin roles, and the account's owner may reveal.
 */
final class AudienceAccountEmail {

	/**
	 * Reveal capability handed to the shared PII policy.
	 */
	public const PII_CAP = 'ffc_view_appointments_pii';

	/**
	 * Domain admin role handed to the shared PII policy.
	 */
	public const ADMIN_ROLE = 'ffc_appointments_admin';

	/**
	 * The `ffc_reveal_pii` record type the button names.
	 */
	public const REVEAL_TYPE = 'audience_account';

	/**
	 * The `ffc_reveal_pii_nonce`, created once per request on first use.
	 *
	 * @var string|null
	 */
	private static $nonce = null;

	/**
	 * Masked email plus, for a user who may reveal, the audited button.
	 *
	 * The clear address never reaches the HTML, whatever the tier: the
	 * button fetches it from `ffc_reveal_pii`, which audits the read. The
	 * row returned by the calendar's "add user" AJAX handler is rendered
	 * here too, so a row added without a reload is masked the same way.
	 *
	 * @param int    $user_id The listed account.
	 * @param string $email   Its stored email.
	 * @return string Escaped HTML, or '' when there is no email.
	 */
	public static function render( int $user_id, string $email ): string {
		if ( '' === $email ) {
			return '';
		}

		$masked = \FreeFormCertificate\Core\DocumentFormatter::mask_email( $email );
		if ( $masked === $email ) {
			// mask_email() hands back an address it cannot parse unchanged.
			$masked = '***';
		}

		$button = '';
		if ( \FreeFormCertificate\Core\PiiAccessPolicy::can_reveal( self::PII_CAP, self::ADMIN_ROLE, $user_id ) ) {
			if ( null === self::$nonce ) {
				self::$nonce = wp_create_nonce( 'ffc_reveal_pii_nonce' );
			}
			$button = sprintf(
				' <button type="button" class="button button-small ffc-reveal-pii ffc-icon-eye" data-type="%1$s" data-field="email" data-submission-id="%2$s" data-nonce="%3$s">%4$s</button>',
				esc_attr( self::REVEAL_TYPE ),
				esc_attr( (string) $user_id ),
				esc_attr( self::$nonce ),
				esc_html__( 'Reveal', 'ffcertificate' )
			);
		}

		return sprintf(
			'<span class="ffc-pii-row"><span class="ffc-pii-value" data-field="email">%1$s</span>%2$s</span>',
			esc_html( $masked ),
			$button
		);
	}

	/**
	 * Whether an account appears on an audience screen: a member of some
	 * audience, or holder of some calendar's user-access row. The reveal
	 * endpoint serves only those, so the type cannot be used to read the
	 * email of an arbitrary account by id.
	 *
	 * @param int $user_id Account ID.
	 * @return bool
	 */
	public static function is_listed( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		return AudienceReader::is_member_of_any( $user_id )
			|| AudienceScheduleRepository::has_any_permission( $user_id );
	}
}
