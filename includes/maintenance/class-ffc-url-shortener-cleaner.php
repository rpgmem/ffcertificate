<?php
/**
 * UrlShortenerCleaner
 *
 * Maintenance tool that removes obsolete short URLs from the `ffc_short_urls`
 * table. Three independent, individually-toggleable criteria are supported:
 *
 *   - `orphaned`:      the row's `post_id` points to a post that no longer exists.
 *   - `never_clicked`: `click_count = 0` and the row is older than the grace window.
 *   - `trashed`:       the row's `status` is `'trashed'`.
 *
 * Honours `dry_run`: a dry run scans and reports without deleting anything.
 * Deletion is a hard delete via the repository (which clears its own cache).
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.7.x
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\UrlShortener\UrlShortenerRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cleanup tool for obsolete short URLs.
 */
class UrlShortenerCleaner implements MaintenanceToolInterface {

	/**
	 * Default grace window (days) for the never_clicked criterion.
	 */
	const DEFAULT_DAYS = 90;

	/**
	 * Max rows detailed in the report; extra rows are counted but not listed.
	 */
	const REPORT_LIMIT = 50;

	/**
	 * Recognised criteria keys.
	 *
	 * @var array<int, string>
	 */
	const VALID_CRITERIA = array( 'orphaned', 'never_clicked', 'trashed' );

	/**
	 * Data access layer. Lazily created on first use so the tool can be
	 * constructed (e.g. in the registry factory) without a database connection.
	 *
	 * @var UrlShortenerRepository|null
	 */
	private ?UrlShortenerRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param UrlShortenerRepository|null $repository Injected for tests; lazily created on first use when null.
	 */
	public function __construct( ?UrlShortenerRepository $repository = null ) {
		$this->repository = $repository;
	}

	/**
	 * Resolve the repository, creating a default one on first use.
	 *
	 * @return UrlShortenerRepository
	 */
	private function repository(): UrlShortenerRepository {
		if ( null === $this->repository ) {
			$this->repository = new UrlShortenerRepository();
		}
		return $this->repository;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'url_shortener_cleanup';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Short URL Cleanup', 'ffcertificate' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Delete obsolete short URLs: those whose target post no longer exists, those created more than the grace window ago and never clicked, and those already in the trash.', 'ffcertificate' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_actionable(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array{criteria:array<string,bool>, days:int, dry_run:bool}
	 */
	public function get_default_options(): array {
		return array(
			'criteria' => array(
				'orphaned'      => true,
				'never_clicked' => false,
				'trashed'       => true,
			),
			'days'     => self::DEFAULT_DAYS,
			'dry_run'  => true,
		);
	}

	/**
	 * Run the cleanup (or a dry run) and return a structured report.
	 *
	 * @param array{criteria?:array<string,mixed>, days?:int, dry_run?:bool} $options Run options.
	 * @return array{
	 *     dry_run: bool,
	 *     days: int,
	 *     criteria: array<string,bool>,
	 *     candidates: int,
	 *     deleted: int,
	 *     by_reason: array<string, int>,
	 *     truncated: bool,
	 *     affected: array<int, array{id:int, short_code:string, title:string, target_url:string, reasons:array<int,string>}>
	 * }
	 */
	public function run( array $options ): array {
		$dry_run  = ! empty( $options['dry_run'] );
		$days     = isset( $options['days'] ) ? max( 0, (int) $options['days'] ) : self::DEFAULT_DAYS;
		$criteria = $this->normalize_criteria( $options['criteria'] ?? array() );

		$report = array(
			'dry_run'    => $dry_run,
			'days'       => $days,
			'criteria'   => $criteria,
			'candidates' => 0,
			'deleted'    => 0,
			'by_reason'  => array(
				'orphaned'      => 0,
				'never_clicked' => 0,
				'trashed'       => 0,
			),
			'truncated'  => false,
			'affected'   => array(),
		);

		// No criterion selected → nothing to scan.
		if ( ! in_array( true, $criteria, true ) ) {
			return $report;
		}

		$rows                 = $this->repository()->find_cleanup_candidates( $criteria, $days );
		$report['candidates'] = count( $rows );

		foreach ( $rows as $row ) {
			$reasons = $this->reasons_for_row( $row, $criteria );
			if ( empty( $reasons ) ) {
				continue;
			}

			foreach ( $reasons as $reason ) {
				++$report['by_reason'][ $reason ];
			}

			if ( ! $dry_run && $this->repository()->delete( (int) $row['id'] ) ) {
				++$report['deleted'];
			}

			if ( count( $report['affected'] ) < self::REPORT_LIMIT ) {
				$report['affected'][] = array(
					'id'         => (int) $row['id'],
					'short_code' => (string) ( $row['short_code'] ?? '' ),
					'title'      => (string) ( $row['title'] ?? '' ),
					'target_url' => (string) ( $row['target_url'] ?? '' ),
					'reasons'    => $reasons,
				);
			}
		}

		$report['truncated'] = $report['candidates'] > self::REPORT_LIMIT;

		return $report;
	}

	/**
	 * Coerce raw criteria input into a complete bool map over VALID_CRITERIA.
	 *
	 * @param mixed $raw Raw `criteria` option.
	 * @return array{orphaned:bool, never_clicked:bool, trashed:bool}
	 */
	private function normalize_criteria( $raw ): array {
		$out = array(
			'orphaned'      => false,
			'never_clicked' => false,
			'trashed'       => false,
		);
		if ( is_array( $raw ) ) {
			foreach ( self::VALID_CRITERIA as $key ) {
				$out[ $key ] = ! empty( $raw[ $key ] );
			}
		}
		return $out;
	}

	/**
	 * Determine which enabled criteria a row satisfies, from the repository's
	 * per-row flags.
	 *
	 * @param array<string, mixed>                                   $row      Candidate row.
	 * @param array{orphaned:bool, never_clicked:bool, trashed:bool} $criteria Enabled criteria.
	 * @return array<int, string> Subset of VALID_CRITERIA.
	 */
	private function reasons_for_row( array $row, array $criteria ): array {
		$reasons = array();
		if ( ! empty( $criteria['orphaned'] ) && ! empty( $row['is_orphaned'] ) ) {
			$reasons[] = 'orphaned';
		}
		if ( ! empty( $criteria['never_clicked'] ) && ! empty( $row['is_never_clicked'] ) ) {
			$reasons[] = 'never_clicked';
		}
		if ( ! empty( $criteria['trashed'] ) && ! empty( $row['is_trashed'] ) ) {
			$reasons[] = 'trashed';
		}
		return $reasons;
	}
}
