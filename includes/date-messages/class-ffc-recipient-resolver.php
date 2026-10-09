<?php
/**
 * Date-message recipient resolver.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Audience\AudienceReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who a rule reaches on a day, and why each candidate is in or out (#1538).
 *
 * THE ONE SELECTION. The runner sends to the `will_send` rows this returns and
 * the recipient preview lists all of them, so the preview cannot disagree
 * with what is sent: there is no second query imitating the first.
 *
 * Decisions, checked in this order, first match wins:
 *
 * - `out_of_audience` -- the rule names audiences and the person is in none
 *   of them (sub-audiences count; any one is enough, #1648). The filter reads the membership whether or not the
 *   Audiences module is toggled on: a rule restricted to an audience must
 *   never widen to everyone because a toggle moved;
 * - `opted_out`       -- the person turned date messages off;
 * - `no_email`        -- the account's address is empty or not an address;
 * - `already_sent`    -- the log already holds this delivery;
 * - `will_send`.
 */
final class RecipientResolver {

	public const WILL_SEND       = 'will_send';
	public const OUT_OF_AUDIENCE = 'out_of_audience';
	public const OPTED_OUT       = 'opted_out';
	public const NO_EMAIL        = 'no_email';
	public const ALREADY_SENT    = 'already_sent';

	/**
	 * Member ids per audience, memoised for this resolver's lifetime.
	 *
	 * @var array<int, array<int, true>>
	 */
	private array $members = array();

	/**
	 * Constructor.
	 *
	 * @param DateSourceInterface|null $source A fixed source, overriding the
	 *                                         rule's (tests and previews of an
	 *                                         unsaved rule); null resolves it
	 *                                         from the rule.
	 */
	public function __construct( private ?DateSourceInterface $source = null ) {}

	/**
	 * One page of candidates for a rule on a target day.
	 *
	 * @param Rule               $rule   The rule.
	 * @param \DateTimeImmutable $target The day the date falls on.
	 * @param int                $after  Keyset cursor (user id).
	 * @param int                $limit  Page size.
	 * @return array{rows: array<int, array{user_id: int, email: string, name: string, decision: string}>, cursor: int, complete: bool}
	 */
	public function resolve( Rule $rule, \DateTimeImmutable $target, int $after, int $limit ): array {
		$source = $this->source ?? DateSources::get( $rule->source );
		if ( null === $source ) {
			return array(
				'rows'     => array(),
				'cursor'   => $after,
				'complete' => true,
			);
		}

		$limit      = max( 1, $limit );
		$candidates = $source->due( $target, $after, $limit );
		$ids        = array_map( static fn( array $c ): int => $c['user_id'], $candidates );
		$occurrence = $target->format( 'Y-m-d' );

		$opted_out = OptOut::among( $ids );
		$delivered = DeliveryLog::delivered_among( $rule->id, $occurrence, $ids );
		$members   = array() === $rule->audience_ids ? null : $this->members_of_any( $rule->audience_ids );

		$rows   = array();
		$cursor = $after;
		foreach ( $candidates as $candidate ) {
			$id     = $candidate['user_id'];
			$cursor = max( $cursor, $id );

			if ( null !== $members && ! isset( $members[ $id ] ) ) {
				$decision = self::OUT_OF_AUDIENCE;
			} elseif ( isset( $opted_out[ $id ] ) ) {
				$decision = self::OPTED_OUT;
			} elseif ( ! is_email( $candidate['email'] ) ) {
				$decision = self::NO_EMAIL;
			} elseif ( isset( $delivered[ $id ] ) ) {
				$decision = self::ALREADY_SENT;
			} else {
				$decision = self::WILL_SEND;
			}

			$rows[] = $candidate + array( 'decision' => $decision );
		}

		return array(
			'rows'     => $rows,
			'cursor'   => $cursor,
			'complete' => count( $candidates ) < $limit,
		);
	}

	/**
	 * Members of any of the audiences, sub-audiences included, as a set.
	 *
	 * @param array<int> $audience_ids Audiences.
	 * @return array<int, true>
	 */
	private function members_of_any( array $audience_ids ): array {
		$set = array();
		foreach ( $audience_ids as $audience_id ) {
			$set += $this->members_of( (int) $audience_id );
		}
		return $set;
	}

	/**
	 * Members of an audience and its sub-audiences, as a set.
	 *
	 * @param int $audience_id Audience.
	 * @return array<int, true>
	 */
	private function members_of( int $audience_id ): array {
		if ( ! isset( $this->members[ $audience_id ] ) ) {
			$set = array();
			if ( class_exists( AudienceReader::class ) ) {
				foreach ( AudienceReader::get_members( $audience_id, true ) as $id ) {
					$set[ (int) $id ] = true;
				}
			}
			$this->members[ $audience_id ] = $set;
		}
		return $this->members[ $audience_id ];
	}
}
