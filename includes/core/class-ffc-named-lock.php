<?php
/**
 * NamedLock — a MySQL named lock around a check-then-write (#2695).
 *
 * WordPress has no lock API, and post meta and options carry no unique key,
 * so two requests that each read "not done yet" and then write both
 * succeed. GET_LOCK makes the second wait until the first has written.
 * The lock belongs to the connection, so MySQL releases it when a request
 * dies without releasing it.
 *
 * @package FreeFormCertificate\Core
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Take and release a MySQL named lock.
 */
final class NamedLock {

	/**
	 * Take the lock.
	 *
	 * @param string $name    Lock name, at most 64 characters (MySQL's limit).
	 * @param int    $timeout Seconds to wait for another holder.
	 * @return bool|null True when held, false when the wait ran out, null
	 *                   when the server cannot take named locks (an error,
	 *                   not a timeout) — the caller then goes on unlocked.
	 */
	public static function acquire( string $name, int $timeout ): ?bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock has no WordPress API and must never be cached.
		$granted = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );
		if ( null === $granted ) {
			return null;
		}
		return '1' === (string) $granted;
	}

	/**
	 * Run a read-modify-write of a form's `_ffc_form_config` under its lock.
	 *
	 * The ticket list and the editor's save both rewrite the whole array;
	 * without the lock, the later write drops what the earlier one changed
	 * (#2695). The meta cache is dropped first, so `$write` reads what any
	 * other request wrote while this one waited. A wait that runs out still
	 * writes, unlocked as before: dropping a used ticket's removal or an
	 * operator's save would lose more than the race the lock narrows.
	 *
	 * @param int      $form_id Form ID.
	 * @param callable $write   Reads and writes the config; its return is passed through.
	 * @return mixed What `$write` returned.
	 */
	public static function with_form_config( int $form_id, callable $write ) {
		$name = 'ffc_form_config_' . $form_id;
		$held = self::acquire( $name, 5 );
		try {
			wp_cache_delete( $form_id, 'post_meta' );
			return $write();
		} finally {
			if ( true === $held ) {
				self::release( $name );
			}
		}
	}

	/**
	 * Release a lock this request holds.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function release( string $name ): void {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock has no WordPress API and must never be cached.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}
