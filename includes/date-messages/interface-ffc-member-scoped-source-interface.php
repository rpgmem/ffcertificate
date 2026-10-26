<?php
/**
 * Member-scoped date source contract.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A source that can read a given list of accounts instead of paging the site.
 *
 * Paging the whole site and dropping who is outside the rule's audiences
 * afterwards is cheap when the source itself is selective (a birthday matches
 * a few accounts a day), and ruinous when it is not: an announcement matches
 * every account, so a twenty-person audience on a 50,000-account site walked
 * the whole table (#1996). For such a source the resolver pages over the
 * audience members itself and asks only for those accounts.
 */
interface MemberScopedSourceInterface extends DateSourceInterface {

	/**
	 * The accounts among the given ids that are due on the target day, in
	 * ascending user id. An id with no account is left out.
	 *
	 * @param \DateTimeImmutable $target   The day of the send.
	 * @param array<int, int>    $user_ids One page of member ids, ascending.
	 * @return array<int, array{user_id: int, email: string, name: string}>
	 */
	public function due_among( \DateTimeImmutable $target, array $user_ids ): array;
}
