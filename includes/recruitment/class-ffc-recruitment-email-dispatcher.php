<?php
/**
 * Recruitment Email Dispatcher
 *
 * Renders the §11 convocation email and sends it via `wp_mail`. Pulls the
 * subject / from / body templates from {@see RecruitmentSettings},
 * resolves all `{{placeholder}}` tokens against the call's
 * candidate / classification / notice / adjutancy data, and fires once
 * per call creation.
 *
 * Per §7 of the plan, send failures are deliberately NOT registered (no
 * `email_sent` flag on the call row): the call is committed regardless,
 * and high-volume installs are advised to install `rpgmem/total-mail-queue`
 * for retry/visibility. Best-effort by design — instrumentation must
 * never disrupt the convocation flow.
 *
 * Resolved placeholders (§11):
 *
 *   {{name}}, {{cpf_masked}}, {{rf_masked}}, {{email_masked}},
 *   {{adjutancy}}, {{notice_code}}, {{notice_name}},
 *   {{rank}}, {{score}}, {{is_pcd}},
 *   {{date_to_assume}}, {{time_to_assume}},
 *   {{called_at}}, {{site_name}}, {{site_url}}, {{notes}}
 *
 * `{{is_pcd}}` resolves to translatable `Yes` / `No` (English source so
 * pt-BR `.po` produces `Sim` / `Não`). Sensitive fields (CPF/RF/email)
 * are rendered MASKED via `DocumentFormatter` per §10-bis — the candidate
 * receives only the redacted forms.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service: render and send the convocation email.
 *
 * Returns `true` on attempted send (whether `wp_mail` actually succeeded
 * is opaque — see §7 rationale). Returns `false` only when there's no
 * email on file for the candidate (admin-side warning surface — the call
 * still proceeds upstream).
 *
 * @phpstan-import-type CandidateRow      from RecruitmentCandidateRepository
 * @phpstan-import-type ClassificationRow from RecruitmentClassificationRepository
 * @phpstan-import-type NoticeRow         from RecruitmentNoticeRepository
 * @phpstan-import-type AdjutancyRow      from RecruitmentAdjutancyRepository
 * @phpstan-import-type CallRow           from RecruitmentCallRepository
 */
final class RecruitmentEmailDispatcher {

	/**
	 * Send the convocation email for a freshly-committed call row.
	 *
	 * Caller (sprint 6's `RecruitmentCallService`) invokes this after
	 * `COMMIT`; failures here do NOT roll the call back. Returns `false`
	 * when the candidate has no email on file (so the admin can show a
	 * "no email — contact candidate manually" warning).
	 *
	 * @param int $call_id Newly created call row ID.
	 * @return bool True on send attempted; false on missing email.
	 */
	public static function send_for_call( int $call_id ): bool {
		$call = RecruitmentCallRepository::get_by_id( $call_id );
		if ( null === $call ) {
			return false;
		}

		$classification = RecruitmentClassificationRepository::get_by_id( (int) $call->classification_id );
		if ( null === $classification ) {
			return false;
		}

		$candidate = RecruitmentCandidateRepository::get_by_id( (int) $classification->candidate_id );
		if ( null === $candidate ) {
			return false;
		}

		$notice    = RecruitmentNoticeRepository::get_by_id( (int) $classification->notice_id );
		$adjutancy = RecruitmentAdjutancyRepository::get_by_id( (int) $classification->adjutancy_id );
		if ( null === $notice || null === $adjutancy ) {
			return false;
		}

		$email_plain = self::decrypt_email( $candidate->email_encrypted );
		if ( null === $email_plain || '' === $email_plain ) {
			// No email on file — admin handles externally per §7.
			return false;
		}

		$tokens   = self::build_token_map( $candidate, $classification, $notice, $adjutancy, $call );
		$settings = RecruitmentSettings::all();

		$subject   = self::render( $settings['email_subject'], $tokens );
		$body      = self::render( $settings['email_body_html'], $tokens );
		$plain     = wp_strip_all_tags( $body );
		$headers   = array( 'Content-Type: text/html; charset=UTF-8' );
		$from_pair = self::build_from_header( $settings );
		if ( null !== $from_pair ) {
			$headers[] = 'From: ' . $from_pair;
		}

		// Send HTML primary; the multipart/text alt is offered via the
		// 'wp_mail_alternative_text' filter so SMTP plugins (or
		// rpgmem/total-mail-queue) can pick it up. WP core lacks a
		// first-class multipart helper, so we attach the plaintext via a
		// scoped one-shot filter.
		$plain_filter = static function ( string $alt ) use ( $plain ): string {
			return '' === $alt ? $plain : $alt;
		};
		add_filter( 'wp_mail_alternative_text', $plain_filter );

		wp_mail( $email_plain, $subject, $body, $headers );

		remove_filter( 'wp_mail_alternative_text', $plain_filter );

		return true;
	}

