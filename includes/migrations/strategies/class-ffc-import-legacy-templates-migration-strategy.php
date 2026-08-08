<?php
/**
 * ImportLegacyTemplatesMigrationStrategy
 *
 * Imports the certificate layouts a site left in the legacy `html/` drop-folder
 * into the database-backed template pool (issue #865). Before the pool, admins
 * dropped `*.html` files whose basename contained "certificate" into
 * `wp-content/plugins/ffcertificate/html/` and the editor discovered them by
 * glob (#443). That folder is being retired: this migration lifts each such
 * file into the pool as a user template ({@see \FreeFormCertificate\Admin\CertTemplateWriter})
 * so it survives plugin updates and gains the management UI, then the glob
 * fallback can be dropped.
 *
 * Non-destructive and idempotent: the three plugin-shipped defaults
 * (`default_certificate_1|2|3.html`, already seeded from
 * `templates/certificate-defaults/`) are skipped, and every imported basename is
 * recorded in the `ffc_imported_legacy_templates` option so re-running never
 * duplicates. A file that fails to import (unreadable, or `wp_insert_post`
 * rejects it) is recorded as handled too, so the batch runner always terminates
 * — the failure is surfaced in the result's `errors`.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since   6.18.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Admin\CertTemplateWriter;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strategy implementation for importing legacy `html/` certificate templates.
 */
class ImportLegacyTemplatesMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * Option key holding the list of basenames already imported (idempotency guard).
	 */
	private const IMPORTED_OPTION = 'ffc_imported_legacy_templates';

	/**
	 * Plugin-shipped defaults already present in the pool (seeded from
	 * `templates/certificate-defaults/`). Never re-imported from `html/`.
	 *
	 * @var array<int, string>
	 */
	private const SHIPPED_DEFAULTS = array(
		'default_certificate_1.html',
		'default_certificate_2.html',
		'default_certificate_3.html',
	);

	/**
	 * Absolute path (with trailing slash) of the legacy drop-folder to scan.
	 *
	 * @var string
	 */
	private string $legacy_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $legacy_dir Absolute path to the legacy `html/` folder
	 *                                (trailing slash optional). Defaults to the
	 *                                plugin's `html/` directory. Injectable for tests.
	 */
	public function __construct( ?string $legacy_dir = null ) {
		if ( null === $legacy_dir ) {
			$legacy_dir = FFC_PLUGIN_DIR . 'html/';
		}
		$this->legacy_dir = rtrim( $legacy_dir, '/' ) . '/';
	}

	/**
	 * Calculate import status.
	 *
	 * Total = importable legacy files currently on disk; migrated = those already
	 * recorded as imported; pending = the rest. An install with no dropped files
	 * reports 100% complete (nothing to import).
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed> Status information.
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		$importable = array_keys( $this->discover_importable() );
		$imported   = $this->imported_basenames();

		$total    = count( $importable );
		$migrated = count( array_intersect( $importable, $imported ) );
		$pending  = $total - $migrated;
		$percent  = ( $total > 0 ) ? ( $migrated / $total ) * 100 : 100;

		return array(
			'total'       => $total,
			'migrated'    => $migrated,
			'pending'     => $pending,
			'percent'     => round( $percent, 2 ),
			'is_complete' => ( 0 === $pending ),
		);
	}

	/**
	 * Import a batch of not-yet-imported legacy files into the pool.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number Batch number (unused — imported files
	 *                             leave the pending set, so each call starts fresh).
	 * @return array<string, mixed> Execution result.
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		$batch_size = isset( $migration_config['batch_size'] ) ? max( 1, intval( $migration_config['batch_size'] ) ) : 20;

		$importable = $this->discover_importable();
		$imported   = $this->imported_basenames();

		$processed = 0;
		$errors    = array();
		$handled   = array();

		foreach ( $importable as $basename => $path ) {
			if ( in_array( $basename, $imported, true ) ) {
				continue;
			}
			if ( count( $handled ) >= $batch_size ) {
				break;
			}

			// Record the basename as handled regardless of outcome: a persistent
			// read / insert failure must not re-loop forever (the batch runner
			// terminates on is_complete). The failure is reported in $errors.
			$handled[] = $basename;

			$html = $this->read_file( $path );
			if ( '' === $html ) {
				/* translators: %s: template filename */
				$errors[] = sprintf( __( 'Could not read legacy template file: %s', 'ffcertificate' ), $basename );
				continue;
			}

			$id = CertTemplateWriter::create( $this->title_from_basename( $basename ), $html, true );
			if ( $id > 0 ) {
				++$processed;
			} else {
				/* translators: %s: template filename */
				$errors[] = sprintf( __( 'Failed to import legacy template: %s', 'ffcertificate' ), $basename );
			}
		}

		if ( ! empty( $handled ) ) {
			$merged = array_values( array_unique( array_merge( $imported, $handled ) ) );
			update_option( self::IMPORTED_OPTION, $merged );
		}

		$status   = $this->calculate_status( $migration_key, $migration_config );
		$has_more = $status['pending'] > 0;

		return array(
			'success'   => count( $errors ) === 0,
			'processed' => $processed,
			'has_more'  => $has_more,
			/* translators: %d: number of templates imported */
			'message'   => sprintf( __( 'Imported %d legacy certificate template(s)', 'ffcertificate' ), $processed ),
			'errors'    => $errors,
		);
	}

	/**
	 * Check whether the migration can run.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		if ( ! class_exists( '\\FreeFormCertificate\\Admin\\CertTemplateWriter' ) ) {
			return new WP_Error(
				'writer_class_missing',
				__( 'Template pool writer not found. Required to import legacy templates.', 'ffcertificate' )
			);
		}
		return true;
	}

	/**
	 * Human-readable strategy name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Import Legacy Certificate Templates', 'ffcertificate' );
	}

	/**
	 * Discover the importable legacy files: `<dir>/*.html` whose basename contains
	 * "certificate" (the #443 convention for a valid certificate layout), minus the
	 * plugin-shipped defaults. Keyed by basename → absolute path, sorted by basename.
	 *
	 * @return array<string, string>
	 */
	private function discover_importable(): array {
		$paths = glob( $this->legacy_dir . '*.html' );
		if ( ! $paths ) {
			return array();
		}

		$out = array();
		foreach ( $paths as $path ) {
			$name = basename( $path );
			if ( in_array( $name, self::SHIPPED_DEFAULTS, true ) ) {
				continue;
			}
			if ( false === stripos( $name, 'certificate' ) ) {
				continue;
			}
			$out[ $name ] = $path;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Basenames already imported (or handled), from the idempotency option.
	 *
	 * @return array<int, string>
	 */
	private function imported_basenames(): array {
		$stored = get_option( self::IMPORTED_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values( array_filter( $stored, 'is_string' ) );
	}

	/**
	 * Derive a human title from a filename: strip `.html`, `_` → space, title-case.
	 * Mirrors the legacy glob's label derivation so imported names match what the
	 * editor "Load" list showed before the pool. `my_cert_certificate.html`
	 * → "My Cert Certificate".
	 *
	 * @param string $basename File basename.
	 * @return string
	 */
	private function title_from_basename( string $basename ): string {
		$stem = (string) preg_replace( '/\.html$/i', '', $basename );
		return ucwords( str_replace( '_', ' ', $stem ) );
	}

	/**
	 * Read a legacy template file's contents.
	 *
	 * @param string $path Absolute file path.
	 * @return string Contents, or an empty string when unreadable.
	 */
	private function read_file( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled/site-local plugin asset, not a remote/user URL.
		$html = file_get_contents( $path );
		return is_string( $html ) ? $html : '';
	}
}
