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
 * WHICH GATE. The audience module's own PII capability,
 * `ffc_view_audiences_pii` (#1863), held by the Audience Calendars Manager and
 * Administrator roles and FFC Administrator. Until #1863 the module had none
 * and borrowed the appointments one; `ffc_view_audiences` /
 * `ffc_manage_audiences` decide who sees and edits a group, not who reads a
 * person's address. The account's owner sees their own address unmasked.
 */
final class AudienceAccountEmail {

	/**
	 * Reveal capability handed to the shared PII policy.
	 */
	public const PII_CAP = 'ffc_view_audiences_pii';

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

		$button = '';
		if ( \FreeFormCertificate\Core\PiiAccessPolicy::can_reveal( self::PII_CAP, $user_id ) ) {
			if ( null === self::$nonce ) {
				self::$nonce = wp_create_nonce( 'ffc_reveal_pii_nonce' );
			}
			$button = ' ' . \FreeFormCertificate\Core\PiiReveal::field_button(
				array(
					'type'          => self::REVEAL_TYPE,
					'field'         => 'email',
					'submission-id' => (string) $user_id,
					'nonce'         => self::$nonce,
				)
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
	 * audience, holder of some calendar's user-access row, or a custom user
	 * of some booking (#2243). The reveal endpoint serves only those, so the
	 * type cannot be used to read the email of an arbitrary account by id.
	 *
	 * @param int $user_id Account ID.
	 * @return bool
	 */
	public static function is_listed( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		return AudienceReader::is_member_of_any( $user_id )
			|| AudienceScheduleRepository::has_any_permission( $user_id )
			|| AudienceBookingReader::is_participant_of_any( $user_id );
	}

	/**
	 * The email an AJAX answer may carry about an account: in clear only to
	 * its owner, masked to everyone else (#2243). A reveal goes through the
	 * audited button, never through the answer itself.
	 *
	 * @param int    $user_id The account.
	 * @param string $email   Its stored email.
	 * @return string
	 */
	public static function for_response( int $user_id, string $email ): string {
		if ( '' === $email ) {
			return '';
		}
		if ( \FreeFormCertificate\Core\PiiAccessPolicy::TIER_UNMASKED === \FreeFormCertificate\Core\PiiAccessPolicy::resolve( self::PII_CAP, $user_id ) ) {
			return $email;
		}
		return \FreeFormCertificate\Core\DocumentFormatter::mask_email( $email );
	}
}
