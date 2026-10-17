<?php
/**
 * AudienceRecordAutosave
 *
 * Registers the audience calendar and the audience with the record autosave
 * (#1757), so their toggles save the moment they flip.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\RecordAutosave;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The calendar and audience entities of the record autosave.
 */
class AudienceRecordAutosave {

	/**
	 * Calendar columns saved as `1` / `0`.
	 *
	 * @var list<string>
	 */
	public const SCHEDULE_FLAGS = array(
		'notify_on_booking',
		'notify_on_cancellation',
		'include_ics',
		'notify_admin_on_booking',
		'notify_admin_on_cancellation',
		'show_event_list',
		'is_isolated',
		'admin_bypass',
	);

	/**
	 * Register both entities.
	 *
	 * @return void
	 */
	public static function register(): void {
		$schedule_fields = array_fill_keys( self::SCHEDULE_FLAGS, array( 'type' => 'bool' ) );

		$schedule_fields['status']     = array(
			'type' => 'map',
			'map'  => array(
				1 => 'active',
				0 => 'inactive',
			),
		);
		$schedule_fields['visibility'] = array(
			'type' => 'map',
			'map'  => array(
				1 => 'public',
				0 => 'private',
			),
		);

		RecordAutosave::register(
			'audience_schedule',
			array(
				'cap'    => 'ffc_manage_audiences',
				'fields' => $schedule_fields,
				'exists' => static fn( int $id ): bool => null !== AudienceScheduleRepository::get_by_id( $id ),
				'save'   => array( self::class, 'save_schedule' ),
			)
		);

		RecordAutosave::register(
			'audience',
			array(
				'cap'    => 'ffc_manage_audiences',
				'fields' => array(
					'status'          => array(
						'type' => 'map',
						'map'  => array(
							1 => 'active',
							0 => 'inactive',
						),
					),
					'allow_self_join' => array( 'type' => 'bool' ),
				),
				'exists' => static fn( int $id ): bool => null !== AudienceReader::get_by_id( $id ),
				'save'   => array( self::class, 'save_audience' ),
			)
		);
	}

	/**
	 * Save one calendar column.
	 *
	 * @param int    $id    Calendar id.
	 * @param string $key   Column.
	 * @param mixed  $value Typed value.
	 * @return true|\WP_Error
	 */
	public static function save_schedule( int $id, string $key, $value ) {
		if ( ! AudienceScheduleRepository::update( $id, array( $key => $value ) ) ) {
			return new \WP_Error( 'ffc_record_not_saved', __( 'The calendar could not be saved.', 'ffcertificate' ) );
		}
		return true;
	}

	/**
	 * Save one audience column. Self-join is set on a top-level audience
	 * only, and cascades to its children, as the Save button does.
	 *
	 * @param int    $id    Audience id.
	 * @param string $key   Column.
	 * @param mixed  $value Typed value.
	 * @return true|\WP_Error
	 */
	public static function save_audience( int $id, string $key, $value ) {
		if ( 'allow_self_join' === $key ) {
			$audience = AudienceReader::get_by_id( $id );
			if ( null === $audience || ! empty( $audience->parent_id ) ) {
				return new \WP_Error( 'ffc_record_not_saved', __( 'Self-join is set on the parent audience.', 'ffcertificate' ) );
			}
		}

		if ( ! AudienceWriter::update( $id, array( $key => $value ) ) ) {
			return new \WP_Error( 'ffc_record_not_saved', __( 'The audience could not be saved.', 'ffcertificate' ) );
		}

		if ( 'allow_self_join' === $key ) {
			AudienceWriter::cascade_self_join( $id, (int) $value );
		}
		return true;
	}
}
