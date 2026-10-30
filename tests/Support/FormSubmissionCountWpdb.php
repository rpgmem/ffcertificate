<?php
/**
 * A `$wpdb` that answers how many submissions a form holds.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Installs a global `$wpdb` whose only answer is a submission count (#2241).
 *
 * The form editor asks `SubmissionRepository::countLiveByForm()` whether a
 * form already holds submissions: once it does, field tags lock and the
 * layout warns about issued certificates. A test of the editor chooses that
 * answer here instead of inheriting whatever `$wpdb` an earlier test left in
 * the process, which is what made those tests pass in the full suite and
 * fail alone.
 */
final class FormSubmissionCountWpdb {

	/**
	 * The `$wpdb` the test replaced, restored by {@see restore()}.
	 *
	 * @var mixed
	 */
	private static $previous = null;

	/**
	 * Whether a `$wpdb` existed before {@see install()}.
	 *
	 * @var bool
	 */
	private static bool $had = false;

	/**
	 * Install the double.
	 *
	 * @param int $count What every count query returns.
	 */
	public static function install( int $count = 0 ): void {
		self::$had      = array_key_exists( 'wpdb', $GLOBALS );
		self::$previous = $GLOBALS['wpdb'] ?? null;

		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => (string) $sql )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( (string) $count )->byDefault();
		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Put back the `$wpdb` the test found.
	 */
	public static function restore(): void {
		if ( self::$had ) {
			$GLOBALS['wpdb'] = self::$previous;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		self::$previous = null;
		self::$had      = false;
	}
}
