<?php
/**
 * Notice Repository
 *
 * Public façade for the `ffc_recruitment_notice` table — the edital lifecycle
 * (draft → preliminary → active → closed).
 *
 * Since the #563 backlog read/write split (B3) this class is a thin façade:
 * reads live in {@see RecruitmentNoticeReader}, writes in
 * {@see RecruitmentNoticeWriter}. It is kept as the public entry point so the
 * existing call sites and the public constant below need no change.
 *
 * State transitions are NOT performed here: the writer exposes raw status
 * setters used by the {@see NoticeStateMachine} (sprint 5). This separation
 * keeps the repository as a thin CRUD primitive and the state machine as the
 * single source of truth for transition validity, reason gating, and the
 * `was_reopened` flag flip.
 *
 * The `code` column is normalized to UPPERCASE on insert/update; lookups via
 * {@see self::get_by_code()} re-uppercase the input for consistency.
 *
 * Tech-debt (#563 B3): migrate call sites to depend on RecruitmentNoticeReader /
 * RecruitmentNoticeWriter directly, then retire this delegating façade.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public façade over {@see RecruitmentNoticeReader} + {@see RecruitmentNoticeWriter}.
 *
 * `public_columns_config` is exposed as the raw JSON string; decoding into an
 * associative array is the caller's responsibility (typically the renderer or
 * the REST controller, which validates the shape against the schema in
 * §3.2 / §8.2 of the implementation plan).
 *
 * @phpstan-type NoticeRow \stdClass&object{id: numeric-string, code: string, name: string, status: string, opened_at: string|null, closed_at: string|null, was_reopened: numeric-string, public_columns_config: string, created_at: string, updated_at: string}
 */
class RecruitmentNoticeRepository {

	/**
	 * Default `public_columns_config` JSON applied to new notices.
	 *
	 * Mirrors §3.2 of the implementation plan. `rank` and `name` are the
	 * mandatory columns and cannot be toggled off via PATCH (validation
	 * lives in the REST controller, not here).
	 *
	 * @var string
	 */
	public const DEFAULT_PUBLIC_COLUMNS_CONFIG = '{"rank":true,"name":true,"adjutancy":true,"status":true,"pcd_badge":true,"date_to_assume":true,"time_to_assume":true,"score":false,"time_points":false,"hab_emebs":false,"cpf_masked":false,"rf_masked":false,"email_masked":false,"preview_reason":false}';

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return RecruitmentNoticeReader::get_table_name();
	}

	// ─────────────────────────────────────────────.
	// Reads — delegate to RecruitmentNoticeReader.
	// ─────────────────────────────────────────────.

	/**
	 * Get a notice by ID.
	 *
	 * @param int $id Notice ID.
	 * @return NoticeRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		return RecruitmentNoticeReader::get_by_id( $id );
	}

	/**
	 * Get a notice by `code` (case-insensitive — input is uppercased before lookup).
	 *
	 * @param string $code Notice code (any case; normalized internally).
	 * @return NoticeRow|null
	 */
	public static function get_by_code( string $code ): ?object {
		return RecruitmentNoticeReader::get_by_code( $code );
	}

	/**
	 * List notices, optionally filtered by status.
	 *
	 * @param string|null $status One of {draft, preliminary, active, closed} or null for all.
	 * @return list<NoticeRow>
	 */
	public static function get_all( ?string $status = null ): array {
		return RecruitmentNoticeReader::get_all( $status );
	}

	// ─────────────────────────────────────────────.
	// Writes — delegate to RecruitmentNoticeWriter.
	// ─────────────────────────────────────────────.

	/**
	 * Create a new notice in `draft` status.
	 *
	 * @param string $code Notice code (will be uppercased).
	 * @param string $name Human-readable name.
	 * @param string $public_columns_config Optional JSON config; defaults to schema's defaults.
	 * @return int|false New notice ID or false on failure (e.g. duplicate code).
	 */
	public static function create( string $code, string $name, string $public_columns_config = '' ) {
		return RecruitmentNoticeWriter::create( $code, $name, $public_columns_config );
	}

	/**
	 * Update mutable notice metadata.
	 *
	 * @param int                  $id   Notice ID.
	 * @param array<string, mixed> $data Update payload.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		return RecruitmentNoticeWriter::update( $id, $data );
	}

	/**
	 * Set the notice status atomically, gated by an expected current status.
	 *
	 * @param int    $id Notice ID.
	 * @param string $expected_current Current status the caller observed.
	 * @param string $new_status Target status.
	 * @return int Number of rows affected (1 on success, 0 if race lost).
	 */
	public static function set_status( int $id, string $expected_current, string $new_status ): int {
		return RecruitmentNoticeWriter::set_status( $id, $expected_current, $new_status );
	}

	/**
	 * Stamp `opened_at` on the first transition to `active`.
	 *
	 * @param int $id Notice ID.
	 * @return int Number of rows affected.
	 */
	public static function mark_opened( int $id ): int {
		return RecruitmentNoticeWriter::mark_opened( $id );
	}

	/**
	 * Stamp `closed_at` on every transition to `closed` (overwrites).
	 *
	 * @param int $id Notice ID.
	 * @return int Number of rows affected.
	 */
	public static function mark_closed( int $id ): int {
		return RecruitmentNoticeWriter::mark_closed( $id );
	}

	/**
	 * Flip `was_reopened` to 1 on the first `closed → active` transition.
	 *
	 * @param int $id Notice ID.
	 * @return int Number of rows affected (1 on first reopen, 0 otherwise).
	 */
	public static function mark_reopened( int $id ): int {
		return RecruitmentNoticeWriter::mark_reopened( $id );
	}

	/**
	 * Delete a notice unconditionally.
	 *
	 * @param int $id Notice ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		return RecruitmentNoticeWriter::delete( $id );
	}
}
