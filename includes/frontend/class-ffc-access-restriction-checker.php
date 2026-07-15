<?php
/**
 * AccessRestrictionChecker
 *
 * Extracted from FormProcessor (Sprint 16 refactoring).
 * Validates form access rules: password, denylist, allowlist, and ticket.
 *
 * @package FreeFormCertificate\Frontend
 * @since 4.12.17
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\Utils;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checker for access restriction conditions.
 */
class AccessRestrictionChecker {

	/**
	 * Option-name prefix for the atomic one-use ticket ledger.
	 *
	 * Each consumed ticket is recorded as a distinct `wp_options` row whose
	 * `option_name` is UNIQUE, so the row insertion itself is the lock that
	 * serializes concurrent claims of the same ticket.
	 *
	 * @var string
	 */
	private const TICKET_CLAIM_OPTION_PREFIX = 'ffc_ticket_used_';

	/**
	 * Check if submission passes restriction rules
	 *
	 * Validation order: Password → Denylist (priority) → Allowlist → Ticket (consumed)
	 *
	 * @param array<string, mixed> $form_config Form configuration.
	 * @param string               $val_cpf CPF/RF from form (already cleaned).
	 * @param string               $val_ticket Ticket from form.
	 * @param int                  $form_id Form ID (needed for ticket consumption).
	 * @return array<string, mixed> ['allowed' => bool, 'message' => string, 'is_ticket' => bool]
	 */
	public static function check( array $form_config, string $val_cpf, string $val_ticket, int $form_id ): array {
		$restrictions = isset( $form_config['restrictions'] ) ? $form_config['restrictions'] : array();

		// Clean CPF/RF (remove any mask).
		$clean_cpf = preg_replace( '/\D/', '', $val_cpf );

		// ========================================.
		// 1. PASSWORD CHECK (if active)
		// ========================================.
		if ( ! empty( $restrictions['password'] ) && '1' === $restrictions['password'] ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_submission_ajax() caller.
			$password       = trim( RequestInput::get_post_string( 'ffc_password' ) );
			$valid_password = isset( $form_config['validation_code'] ) ? $form_config['validation_code'] : '';

			if ( empty( $password ) ) {
				return array(
					'allowed'   => false,
					'message'   => __( 'Password is required.', 'ffcertificate' ),
					'is_ticket' => false,
				);
			}

			if ( $password !== $valid_password ) {
				return array(
					'allowed'   => false,
					'message'   => __( 'Incorrect password.', 'ffcertificate' ),
					'is_ticket' => false,
				);
			}
		}

		// ========================================.
		// 2. DENYLIST CHECK (if active - HAS PRIORITY)
		// ========================================.
		if ( ! empty( $restrictions['denylist'] ) && '1' === $restrictions['denylist'] ) {
			$denied_raw  = isset( $form_config['denied_users_list'] ) ? $form_config['denied_users_list'] : '';
			$denied_list = array_filter( array_map( 'trim', explode( "\n", $denied_raw ) ) );

			// Clean masks from denylist before comparing.
			$denied_clean = array_map(
				function ( $d ) {
					return preg_replace( '/\D/', '', $d );
				},
				$denied_list
			);

			if ( in_array( $clean_cpf, $denied_clean, true ) ) {
				return array(
					'allowed'   => false,
					'message'   => __( 'Your CPF/RF is blocked.', 'ffcertificate' ),
					'is_ticket' => false,
				);
			}
		}

		// ========================================.
		// 3. ALLOWLIST CHECK (if active)
		// ========================================.
		if ( ! empty( $restrictions['allowlist'] ) && '1' === $restrictions['allowlist'] ) {
			$allowed_raw  = isset( $form_config['allowed_users_list'] ) ? $form_config['allowed_users_list'] : '';
			$allowed_list = array_filter( array_map( 'trim', explode( "\n", $allowed_raw ) ) );

			// Clean masks from allowlist before comparing.
			$allowed_clean = array_map(
				function ( $a ) {
					return preg_replace( '/\D/', '', $a );
				},
				$allowed_list
			);

			if ( ! in_array( $clean_cpf, $allowed_clean, true ) ) {
				return array(
					'allowed'   => false,
					'message'   => __( 'Your CPF/RF is not authorized.', 'ffcertificate' ),
					'is_ticket' => false,
				);
			}
		}

		// ========================================.
		// 4. TICKET CHECK (if active - CONSUMED)
		// ========================================.
		if ( ! empty( $restrictions['ticket'] ) && '1' === $restrictions['ticket'] ) {
			$ticket = strtoupper( trim( $val_ticket ) );

			if ( empty( $ticket ) ) {
				return array(
					'allowed'   => false,
					'message'   => __( 'Ticket code is required.', 'ffcertificate' ),
					'is_ticket' => false,
				);
			}

			$tickets_raw = isset( $form_config['generated_codes_list'] ) ? $form_config['generated_codes_list'] : '';
			$tickets     = array_filter(
				array_map(
					function ( $t ) {
						return strtoupper( trim( $t ) );
					},
					explode( "\n", $tickets_raw )
				)
			);

			if ( ! in_array( $ticket, $tickets, true ) ) {
				return array(
					'allowed'   => false,
					'message'   => __( 'Invalid or already used ticket.', 'ffcertificate' ),
					'is_ticket' => false,
				);
			}

			// Atomically claim the ticket. The list membership check above is
			// a fast pre-filter, but the list read-modify-write below is NOT
			// atomic, so two concurrent submissions could both pass it and
			// each issue a certificate from one single-use ticket. The claim
			// is the authoritative one-use gate: only the first concurrent
			// caller wins the UNIQUE-keyed insert; a loser is rejected as
			// already-used, closing the TOCTOU race.
			if ( ! self::try_claim_ticket( $form_id, $ticket ) ) {
				return array(
					'allowed'   => false,
					'message'   => __( 'Invalid or already used ticket.', 'ffcertificate' ),
					'is_ticket' => false,
				);
			}

			// Consume ticket (remove from list). Best-effort bookkeeping so the
			// admin's remaining-tickets view stays accurate; the claim above,
			// not this write, is what enforces single use.
			$tickets                             = array_diff( $tickets, array( $ticket ) );
			$form_config['generated_codes_list'] = implode( "\n", $tickets );
			update_post_meta( $form_id, '_ffc_form_config', $form_config );

			return array(
				'allowed'   => true,
				'message'   => '',
				'is_ticket' => true,
			);
		}

		// ========================================.
		// NO RESTRICTIONS ACTIVE - ALLOW.
		// ========================================.
		return array(
			'allowed'   => true,
			'message'   => '',
			'is_ticket' => false,
		);
	}