	/**
	 * Build the `From: "Name" <addr>` header value, or null when both
	 * settings are blank (let `wp_mail` use its default).
	 *
	 * @param array<string, mixed> $settings Output of `RecruitmentSettings::all()`.
	 * @return string|null
	 */
	private static function build_from_header( array $settings ): ?string {
		$addr = is_string( $settings['email_from_address'] ?? null ) ? trim( $settings['email_from_address'] ) : '';
		$name = is_string( $settings['email_from_name'] ?? null ) ? trim( $settings['email_from_name'] ) : '';

		if ( '' === $addr ) {
			return null;
		}

		if ( '' === $name ) {
			return $addr;
		}

		return sprintf( '"%s" <%s>', str_replace( '"', '', $name ), $addr );
	}

	/**
	 * Compute the placeholder → value map for a single call row.
	 *
	 * @param object $candidate      Candidate row (CandidateRow shape).
	 * @param object $classification Classification row (ClassificationRow shape).
	 * @param object $notice         Notice row (NoticeRow shape).
	 * @param object $adjutancy      Adjutancy row (AdjutancyRow shape).
	 * @param object $call           Call row (CallRow shape).
	 * @phpstan-param CandidateRow      $candidate
	 * @phpstan-param ClassificationRow $classification
	 * @phpstan-param NoticeRow         $notice
	 * @phpstan-param AdjutancyRow      $adjutancy
	 * @phpstan-param CallRow           $call
	 * @return array<string, string>
	 */
	private static function build_token_map( object $candidate, object $classification, object $notice, object $adjutancy, object $call ): array {
		$cpf_plain   = self::decrypt( $candidate->cpf_encrypted ?? null );
		$rf_plain    = self::decrypt( $candidate->rf_encrypted ?? null );
		$email_plain = self::decrypt_email( $candidate->email_encrypted ?? null );

		$is_pcd = RecruitmentPcdHasher::verify( $candidate->pcd_hash, (int) $candidate->id );

		return array(
			'name'           => $candidate->name,
			'cpf_masked'     => null === $cpf_plain ? '' : DocumentFormatter::mask_cpf( $cpf_plain ),
			'rf_masked'      => null === $rf_plain ? '' : DocumentFormatter::mask_rf( $rf_plain ),
			'email_masked'   => null === $email_plain ? '' : DocumentFormatter::mask_email( $email_plain ),
			'adjutancy'      => $adjutancy->name,
			'notice_code'    => $notice->code,
			'notice_name'    => $notice->name,
			'rank'           => (string) $classification->rank,
			'score'          => $classification->score,
			'is_pcd'         => true === $is_pcd ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' ),
			'date_to_assume' => $call->date_to_assume,
			'time_to_assume' => $call->time_to_assume,
			'called_at'      => $call->called_at,
			'site_name'      => wp_specialchars_decode( (string) get_option( 'blogname', '' ), ENT_QUOTES ),
			'site_url'       => (string) get_option( 'siteurl', '' ),
			'notes'          => null === $call->notes ? '' : $call->notes,
		);
	}

	/**
	 * Substitute every `{{token}}` in a template with its mapped value.
	 *
	 * Unknown tokens (not in the map) are left untouched so missing data
	 * is visible in the rendered email — easier to spot than silent
	 * empties. Token names are case-sensitive.
	 *
	 * @param string                $template Template string.
	 * @param array<string, string> $tokens   Token → value map.
	 * @return string
	 */
	private static function render( string $template, array $tokens ): string {
		$replacements = array();
		foreach ( $tokens as $key => $value ) {
			$replacements[ '{{' . $key . '}}' ] = $value;
		}
		return strtr( $template, $replacements );
	}

	/**
	 * Decrypt a `*_encrypted` column or return null.
	 *
	 * @param mixed $value Stored ciphertext or null.
	 * @return string|null
	 */
	private static function decrypt( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$plain = Encryption::decrypt( $value );
		return null === $plain ? null : $plain;
	}

	/**
	 * Decrypt the email column specifically — typed wrapper for clarity.
	 *
	 * @param mixed $value Stored ciphertext or null.
	 * @return string|null
	 */
	private static function decrypt_email( $value ): ?string {
		return self::decrypt( $value );
	}
}
