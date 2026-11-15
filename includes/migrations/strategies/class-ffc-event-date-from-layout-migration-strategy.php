<?php
/**
 * Event date from layout migration strategy.
 *
 * Moves the event date typed into a form's certificate layout onto the form's
 * event-date field (#2580).
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\EventDate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typed event date → `class_date_start` + `{{schedule:date:format=…}}`.
 *
 * Before #2579 a form had no event date, so every layout typed it (all 240
 * production forms measured). For each form whose layout holds exactly one
 * `d/m/Y` date and whose event date is still empty, this card:
 *
 * 1. sets the event start date from the typed date — never from the opening
 *    window, which differed on 15 of those forms;
 * 2. replaces the typed date with `{{schedule:date:format=<its own format>}}`,
 *    the format read from how it was typed (`19/12/2025` → `d/m/Y`,
 *    `9/3/2026` → `j/n/Y`), so the printed text does not depend on the PDF
 *    date format set today;
 * 3. checks, before writing anything, that resolving the new token gives back
 *    the original layout byte for byte. A form that would print anything else
 *    is left untouched and listed.
 *
 * A form with several typed dates, or an event date already set, is left alone
 * and listed with the reason. Examined forms are kept in a register, so a
 * skipped form does not count as pending forever and a batch counts the forms
 * it CHANGED (#1378): termination does not depend on the repair.
 */
class EventDateFromLayoutMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * The register of examined forms. Listed in `uninstall.php`.
	 */
	public const STATE_OPTION = 'ffc_event_date_migration_state';

	/**
	 * How many forms one batch examines.
	 */
	private const BATCH_SIZE = 25;

	/**
	 * How many pending forms the card previews.
	 */
	private const PREVIEW_LIMIT = 20;

	/**
	 * A typed `d/m/Y` date, not part of a longer number.
	 */
	private const DATE_PATTERN = '#(?<![\d/])(\d{1,2})/(\d{1,2})/(\d{4})(?![\d/])#';

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		$state   = $this->state();
		$pending = $this->pending_ids( $state );
		$done    = count( $state['done'] ) + count( $state['skipped'] );
		$total   = $done + count( $pending );

		$failures = array();
		foreach ( $state['skipped'] as $form_id => $reason ) {
			$failures[] = sprintf( '%s — %s', $this->form_label( (int) $form_id ), $this->reason_text( (string) $reason ) );
		}

		$preview = array();
		foreach ( array_slice( $pending, 0, self::PREVIEW_LIMIT ) as $form_id ) {
			$preview[] = $this->preview_line( $form_id );
		}

		return array(
			'total'       => $total,
			'migrated'    => $done,
			'pending'     => count( $pending ),
			'percent'     => $total > 0 ? round( ( $done / $total ) * 100, 2 ) : 100,
			'is_complete' => array() === $pending,
			'failures'    => array_slice( $failures, 0, 50 ),
			'failed'      => count( $failures ),
			'preview'     => $preview,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number     Batch number.
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		$state   = $this->state();
		$pending = array_slice( $this->pending_ids( $state ), 0, self::BATCH_SIZE );
		$changed = 0;

		foreach ( $pending as $form_id ) {
			$outcome = $this->migrate_form( $form_id );
			if ( 'done' === $outcome ) {
				$state['done'][] = $form_id;
				++$changed;
			} else {
				$state['skipped'][ $form_id ] = $outcome;
			}
		}
		update_option( self::STATE_OPTION, $state, false );

		return array(
			'success'   => true,
			'processed' => $changed,
			'message'   => sprintf(
				/* translators: 1: forms examined, 2: forms whose event date was set */
				__( 'Examined %1$d forms and moved the typed event date of %2$d; the rest are listed with the reason.', 'ffcertificate' ),
				count( $pending ),
				$changed
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|\WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'event_date_from_layout';
	}

	/**
	 * What one form's typed date becomes, without writing it.
	 *
	 * @param string $layout Layout HTML.
	 * @return array{date: string, token: string, layout: string}|string The plan, or the reason to skip.
	 */
	public static function plan( string $layout ) {
		$count = (int) preg_match_all( self::DATE_PATTERN, $layout, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		if ( 0 === $count ) {
			return 'no_date';
		}
		if ( $count > 1 ) {
			return 'several_dates';
		}
		list( $literal, $offset ) = $m[0][0];
		$day                      = $m[0][1][0];
		$month                    = $m[0][2][0];
		$year                     = $m[0][3][0];
		$iso                      = sprintf( '%04d-%02d-%02d', (int) $year, (int) $month, (int) $day );
		if ( '' === EventDate::valid( $iso ) ) {
			return 'not_a_date';
		}

		$format = ( 2 === strlen( $day ) ? 'd' : 'j' ) . '/' . ( 2 === strlen( $month ) ? 'm' : 'n' ) . '/Y';
		$token  = '{{schedule:date:format=' . $format . '}}';
		$new    = substr_replace( $layout, $token, (int) $offset, strlen( $literal ) );

		// The certificate must print exactly what it printed before.
		if ( EventDate::replace_tokens( $new, $iso, '' ) !== $layout ) {
			return 'output_differs';
		}
		return array(
			'date'   => $iso,
			'token'  => $token,
			'layout' => $new,
		);
	}

	/**
	 * Migrate one form.
	 *
	 * @param int $form_id Form post id.
	 * @return string 'done', or the reason it was left alone.
	 */
	private function migrate_form( int $form_id ): string {
		$geofence = get_post_meta( $form_id, '_ffc_geofence_config', true );
		$geofence = is_array( $geofence ) ? $geofence : array();
		if ( '' !== EventDate::range_from_config( $geofence )[0] ) {
			return 'already_set';
		}

		$config = get_post_meta( $form_id, '_ffc_form_config', true );
		$config = is_array( $config ) ? $config : array();
		$plan   = self::plan( is_string( $config['pdf_layout'] ?? null ) ? $config['pdf_layout'] : '' );
		if ( ! is_array( $plan ) ) {
			return $plan;
		}

		$geofence['class_date_start'] = $plan['date'];
		$geofence['class_date_end']   = '';
		$config['pdf_layout']         = $plan['layout'];
		update_post_meta( $form_id, '_ffc_geofence_config', $geofence );
		update_post_meta( $form_id, '_ffc_form_config', $config );
		return 'done';
	}

	/**
	 * Forms not yet examined whose layout types a date.
	 *
	 * @param array{done: array<int, int>, skipped: array<int, string>} $state Register.
	 * @return array<int, int>
	 */
	private function pending_ids( array $state ): array {
		$seen    = array_flip( array_merge( $state['done'], array_map( 'intval', array_keys( $state['skipped'] ) ) ) );
		$pending = array();
		foreach ( $this->form_ids() as $form_id ) {
			if ( isset( $seen[ $form_id ] ) ) {
				continue;
			}
			$config = get_post_meta( $form_id, '_ffc_form_config', true );
			$layout = is_array( $config ) && is_string( $config['pdf_layout'] ?? null ) ? $config['pdf_layout'] : '';
			if ( 1 === preg_match( self::DATE_PATTERN, $layout ) ) {
				$pending[] = $form_id;
			}
		}
		return $pending;
	}

	/**
	 * Every form that is not in the bin.
	 *
	 * @return array<int, int>
	 */
	private function form_ids(): array {
		$ids = get_posts(
			array(
				'post_type'        => 'ffc_form',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * The register, normalised.
	 *
	 * @return array{done: array<int, int>, skipped: array<int, string>}
	 */
	private function state(): array {
		$raw     = get_option( self::STATE_OPTION, array() );
		$raw     = is_array( $raw ) ? $raw : array();
		$done    = is_array( $raw['done'] ?? null ) ? array_map( 'intval', $raw['done'] ) : array();
		$skipped = array();
		foreach ( is_array( $raw['skipped'] ?? null ) ? $raw['skipped'] : array() as $id => $reason ) {
			$skipped[ (int) $id ] = (string) $reason;
		}
		return array(
			'done'    => array_values( $done ),
			'skipped' => $skipped,
		);
	}

	/**
	 * One preview line: what the next run sets.
	 *
	 * @param int $form_id Form post id.
	 */
	private function preview_line( int $form_id ): string {
		$config   = get_post_meta( $form_id, '_ffc_form_config', true );
		$plan     = self::plan( is_array( $config ) && is_string( $config['pdf_layout'] ?? null ) ? $config['pdf_layout'] : '' );
		$geofence = get_post_meta( $form_id, '_ffc_geofence_config', true );
		$opening  = is_array( $geofence ) ? EventDate::valid( $geofence['date_start'] ?? '' ) : '';
		if ( ! is_array( $plan ) ) {
			return sprintf( '%s — %s', $this->form_label( $form_id ), $this->reason_text( $plan ) );
		}
		return sprintf(
			/* translators: 1: form title and id, 2: event date, 3: opening date or a dash */
			__( '%1$s — event date %2$s (opens %3$s)', 'ffcertificate' ),
			$this->form_label( $form_id ),
			EventDate::format_one( $plan['date'] ),
			'' !== $opening ? EventDate::format_one( $opening ) : '—'
		);
	}

	/**
	 * A form's title with its id.
	 *
	 * @param int $form_id Form post id.
	 */
	private function form_label( int $form_id ): string {
		return sprintf( '%s (#%d)', get_the_title( $form_id ), $form_id );
	}

	/**
	 * Why a form was left alone, for the card.
	 *
	 * @param string $reason Reason code.
	 */
	private function reason_text( string $reason ): string {
		switch ( $reason ) {
			case 'several_dates':
				return __( 'the layout types more than one date; set the event date by hand', 'ffcertificate' );
			case 'already_set':
				return __( 'the form already has an event date', 'ffcertificate' );
			case 'output_differs':
				return __( 'the certificate would print differently; left as it is', 'ffcertificate' );
			case 'not_a_date':
				return __( 'the typed date is not a real date', 'ffcertificate' );
			default:
				return __( 'the layout types no date', 'ffcertificate' );
		}
	}
}
