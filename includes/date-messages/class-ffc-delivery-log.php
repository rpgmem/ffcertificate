<?php
/**
 * Date-message runs and delivery log.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Every statement in this class runs against the plugin's own ffc_date_message_runs / ffc_date_message_log tables, which WordPress exposes no API for. A send must read the live log, never a cache (#1538). Each statement is prepared into a variable that is checked for null before it runs, and the only interpolation is a list of %d placeholders whose ids are all bound.
/**
 * Runs and the per-delivery log (#1538).
 *
 * THE LOG IS THE DEDUPLICATION. `record()` inserts with `INSERT IGNORE`
 * against `UNIQUE(rule_id, user_id, occurrence_key, channel)`, and reports
 * whether a row went in. The runner records BEFORE it hands the message to
 * `wp_mail()`: a concurrent second run loses the insert and does not send,
 * where checking first and recording after would let both send. The cost of
 * that order is the opposite failure -- a send that dies after the insert is
 * logged and not retried -- and it is the right one to choose here: a missed
 * birthday message is a lesser harm than a duplicated one, and the mail queue
 * retries whatever `wp_mail()` accepted.
 */
class DeliveryLog {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Counter columns a run carries.
	 */
	public const COUNTERS = array( 'sent', 'opted_out', 'no_email', 'out_of_audience', 'failed' );

	/**
	 * Cache group (unused for reads; required by the trait).
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_date_message_runs';
	}

	/**
	 * Open a run.
	 *
	 * @param int    $rule_id    Rule.
	 * @param string $trigger    'cron' or 'manual'.
	 * @param string $target_from First target day, `Y-m-d`.
	 * @param string $target_to   Last target day, `Y-m-d`.
	 * @param int    $created_by  Operator for a manual run, 0 for the cron.
	 * @return int Run id, or 0 on failure.
	 */
	public static function start_run( int $rule_id, string $trigger, string $target_from, string $target_to, int $created_by ): int {
		$wpdb   = self::db();
		$result = $wpdb->insert(
			DateMessagesActivator::runs_table(),
			array(
				'rule_id'      => $rule_id,
				'trigger_kind' => $trigger,
				'target_from'  => $target_from,
				'target_to'    => $target_to,
				'started_at'   => time(),
				'created_by'   => $created_by > 0 ? $created_by : null,
			)
		);
		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Add to one of a run's counters.
	 *
	 * @param int    $run_id  Run.
	 * @param string $counter One of COUNTERS.
	 * @param int    $by      Amount.
	 * @return void
	 */
	public static function bump( int $run_id, string $counter, int $by = 1 ): void {
		if ( $by <= 0 || ! in_array( $counter, self::COUNTERS, true ) ) {
			return;
		}
		$wpdb = self::db();
		$sql  = $wpdb->prepare( 'UPDATE %i SET %i = %i + %d WHERE id = %d', DateMessagesActivator::runs_table(), $counter, $counter, $by, $run_id );
		if ( null !== $sql ) {
			$wpdb->query( $sql );
		}
	}

	/**
	 * Close a run.
	 *
	 * @param int $run_id Run.
	 * @return void
	 */
	public static function finish_run( int $run_id ): void {
		self::db()->update( DateMessagesActivator::runs_table(), array( 'finished_at' => time() ), array( 'id' => $run_id ) );
	}

	/**
	 * One run as stored.
	 *
	 * @param int $run_id Run.
	 * @return array<string, mixed>|null
	 */
	public static function get_run( int $run_id ): ?array {
		$wpdb = self::db();
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', DateMessagesActivator::runs_table(), $run_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The most recent runs, newest first, for the history screen.
	 *
	 * @param int $limit  Page size.
	 * @param int $offset Rows to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent_runs( int $limit, int $offset = 0 ): array {
		$wpdb = self::db();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
				DateMessagesActivator::runs_table(),
				max( 1, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$run = array();
			foreach ( $row as $column => $value ) {
				$run[ (string) $column ] = $value;
			}
			$out[] = $run;
		}
		return $out;
	}

	/**
	 * How many runs are stored.
	 *
	 * @return int
	 */
	public static function count_runs(): int {
		$wpdb = self::db();
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', DateMessagesActivator::runs_table() ) );
	}

	/**
	 * Claim one delivery. True when this call inserted the row -- and is
	 * therefore the one allowed to send -- false when it already existed.
	 *
	 * @param int    $run_id     Run.
	 * @param int    $rule_id    Rule.
	 * @param int    $user_id    Recipient.
	 * @param string $occurrence Target day, `Y-m-d`.
	 * @param string $channel    'user' (the digest is a later channel).
	 * @return bool
	 */
	public static function claim( int $run_id, int $rule_id, int $user_id, string $occurrence, string $channel = 'user' ): bool {
		$wpdb = self::db();
		$sql  = $wpdb->prepare(
			'INSERT IGNORE INTO %i (run_id, rule_id, user_id, occurrence_key, channel, sent_at) VALUES (%d, %d, %d, %s, %s, %d)',
			DateMessagesActivator::log_table(),
			$run_id,
			$rule_id,
			$user_id,
			$occurrence,
			$channel,
			time()
		);
		// A statement that did not prepare claims nothing, so nothing is sent.
		return null !== $sql && 1 === $wpdb->query( $sql );
	}

	/**
	 * Release a claim whose send was refused before it left the plugin, so a
	 * later run may try again.
	 *
	 * @param int    $rule_id    Rule.
	 * @param int    $user_id    Recipient.
	 * @param string $occurrence Target day.
	 * @param string $channel    Channel.
	 * @return void
	 */
	public static function release( int $rule_id, int $user_id, string $occurrence, string $channel = 'user' ): void {
		self::db()->delete(
			DateMessagesActivator::log_table(),
			array(
				'rule_id'        => $rule_id,
				'user_id'        => $user_id,
				'occurrence_key' => $occurrence,
				'channel'        => $channel,
			),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * The ids, among those given, already delivered for an occurrence.
	 *
	 * @param int             $rule_id    Rule.
	 * @param string          $occurrence Target day.
	 * @param array<int, int> $user_ids   Candidates.
	 * @param string          $channel    Channel.
	 * @return array<int, true>
	 */
	public static function delivered_among( int $rule_id, string $occurrence, array $user_ids, string $channel = 'user' ): array {
		$user_ids = array_values( array_filter( array_map( 'intval', $user_ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( array() === $user_ids ) {
			return array();
		}

		$wpdb         = self::db();
		$placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The sniff counts the literal's placeholders and does not know `prepare()` accepts a single array of arguments, which is how the table, the keys and the ids arrive.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM %i WHERE rule_id = %d AND occurrence_key = %s AND channel = %s AND user_id IN ({$placeholders})",
				array_merge( array( DateMessagesActivator::log_table(), $rule_id, $occurrence, $channel ), $user_ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$out = array();
		foreach ( (array) $found as $id ) {
			if ( is_numeric( $id ) ) {
				$out[ (int) $id ] = true;
			}
		}
		return $out;
	}
}
