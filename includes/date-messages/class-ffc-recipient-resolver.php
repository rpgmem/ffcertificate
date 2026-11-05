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
 *   never widen to everyone because a toggle moved. A source that reads the
 *   members it is given (`MemberScopedSourceInterface`, the announcement) is
 *   paged over the members instead, so for it this decision never occurs
 *   (#1996); a dated source stays selective by its date and keeps it;
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
	 * The rule's audiences with their descendants, memoised per audience list.
	 *
	 * @var array<string, list<int>>
	 */
	private array $expanded = array();

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
	 * @return array{rows: array<int, array{user_id: int, email: string, name: string, decision: string, forced: bool}>, cursor: int, complete: bool}
	 */
	public function resolve( Rule $rule, \DateTimeImmutable $target, int $after, int $limit ): array {
		$source = $this->source ?? DateSources::for_rule( $rule );
		if ( null === $source ) {
			return array(
				'rows'     => array(),
				'cursor'   => $after,
				'complete' => true,
			);
		}

		$limit     = max( 1, $limit );
		$audiences = array() === $rule->audience_ids ? null : $this->audiences( $rule->audience_ids );
		$members   = null;

		if ( null !== $audiences && $source instanceof MemberScopedSourceInterface ) {
			// Page over the audience members, not over the site (#1996), with
			// a cursor in the database, so a batch reads one page of members
			// and never the whole list (#2336). The page is the ids, so an id
			// whose account is gone neither stops the run early nor holds the
			// cursor back.
			$page       = $this->member_page( $audiences, $after, $limit );
			$candidates = array() === $page ? array() : $source->due_among( $target, $page );
			$cursor     = array() === $page ? $after : (int) end( $page );
			$complete   = count( $page ) < $limit;
			$members    = array_fill_keys( $page, true );
		} else {
			$candidates = $source->due( $target, $after, $limit );
			$cursor     = $after;
			$complete   = count( $candidates ) < $limit;
		}

		$ids = array_map( static fn( array $c ): int => $c['user_id'], $candidates );
		if ( null !== $audiences && null === $members ) {
			// A dated source: only this page's candidates are looked up.
			$members = $this->members_among( $audiences, $ids );
		}
		$occurrence = $rule->occurrence_key( $target );

		$opted_out = OptOut::among( $ids, $rule->opt_out_category() );
		$delivered = DeliveryLog::delivered_among( $rule->id, $occurrence, $ids );

		$rows = array();
		foreach ( $candidates as $candidate ) {
			$id     = $candidate['user_id'];
			$cursor = max( $cursor, $id );

			if ( null !== $members && ! isset( $members[ $id ] ) ) {
				$decision = self::OUT_OF_AUDIENCE;
			} elseif ( isset( $opted_out[ $id ] ) && $rule->respect_opt_out ) {
				$decision = self::OPTED_OUT;
			} elseif ( ! is_email( $candidate['email'] ) ) {
				$decision = self::NO_EMAIL;
			} elseif ( isset( $delivered[ $id ] ) ) {
				$decision = self::ALREADY_SENT;
			} else {
				$decision = self::WILL_SEND;
			}

			// Forced: an opted-out person an announcement reaches anyway, because
			// the operator chose "send to everyone". Labelled, so it is never silent.
			$forced = self::WILL_SEND === $decision && isset( $opted_out[ $id ] );

			$rows[] = $candidate + array(
				'decision' => $decision,
				'forced'   => $forced,
			);
		}

		return array(
			'rows'     => $rows,
			'cursor'   => $cursor,
			'complete' => $complete,
		);
	}

	/**
	 * One page of member ids after the cursor, ascending.
	 *
	 * @param array<int> $audiences Audiences with their descendants.
	 * @param int        $after     Keyset cursor.
	 * @param int        $limit     Page size.
	 * @return list<int>
	 */
	private function member_page( array $audiences, int $after, int $limit ): array {
		return class_exists( AudienceReader::class ) ? AudienceReader::member_page( $audiences, $after, $limit ) : array();
	}

	/**
	 * The candidates who belong to any of the audiences.
	 *
	 * @param array<int> $audiences Audiences with their descendants.
	 * @param array<int> $user_ids  Candidates.
	 * @return array<int, true>
	 */
	private function members_among( array $audiences, array $user_ids ): array {
		return class_exists( AudienceReader::class ) ? AudienceReader::members_among( $audiences, $user_ids ) : array();
	}

	/**
	 * Whether any of the audiences has a member, sub-audiences included (#1949).
	 * An announcement to an audience with nobody in it has nothing to send.
	 *
	 * @param array<int> $audience_ids Audiences.
	 * @return bool
	 */
	public function audience_has_members( array $audience_ids ): bool {
		return array() !== $this->member_page( $this->audiences( $audience_ids ), 0, 1 );
	}

	/**
	 * The audiences with their sub-audiences, each once.
	 *
	 * @param array<int> $audience_ids Audiences.
	 * @return list<int>
	 */
	private function audiences( array $audience_ids ): array {
		$key = implode( ',', array_map( 'intval', $audience_ids ) );
		if ( ! isset( $this->expanded[ $key ] ) ) {
			$this->expanded[ $key ] = class_exists( AudienceReader::class ) ? AudienceReader::with_descendants( $audience_ids ) : array();
		}
		return $this->expanded[ $key ];
	}
}
