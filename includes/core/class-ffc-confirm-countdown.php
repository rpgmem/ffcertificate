<?php
/**
 * ConfirmCountdown
 *
 * How long a confirmation that cannot be undone keeps its button disabled
 * (#2078). One value for the whole plugin: the override warning, the
 * booking cancellation and the recruitment promotion all read it, so a
 * reader waits the same time before every irreversible step.
 *
 * @package FreeFormCertificate\Core
 * @since   6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin-wide confirmation countdown.
 */
final class ConfirmCountdown {

	/**
	 * Seconds a confirm button stays disabled.
	 */
	public const SECONDS = 15;
}
