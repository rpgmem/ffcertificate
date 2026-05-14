<?php
/**
 * ExpiredTicketsCleanup — daily sweep that wipes the unredeemed
 * ticket pool of forms whose collection window has already ended.
 *
 * Why: forms with `restrictions[ticket] = '1'` keep their generated
 * ticket codes in `_ffc_form_config[generated_codes_list]` indefinitely.
 * After the form's `end_datetime` passes, those codes are dead data —
 * unredeemable in the form workflow, but a leak vector if the DB is
 * ever exposed. This cron clears the list per form (toggle stays as
 * history; only the codes are wiped).
 *
 * Scope: each cron tick scans every published `ffc_form` and acts on
 * those that match all three conditions:
 *   1. `restrictions[ticket] === '1'` (ticket gate is on)
 *   2. `Geofence::has_form_expired($form_id) === true`
 *   3. `_ffc_form_config[generated_codes_list]` is non-empty
 *
 * Idempotency: matches once, wipes once, then condition (3) becomes
 * false and the form drops out of the sweep on subsequent days.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.5.6
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily cron for purging unredeemed ticket pools of ended forms.
 */
class ExpiredTicketsCleanup {

	/**
	 * Cron hook name. Activator registers, deactivator clears.
	 */
	public const CRON_HOOK = 'ffc_daily_expired_tickets_cleanup';

	/**
	 * Register the action handler. Call from the loader at boot.
	 */
	public static function init(): void {
		add_action(
			self::CRON_HOOK,
			static function (): void {
				self::run();
			}
		);
	}

	/**
	 * Schedule the daily event if not already scheduled. Call from
	 * the activator.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the scheduled event. Call from the deactivator.
	 */
	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Run a sweep — invoked by WP cron on the daily schedule.
	 *
	 * Returns the number of forms that had their ticket pool wiped
	 * (zero on a quiet day). Returned for testability; cron itself
	 * discards the value.
	 */
	public static function run(): int {
		$ids = get_posts(
			array(
				'post_type'        => 'ffc_form',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		$forms_purged = 0;
		foreach ( $ids as $form_id ) {
			$form_id = (int) $form_id;
			if ( self::purge_form_if_eligible( $form_id ) ) {
				++$forms_purged;
			}
		}

		return $forms_purged;
	}

	/**
	 * Check the eligibility of a single form and purge its ticket pool
	 * when all conditions are met.
	 *
	 * @param int $form_id Form post id.
	 * @return bool True when this call performed a purge.
	 */
	public static function purge_form_if_eligible( int $form_id ): bool {
		$config = get_post_meta( $form_id, '_ffc_form_config', true );
		if ( ! is_array( $config ) ) {
			return false;
		}

		$restrictions = isset( $config['restrictions'] ) && is_array( $config['restrictions'] )
			? $config['restrictions']
			: array();

		if ( '1' !== (string) ( $restrictions['ticket'] ?? '' ) ) {
			return false;
		}

		if ( ! \FreeFormCertificate\Security\Geofence::has_form_expired( $form_id ) ) {
			return false;
		}

		$codes_raw = isset( $config['generated_codes_list'] ) ? (string) $config['generated_codes_list'] : '';
		if ( '' === trim( $codes_raw ) ) {
			return false;
		}

		// Count non-empty lines as "tickets removed" for the audit log.
		$lines = preg_split( '/\R/', $codes_raw );
		if ( false === $lines ) {
			$lines = array();
		}
		$count = 0;
		foreach ( $lines as $line ) {
			if ( '' !== trim( (string) $line ) ) {
				++$count;
			}
		}

		$config['generated_codes_list'] = '';
		update_post_meta( $form_id, '_ffc_form_config', $config );

		// Audit — best-effort, doesn't block the sweep.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'tickets_purged_expired',
				'info',
				array(
					'form_id'         => $form_id,
					'tickets_removed' => $count,
					'reason'          => 'cron',
				)
			);
		}

		return true;
	}
}
