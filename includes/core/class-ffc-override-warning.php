<?php
/**
 * OverrideWarning
 *
 * The strings of the warning a bypass holder confirms before booking outside
 * a calendar's rules (#1859). The dialog itself is `FFC.overrideConfirm()` in
 * `ffc-core.js`; audience calendars and personal calendars both open it, so
 * its words are localized from one place.
 *
 * @package FreeFormCertificate\Core
 * @since   6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Localized strings of the override warning.
 */
final class OverrideWarning {

	/**
	 * The strings `FFC.overrideConfirm()` reads.
	 *
	 * @return array<string, string|int>
	 */
	public static function strings(): array {
		return array(
			'seconds'        => ConfirmCountdown::SECONDS,
			'title'          => __( 'Book outside the rules?', 'ffcertificate' ),
			'intro'          => __( 'This booking breaks the following rules:', 'ffcertificate' ),
			'logged'         => __( 'If you confirm, the booking is saved and recorded in the activity log with these reasons.', 'ffcertificate' ),
			'agree'          => __( 'I agree', 'ffcertificate' ),
			/* translators: %d: seconds left before the button can be pressed */
			'agreeIn'        => __( 'I agree (%d)', 'ffcertificate' ),
			'cancel'         => __( 'Cancel', 'ffcertificate' ),
			/* translators: %d: seconds before the confirmation can be given */
			'countdownStart' => __( 'Read the reasons. You can confirm in %d seconds.', 'ffcertificate' ),
			'countdownEnd'   => __( 'You can confirm now.', 'ffcertificate' ),
		);
	}
}
