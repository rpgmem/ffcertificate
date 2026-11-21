<?php
/**
 * SubmissionIdentityLock — serialises submissions of one person to one form (#2695).
 *
 * Two requests with the same form and the same CPF/RF (or e-mail, when the
 * form asks for no document) could both pass the checks that read what is
 * already stored — the rate limit, the quiz attempt limit, the reprint
 * lookup — before either wrote its row, which issued two certificates and
 * let a second attempt through a limit of one. A MySQL named lock taken
 * after the read-only gates and held until the response is built makes the
 * second request wait for the first and then see its row.
 *
 * The lock is session-scoped: MySQL releases it when the connection closes,
 * so a request that dies mid-way never leaves it held.
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Named lock per (form, identity), released by the orchestrator.
 */
class SubmissionIdentityLock {

	/**
	 * Seconds a request waits for an earlier one with the same identity.
	 */
	public const WAIT_SECONDS = 10;

	/**
	 * The lock this request holds, or '' when it holds none.
	 *
	 * @var string
	 */
	private string $held = '';

	/**
	 * Take the lock for this form and person.
	 *
	 * A server that cannot take named locks (an error, not a timeout) lets
	 * the request through, as before the lock existed.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected When an earlier submission still holds it after WAIT_SECONDS.
	 */
	public function apply( SubmissionContext $ctx ): void {
		$identity = '' !== $ctx->val_cpf
			? 'cpf:' . \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $ctx->val_cpf )
			: 'email:' . \FreeFormCertificate\Core\DataSanitizer::normalize_email( $ctx->user_email );
		if ( 'email:' === $identity ) {
			return;
		}

		$name    = self::lock_name( $ctx->form_id, $identity );
		$granted = \FreeFormCertificate\Core\NamedLock::acquire( $name, self::WAIT_SECONDS );
		if ( null === $granted ) {
			return;
		}
		if ( ! $granted ) {
			throw new SubmissionRejected(
				array(
					'message' => __( 'Another submission with these details is still being processed. Wait a moment and try again.', 'ffcertificate' ),
				)
			);
		}
		$this->held = $name;
	}

	/**
	 * Release the lock, when this request holds one.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( '' === $this->held ) {
			return;
		}
		\FreeFormCertificate\Core\NamedLock::release( $this->held );
		$this->held = '';
	}

	/**
	 * The lock name: the identity is keyed-hashed, because lock names are
	 * readable by anyone who can list the server's locks.
	 *
	 * @param int    $form_id  Form ID.
	 * @param string $identity Normalised identity, prefixed by its kind.
	 * @return string At most 64 characters, MySQL's limit.
	 */
	public static function lock_name( int $form_id, string $identity ): string {
		return 'ffc_submit_' . substr( \FreeFormCertificate\Core\Encryption::keyed_hash( $form_id . '|' . $identity, 'submission_lock' ), 0, 40 );
	}
}
