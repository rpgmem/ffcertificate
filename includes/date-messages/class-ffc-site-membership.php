<?php
/**
 * Site membership for the date-message sources.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps a source's query to the members of the current site (#2692).
 *
 * On a multisite network `wp_users` and `wp_usermeta` hold the accounts of
 * every site, so a query over them alone reaches people this site never
 * added. A member of a site carries that site's `{prefix}capabilities` meta,
 * which is what `get_users()` reads for the same question; the join below
 * asks it in SQL. On a single site every account is a member, and there is
 * nothing to join.
 */
final class SiteMembership {

	/**
	 * The join that keeps `$user_column` to this site's members, with its bound value.
	 *
	 * @param string $user_column The query's user-id column, written by the caller (never input).
	 * @return array{sql: string, args: list<string>} Empty outside multisite.
	 */
	public static function join( string $user_column ): array {
		if ( ! is_multisite() ) {
			return array(
				'sql'  => '',
				'args' => array(),
			);
		}

		global $wpdb;
		return array(
			'sql'  => ' INNER JOIN ' . $wpdb->usermeta . ' ffc_site ON ffc_site.user_id = ' . $user_column . ' AND ffc_site.meta_key = %s',
			'args' => array( $wpdb->get_blog_prefix() . 'capabilities' ),
		);
	}
}
