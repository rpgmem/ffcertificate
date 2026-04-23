<?php
/**
 * ViewPolicy
 *
 * Declarative intent attached to a UserProfileService read: how much
 * plaintext the caller wants to see for sensitive fields. Non-sensitive
 * fields ignore the policy and always return their stored value.
 *
 * The policy is advisory for the service — it does NOT elevate
 * privileges. Callers are responsible for validating capability
 * (e.g. `current_user_can('manage_options')`) before asking for FULL;
 * the service only audits and dispatches.
 *
 * @package FreeFormCertificate\UserDashboard
 * @since 5.5.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read visibility policy for sensitive user-profile fields.
 */
enum ViewPolicy: string {
	/**
	 * Return the decrypted plaintext. Triggers an audit entry when a
	 * sensitive field is included in the read.
	 */
	case FULL = 'full';

	/**
	 * Return a masked form (e.g. `123.***.***-01` for CPF). Safe to
	 * render to end users without elevating privileges.
	 */
	case MASKED = 'masked';

	/**
	 * Return only the stored hash — useful for lookups where the caller
	 * does not need to decrypt at all.
	 */
	case HASHED_ONLY = 'hashed_only';
}
