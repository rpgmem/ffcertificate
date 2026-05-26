<?php
/**
 * MaintenanceToolInterface
 *
 * Contract for a pluggable admin maintenance/cleanup tool surfaced on the
 * Settings → Data Migrations tab. Each tool advertises an id, human-readable
 * title/description, whether it mutates data (`is_actionable()`), its default
 * option set, and a single `run( array $options )` entry point that returns a
 * structured report.
 *
 * Two tool shapes are supported:
 *   - Actionable tools (`is_actionable() === true`): support a `dry_run`
 *     option. The UI runs a dry-run preview first, then an apply pass.
 *   - Report-only tools (`is_actionable() === false`): only scan and report;
 *     the UI shows no apply button and `dry_run` is irrelevant.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.7.x
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pluggable maintenance tool contract.
 */
interface MaintenanceToolInterface {

	/**
	 * Stable machine id (snake_case). Used as the registry key, the form
	 * action discriminator and the transient namespace.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human-readable, translated title for the tool's card.
	 *
	 * @return string
	 */
	public function get_title(): string;

	/**
	 * Human-readable, translated description shown under the title.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Whether the tool mutates data (true) or only scans/reports (false).
	 *
	 * Actionable tools support a `dry_run` option and a two-step
	 * preview → apply flow; report-only tools just scan.
	 *
	 * @return bool
	 */
	public function is_actionable(): bool;

	/**
	 * Default option values for the tool (merged over by the caller).
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_options(): array;

	/**
	 * Run the tool and return a structured report.
	 *
	 * Implementations MUST honour `$options['dry_run']` when
	 * `is_actionable()` is true: a truthy value means "do not mutate".
	 *
	 * @param array<string, mixed> $options Run options (tool-specific, plus
	 *                                       the common `dry_run` flag).
	 * @return array<string, mixed> Structured report. Actionable tools should
	 *                              include a `dry_run` boolean.
	 */
	public function run( array $options ): array;
}
