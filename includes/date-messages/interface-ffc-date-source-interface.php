<?php
/**
 * Date source contract.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a rule's date comes from (#1538).
 *
 * The birthday is the only source today. A second one -- an admission date, a
 * document's expiry -- is a new class plus one line in `DateSources`; nothing
 * in the resolver, the runner or the rules changes, because they only ever ask
 * a source who is due on a day and what that person's tokens are.
 */
interface DateSourceInterface {

	/**
	 * Stable id, stored in the rule.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Operator-facing label.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * The accounts whose date falls on a target day, in ascending user id
	 * after a cursor.
	 *
	 * @param \DateTimeImmutable $target        The day the date must fall on.
	 * @param int                $after_user_id Keyset cursor.
	 * @param int                $limit         Page size.
	 * @return array<int, array{user_id: int, email: string, name: string}>
	 */
	public function due( \DateTimeImmutable $target, int $after_user_id, int $limit ): array;

	/**
	 * Source-specific tokens for one person, e.g. `{{age}}`.
	 *
	 * @param int                $user_id The person.
	 * @param \DateTimeImmutable $target  The day of the date.
	 * @return array<string, string> Token name (no braces) => plain-text value.
	 */
	public function tokens( int $user_id, \DateTimeImmutable $target ): array;

	/**
	 * Fictional values for the same tokens, for previews and test sends.
	 *
	 * @param \DateTimeImmutable $target The day of the date.
	 * @return array<string, string>
	 */
	public function sample_tokens( \DateTimeImmutable $target ): array;
}
