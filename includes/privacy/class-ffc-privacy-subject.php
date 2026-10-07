<?php
/**
 * PrivacySubject
 *
 * Who a WordPress privacy request is about, in the terms the plugin's
 * tables can be searched by.
 *
 * @package FreeFormCertificate\Privacy
 * @since 6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Privacy;

use FreeFormCertificate\Core\SensitiveFieldRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The subject of a privacy export or erasure request (#1574).
 *
 * WordPress hands the exporters and the eraser an e-mail address, and the
 * request has already been confirmed by a link sent to that address, so the
 * address is the identity being proven. Until #1574 every exporter turned it
 * into an account with `get_user_by()` and stopped there, which missed every
 * record made without logging in: a submission or an appointment keeps the
 * e-mail only as a salted hash and a ciphertext.
 *
 * A record belongs to the subject when it is linked to the subject's account,
 * or when it is linked to NO account and its stored e-mail hash is the
 * subject's. A record linked to a different account is that account's, even
 * if it carries the same address.
 */
final class PrivacySubject {

	/**
	 * A value no `user_id` column holds, used when there is no account.
	 */
	private const NO_ACCOUNT = -1;

	/**
	 * A value no hash column holds (hashes are hexadecimal), used when the
	 * address cannot be hashed.
	 */
	private const NO_HASH = '-';

	/**
	 * Account ID, or 0 when the address has no account.
	 *
	 * @var int
	 */
	public int $user_id;

	/**
	 * Salted hash of the canonical address, or null when it cannot be hashed.
	 *
	 * @var string|null
	 */
	public ?string $email_hash;

	/**
	 * Constructor.
	 *
	 * @param int         $user_id    Account ID, or 0.
	 * @param string|null $email_hash E-mail hash, or null.
	 */
	public function __construct( int $user_id, ?string $email_hash ) {
		$this->user_id    = max( 0, $user_id );
		$this->email_hash = ( null !== $email_hash && '' !== $email_hash ) ? $email_hash : null;
	}

	/**
	 * Resolve the subject of a request from its e-mail address.
	 *
	 * @param string $email_address Address from the privacy request.
	 * @return self
	 */
	public static function from_email( string $email_address ): self {
		$user = get_user_by( 'email', $email_address );

		return new self(
			$user ? (int) $user->ID : 0,
			SensitiveFieldRegistry::hash_identifier( 'email', $email_address )
		);
	}

	/**
	 * Whether there is nothing to search by.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return 0 === $this->user_id && null === $this->email_hash;
	}

	/**
	 * The predicate selecting the subject's rows of a table aliased
	 * `$alias` that has `user_id` and `email_hash` columns, prepared.
	 *
	 * A legacy row may carry `0` rather than NULL for "no account", so both
	 * count as unlinked. The alias is a fixed identifier chosen by the caller,
	 * checked here so it can never carry anything else.
	 *
	 * @param string $alias Table alias used in the caller's query.
	 * @return string SQL fragment with every value bound.
	 */
	public function owns_row( string $alias ): string {
		global $wpdb;

		if ( 1 !== preg_match( '/^[a-z]{1,8}$/', $alias ) ) {
			$alias = 't';
		}

		return $wpdb->prepare(
			"({$alias}.user_id = %d OR (({$alias}.user_id IS NULL OR {$alias}.user_id = 0) AND {$alias}.email_hash = %s))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $alias is matched against /^[a-z]{1,8}$/ just above, so it is an identifier and never data; every value is bound.
			$this->user_id > 0 ? $this->user_id : self::NO_ACCOUNT,
			$this->email_hash ?? self::NO_HASH
		);
	}
}