	/**
	 * Atomically claim a one-use ticket.
	 *
	 * Inserts a UNIQUE-keyed marker row into `wp_options` for this
	 * (form, ticket) pair. `INSERT IGNORE` makes the write a no-op when the
	 * row already exists, so under concurrency exactly one caller sees
	 * `rows_affected > 0` (the winner) and every other caller sees `0`
	 * (already claimed). This is the same atomic single-use pattern used by
	 * {@see ScheduleExceptionSession::try_consume_jti()}. The marker row is
	 * `autoload => 'no'` so it never enters the alloptions cache.
	 *
	 * @param int    $form_id Form ID the ticket belongs to.
	 * @param string $ticket  Normalised (upper-cased, trimmed) ticket code.
	 * @return bool True when this caller won the claim (or no DB layer is
	 *              available to serialize on); false when the ticket was
	 *              already consumed by a concurrent or prior caller.
	 */
	private static function try_claim_ticket( int $form_id, string $ticket ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			// No DB layer to serialize on — cannot make the claim atomic.
			// Fall back to the legacy (non-atomic) list check by allowing;
			// this preserves prior behaviour rather than hard-failing.
			return true;
		}

		$option_name = self::TICKET_CLAIM_OPTION_PREFIX . $form_id . '_' . hash( 'sha256', $ticket );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic single-use claim; the UNIQUE option_name index IS the lock and a cached read would defeat it.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option_name,
				(string) time()
			)
		);

		return (int) $wpdb->rows_affected > 0;
	}

	/**
	 * Remove used ticket from form configuration
	 *
	 * @param int    $form_id Form ID.
	 * @param string $ticket Ticket code to consume.
	 */
	public static function consume_ticket( int $form_id, string $ticket ): void {
		$current_config                         = get_post_meta( $form_id, '_ffc_form_config', true );
		$current_raw_codes                      = isset( $current_config['generated_codes_list'] ) ? $current_config['generated_codes_list'] : '';
		$current_list                           = array_filter( array_map( 'trim', explode( "\n", $current_raw_codes ) ) );
		$updated_list                           = array_diff( $current_list, array( $ticket ) );
		$current_config['generated_codes_list'] = implode( "\n", $updated_list );
		update_post_meta( $form_id, '_ffc_form_config', $current_config );
	}
}
