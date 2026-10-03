<?php
/**
 * Date-message rule reader.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against the plugin's own ffc_date_message_rules table, which WordPress exposes no API for. The table is a handful of rows read by a daily job, so no read is cached (#1538).
/**
 * Read side of `ffc_date_message_rules` (#1538). Rows come back as `Rule`
 * value objects; a stored row that no longer validates (an unknown source,
 * say) is skipped rather than half-built.
 */
class RuleReader {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group, shared with `RuleWriter`.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_date_message_rules';
	}

	/**
	 * One rule.
	 *
	 * @param int $id Rule id.
	 * @return Rule|null
	 */
	public static function get_by_id( int $id ): ?Rule {
		$wpdb = self::db();
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', DateMessagesActivator::rules_table(), $id ),
			ARRAY_A
		);
		return is_array( $row ) ? self::to_rule( $row ) : null;
	}

	/**
	 * Every rule, active or not, by name.
	 *
	 * @return array<int, Rule>
	 */
	public static function all(): array {
		$wpdb = self::db();
		return self::to_rules( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC, id ASC', DateMessagesActivator::rules_table() ), ARRAY_A ) );
	}

	/**
	 * The rules the daily job sends.
	 *
	 * @return array<int, Rule>
	 */
	public static function active(): array {
		$wpdb = self::db();
		return self::to_rules( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE is_active = 1 ORDER BY id ASC', DateMessagesActivator::rules_table() ), ARRAY_A ) );
	}

	/**
	 * Rows as rules, skipping any that no longer validate.
	 *
	 * @param mixed $rows `get_results()` output.
	 * @return array<int, Rule>
	 */
	private static function to_rules( $rows ): array {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$rule = is_array( $row ) ? self::to_rule( $row ) : null;
			if ( null !== $rule ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/**
	 * A stored row as a rule, or null when it no longer validates.
	 *
	 * @param array<mixed, mixed> $row Row.
	 * @return Rule|null
	 */
	private static function to_rule( array $row ): ?Rule {
		$data = array();
		foreach ( $row as $key => $value ) {
			$data[ (string) $key ] = $value;
		}
		$rule = Rule::from_array( $data );
		return $rule instanceof Rule ? $rule : null;
	}
}
