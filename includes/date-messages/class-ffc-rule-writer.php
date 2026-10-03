<?php
/**
 * Date-message rule writer.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against the plugin's own ffc_date_message_rules table, which WordPress exposes no API for (#1538).
/**
 * Write side of `ffc_date_message_rules` (#1538). It only ever stores a
 * `Rule`, so whatever reaches the table passed `Rule::from_array()`.
 */
class RuleWriter {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group, shared with `RuleReader`.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_date_message_rules';
	}

	/**
	 * Insert a new rule, or update the one its id names.
	 *
	 * @param Rule $rule Validated rule.
	 * @return int|false The rule id, or false on failure.
	 */
	public static function save( Rule $rule ) {
		$wpdb    = self::db();
		$columns = $rule->to_columns();
		$now     = time();

		$columns['updated_at'] = $now;

		if ( $rule->id > 0 ) {
			$result = $wpdb->update( DateMessagesActivator::rules_table(), $columns, array( 'id' => $rule->id ) );
			return false === $result ? false : $rule->id;
		}

		$columns['created_at'] = $now;
		$result                = $wpdb->insert( DateMessagesActivator::rules_table(), $columns );
		return false === $result ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Delete a rule. Its runs and log stay: they are the record of what was
	 * sent, and a deleted rule sends nothing more.
	 *
	 * @param int $id Rule id.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		return false !== self::db()->delete( DateMessagesActivator::rules_table(), array( 'id' => $id ), array( '%d' ) );
	}
}
