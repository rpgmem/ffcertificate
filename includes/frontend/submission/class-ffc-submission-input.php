<?php
/**
 * SubmissionInput — where a pipeline stage reads a request value.
 *
 * The submission stages were written for the AJAX form and read `$_POST`.
 * The REST submit route runs the same stages over its JSON body (#1861),
 * so the read goes through here: `$_POST` when the context carries no
 * input, the context's input when it does. The web path reads exactly
 * what it read before, through the same `RequestInput` sanitisers.
 *
 * @package FreeFormCertificate\Frontend\Submission
 * @since 6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request-value reader for the submission stages.
 */
final class SubmissionInput {

	/**
	 * The raw value of one key, unslashed, or null when absent.
	 *
	 * Raw on purpose: the caller sanitises it the way the field type
	 * requires, as FieldSanitizer does.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @param string            $key Request key.
	 * @return mixed
	 */
	public static function raw( SubmissionContext $ctx, string $key ) {
		if ( null !== $ctx->input ) {
			return $ctx->input[ $key ] ?? null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the caller verified the nonce (NonceGuard) and sanitises the value it asked for.
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
	}

	/**
	 * One key as sanitised text, `''` when absent or not scalar.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @param string            $key Request key.
	 * @return string
	 */
	public static function string( SubmissionContext $ctx, string $key ): string {
		if ( null === $ctx->input ) {
			return RequestInput::get_post_string( $key );
		}
		$value = $ctx->input[ $key ] ?? null;
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * One key as a non-negative integer, 0 when absent or not scalar.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @param string            $key Request key.
	 * @return int
	 */
	public static function int( SubmissionContext $ctx, string $key ): int {
		if ( null === $ctx->input ) {
			return RequestInput::get_post_int( $key );
		}
		$value = $ctx->input[ $key ] ?? null;
		return is_scalar( $value ) ? absint( $value ) : 0;
	}
}
