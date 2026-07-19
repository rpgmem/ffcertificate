<?php
/**
 * Audience Email Token Migration
 *
 * One-shot, version-flagged migration that rewrites stored audience email
 * templates from the old single-brace `{token}` convention to the unified
 * double-brace `{{token}}` engine (#653). Only the known audience tokens are
 * converted, so literal braces in operator markup (CSS, etc.) are left alone.
 *
 * BREAKING for external integrations that assemble these templates with the
 * old `{token}` syntax — see CHANGELOG.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.14.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts stored audience email templates `{token}` → `{{token}}` once.
 */
final class AudienceEmailTokenMigration {

	/**
	 * Option flag marking the migration as done. Guards against re-running —
	 * a second pass would turn `{{token}}` into `{{{{token}}}}`.
	 *
	 * @var string
	 */
	private const DONE_OPTION = 'ffc_audience_email_tokens_migrated_v1';

	/**
	 * The audience email tokens (must match
	 * {@see AudienceNotificationHandler} render map).
	 *
	 * @var array<int, string>
	 */
	private const TOKENS = array(
		'user_name',
		'user_email',
		'environment_name',
		'environment_label',
		'schedule_name',
		'booking_date',
		'start_time',
		'end_time',
		'description',
		'audiences',
		'creator_name',
		'cancelled_by_name',
		'cancellation_reason',
		'site_name',
		'site_url',
	);

	/**
	 * Run the migration once (guarded by the version flag).
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}

		$map = array();
		foreach ( self::TOKENS as $token ) {
			$map[ '{' . $token . '}' ] = '{{' . $token . '}}';
		}

		foreach ( AudienceScheduleRepository::get_all() as $schedule ) {
			$id = isset( $schedule->id ) ? (int) $schedule->id : 0;
			if ( ! $id ) {
				continue;
			}

			$update  = array();
			$booking = isset( $schedule->email_template_booking ) ? (string) $schedule->email_template_booking : '';
			$cancel  = isset( $schedule->email_template_cancellation ) ? (string) $schedule->email_template_cancellation : '';

			if ( '' !== $booking ) {
				$update['email_template_booking'] = strtr( $booking, $map );
			}
			if ( '' !== $cancel ) {
				$update['email_template_cancellation'] = strtr( $cancel, $map );
			}

			if ( array() !== $update ) {
				AudienceScheduleRepository::update( $id, $update );
			}
		}

		update_option( self::DONE_OPTION, 1, false );
	}
}
