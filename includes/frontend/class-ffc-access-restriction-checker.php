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
	 * @param string|null          $val_password Password the caller submitted; null reads `ffc_password` from POST.
	 * @param \Closure|null        $is_reprint   Answers whether the ticket already issued a certificate, from the
	 *                                            submission's single lookup (#2241); null looks it up here.
	 * @return array<string, mixed> ['allowed' => bool, 'message' => string, 'is_ticket' => bool]
	 */
	public static function check( array $form_config, string $val_cpf, string $val_ticket, int $form_id, ?string $val_password = null, ?\Closure $is_reprint = null ): array {
		$restrictions = \FreeFormCertificate\Core\ArrayValue::array( $form_config, 'restrictions' );

		// Clean CPF/RF (remove any mask).
		$clean_cpf = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $val_cpf );

		// ========================================.
		// 1. PASSWORD CHECK (if active)
		// ========================================.
		if ( ! empty( $restrictions['password'] ) && '1' === $restrictions['password'] ) {
			$password       = trim( $val_password ?? RequestInput::get_post_string( 'ffc_password' ) );
			$valid_password = isset( $form_config['validation_code'] ) && is_scalar( $form_config['validation_code'] ) ? (string) $form_config['validation_code'] : '';

			if ( empty( $password ) ) {
				return array(
					'allowed'   => false,
					'message'   => __( 'Password is required.', 'ffcertificate' ),
					'is_ticket' => false,
				);
			}

			// Constant-time, so the comparison does not time the secret (#1861).
			if ( '' === $valid_password || ! hash_equals( $valid_password, $password ) ) {
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
			$denied_raw  = \FreeFormCertificate\Core\ArrayValue::string( $form_config, 'denied_users_list' );
			$denied_list = array_filter( array_map( 'trim', explode( "\n", $denied_raw ) ) );

			// Clean masks from denylist before comparing.
			$denied_clean = array_map(
				function ( $d ) {
					return \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $d );
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
			$allowed_raw  = \FreeFormCertificate\Core\ArrayValue::string( $form_config, 'allowed_users_list' );
			$allowed_list = array_filter( array_map( 'trim', explode( "\n", $allowed_raw ) ) );

			// Clean masks from allowlist before comparing.
			$allowed_clean = array_map(
				function ( $a ) {
					return \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $a );
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
			$ticket = self::normalize_ticket( $val_ticket );

			if ( empty( $ticket ) ) {
				return array(
					'allowed'   => false,
					'message'   => __( 'Ticket code is required.', 'ffcertificate' ),
					'is_ticket' => false,
				);
			}

			$tickets_raw = \FreeFormCertificate\Core\ArrayValue::string( $form_config, 'generated_codes_list' );
			$tickets     = array_filter(
				array_map(
					function ( $t ) {
						return strtoupper( trim( $t ) );
					},
					explode( "\n", $tickets_raw )
				)
			);

			if ( ! in_array( $ticket, $tickets, true ) ) {
				if ( self::is_reprint_of_used_ticket( $form_config, $form_id, $ticket, $is_reprint ) ) {
					return self::reprint_pass();
				}
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
				if ( self::is_reprint_of_used_ticket( $form_config, $form_id, $ticket, $is_reprint ) ) {
					return self::reprint_pass();
				}
				return array(
					'allowed'   => false,
					'message'   => __( 'Invalid or already used ticket.', 'ffcertificate' ),
					'is_ticket' => false,
				);
			}

			// The ticket stays on the list until a submission is saved:
			// SubmissionPersister then removes it through consume_ticket(),
			// which re-reads the form's config, or gives the claim back through
			// release_ticket() when nothing was saved (#2341). Writing the
			// config read at the start of the request here would undo an
			// operator's edit made in between, and would burn the ticket of a
			// submission that then failed.
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
	 * Whether a ticket the list no longer holds belongs to a certificate this
	 * form already issued, so the request is a reprint (#1574).
	 *
	 * The ticket is the credential that issued the certificate, and a reprint
	 * creates nothing: the submission step finds the same row through
	 * {@see ReprintDetector} and returns it. That is the reprint the CPF/RF
	 * path already offers. A quiz form is excluded, because there a request
	 * that passes this gate is a new attempt, and letting a spent ticket buy
	 * one would undo its single use.
	 *
	 * @param array<string, mixed> $form_config Form configuration.
	 * @param int                  $form_id     Form ID.
	 * @param string               $ticket      Normalised ticket code.
	 * @param \Closure|null        $is_reprint  The submission's own lookup, when the caller has one.
	 * @return bool
	 */
	private static function is_reprint_of_used_ticket( array $form_config, int $form_id, string $ticket, ?\Closure $is_reprint = null ): bool {
		if ( '1' === \FreeFormCertificate\Core\ArrayValue::string( $form_config, 'quiz_enabled' ) ) {
			return false;
		}
		if ( null !== $is_reprint ) {
			return true === $is_reprint();
		}
		// No database layer (very early bootstrap): nothing can be looked up,
		// so the ticket keeps its plain "already used" answer.
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		$found = ReprintDetector::detect( $form_id, '', $ticket );
		return ! empty( $found['is_reprint'] );
	}

	/**
	 * The result that lets a reprint through without claiming the ticket again.
	 *
	 * @return array<string, mixed>
	 */
	private static function reprint_pass(): array {
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

		$option_name = self::claim_option_name( $form_id, $ticket );

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
	 * Remove a used ticket from the form's list, once its submission is saved.
	 *
	 * Reads the config as it is now, not as the request first saw it, so an
	 * operator's edit made in between survives (#2341). Lines are compared
	 * the way {@see check()} compares them, trimmed and upper-cased, so a
	 * ticket typed in another case still leaves the list.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $ticket  Ticket code as the participant typed it.
	 */
	public static function consume_ticket( int $form_id, string $ticket ): void {
		$ticket = self::normalize_ticket( $ticket );
		if ( '' === $ticket ) {
			return;
		}
		// `get_post_meta( …, true )` returns '' when the key does not exist,
		// and in PHP 8 assigning an offset on a string is fatal (#1087).
		$current_config = get_post_meta( $form_id, '_ffc_form_config', true );
		if ( ! is_array( $current_config ) ) {
			$current_config = array();
		}
		$current_raw_codes = isset( $current_config['generated_codes_list'] ) && is_string( $current_config['generated_codes_list'] ) ? $current_config['generated_codes_list'] : '';
		$current_list      = array_filter( array_map( 'trim', explode( "\n", $current_raw_codes ) ) );
		$updated_list      = array_filter(
			$current_list,
			static fn( string $line ): bool => self::normalize_ticket( $line ) !== $ticket
		);
		if ( count( $updated_list ) === count( $current_list ) ) {
			return;
		}
		$current_config['generated_codes_list'] = implode( "\n", $updated_list );
		update_post_meta( $form_id, '_ffc_form_config', $current_config );
	}

	/**
	 * Give back a ticket claimed by a request that saved nothing (#2341).
	 *
	 * The claim is taken before the submission is written, so a save that
	 * fails, or a quiz attempt refused before its row exists, would otherwise
	 * leave the participant holding a ticket that answers "already used".
	 *
	 * @param int    $form_id Form ID.
	 * @param string $ticket  Ticket code as the participant typed it.
	 */
	public static function release_ticket( int $form_id, string $ticket ): void {
		$ticket = self::normalize_ticket( $ticket );
		if ( '' === $ticket ) {
			return;
		}
		delete_option( self::claim_option_name( $form_id, $ticket ) );
	}

	/**
	 * Delete claim rows that no longer guard anything (#2341).
	 *
	 * A claim serialises two requests racing for one ticket; once the ticket
	 * has left the list, the list itself refuses it, so the row only grows
	 * `wp_options`. A claim is kept while its ticket is still listed (the
	 * removal failed, or an operator listed it again), because there it is
	 * still the one-use gate. Rows younger than a day are left alone, so no
	 * request still in flight loses its claim. Hooked on `ffc_daily_cleanup`.
	 *
	 * @param int $limit Most rows read in one run.
	 * @return int Rows deleted.
	 */
	public static function reap_stale_claims( int $limit = 500 ): int {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$like = $wpdb->esc_like( self::TICKET_CLAIM_OPTION_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance sweep over non-autoloaded claim rows the options API never caches.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_id ASC LIMIT %d",
				$like,
				time() - DAY_IN_SECONDS,
				max( 1, $limit )
			)
		);
		if ( ! is_array( $names ) || array() === $names ) {
			return 0;
		}

		$listed  = array();
		$deleted = 0;
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^' . preg_quote( self::TICKET_CLAIM_OPTION_PREFIX, '/' ) . '(\d+)_([0-9a-f]{64})$/', $name, $m ) ) {
				continue;
			}
			$form_id = (int) $m[1];
			if ( ! isset( $listed[ $form_id ] ) ) {
				$listed[ $form_id ] = self::listed_hashes( $form_id );
			}
			if ( isset( $listed[ $form_id ][ $m[2] ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the claim was written raw and never cached, so there is no cache entry to clear.
			if ( $wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Hashes of the tickets a form still lists, keyed for lookup.
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, true>
	 */
	private static function listed_hashes( int $form_id ): array {
		$config = get_post_meta( $form_id, '_ffc_form_config', true );
		$raw    = is_array( $config ) && isset( $config['generated_codes_list'] ) && is_string( $config['generated_codes_list'] ) ? $config['generated_codes_list'] : '';
		$hashes = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			$ticket = self::normalize_ticket( $line );
			if ( '' !== $ticket ) {
				$hashes[ hash( 'sha256', $ticket ) ] = true;
			}
		}
		return $hashes;
	}

	/**
	 * A ticket as the list and the claim compare it: trimmed, upper-cased.
	 *
	 * @param string $ticket Ticket code.
	 * @return string
	 */
	private static function normalize_ticket( string $ticket ): string {
		return strtoupper( trim( $ticket ) );
	}

	/**
	 * The `wp_options` row that claims a ticket.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $ticket  Normalised ticket code.
	 * @return string
	 */
	private static function claim_option_name( int $form_id, string $ticket ): string {
		return self::TICKET_CLAIM_OPTION_PREFIX . $form_id . '_' . hash( 'sha256', $ticket );
	}
}
