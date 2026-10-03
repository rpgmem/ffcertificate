<?php
/**
 * Date-messages recipient preview over a range.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Core\DocumentFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who a rule reaches over a range of target days, without sending (#1538).
 *
 * It walks the same `RecipientResolver` the runner sends from, day by day and
 * page by page, so the recipient preview and the upcoming-dates panel cannot
 * disagree with the send. It writes nothing. Totals count everyone; the list
 * of people stops at a limit, and says so.
 */
final class RecipientPreview {

	/**
	 * Most people listed by name.
	 */
	public const ROW_LIMIT = 500;

	/**
	 * Walk the range.
	 *
	 * @param Rule               $rule      The rule (saved or not).
	 * @param \DateTimeImmutable $from      First target day.
	 * @param \DateTimeImmutable $to        Last target day.
	 * @param bool               $with_rows Whether to list people.
	 * @return array{totals: array<string, int>, rows: array<int, array{user_id: int, name: string, email: string, date: string, decision: string}>, truncated: bool, pii: bool}
	 */
	public static function collect( Rule $rule, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $with_rows ): array {
		$totals    = array_fill_keys( array_keys( DateMessagesAdminPage::decision_labels() ), 0 );
		$rows      = array();
		$truncated = false;
		$resolver  = new RecipientResolver();

		for ( $day = $from; $day <= $to; $day = $day->modify( '+1 day' ) ) {
			$after = 0;
			do {
				$page = $resolver->resolve( $rule, $day, $after, Runner::BATCH_SIZE );
				foreach ( $page['rows'] as $row ) {
					$totals[ $row['decision'] ] = ( $totals[ $row['decision'] ] ?? 0 ) + 1;
					if ( ! $with_rows ) {
						continue;
					}
					if ( count( $rows ) >= self::ROW_LIMIT ) {
						$truncated = true;
						continue;
					}
					$rows[] = array(
						'user_id'  => $row['user_id'],
						'name'     => $row['name'],
						'email'    => DocumentFormatter::mask_email( $row['email'] ),
						'date'     => $day->format( 'Y-m-d' ),
						'decision' => $row['decision'],
					);
				}
				$after = $page['cursor'];
			} while ( ! $page['complete'] );
		}

		return array(
			'totals'    => $totals,
			'rows'      => $rows,
			'truncated' => $truncated,
			'pii'       => $with_rows,
		);
	}
}
