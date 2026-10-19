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
					'status'             => array(
						'type' => 'map',
						'map'  => array(
							1 => 'active',
							0 => 'inactive',
						),
					),
					'allow_self_join'    => array( 'type' => 'bool' ),
					'self_join_scope'    => array(
						'type'    => 'enum',
						'options' => AudienceSelfJoinScope::values(),
					),
					'members_only'       => array( 'type' => 'bool' ),
					'require_membership' => array( 'type' => 'bool' ),
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
	 * Save one audience field. Self-join is set on a top-level audience only
	 * (#1901): `allow_self_join` is the single switch of an audience without
	 * children, and `self_join_scope` the four-option choice of one with
	 * children, which writes the audience's own flag and every child's and
	 * grandchild's.
	 *
	 * @param int    $id    Audience id.
	 * @param string $key   Column, or `self_join_scope`.
	 * @param mixed  $value Typed value.
	 * @return true|\WP_Error
	 */
	public static function save_audience( int $id, string $key, $value ) {
		if ( 'allow_self_join' === $key || 'self_join_scope' === $key ) {
			$audience = AudienceReader::get_by_id( $id );
			if ( null === $audience || ! empty( $audience->parent_id ) ) {
				return new \WP_Error( 'ffc_record_not_saved', __( 'Self-join is set on the parent audience.', 'ffcertificate' ) );
			}
		}

		// The tree-access pair (#1918) is set on the top-level audience, and
		// "Keep at least one group" only exists under "Visible to members only".
		if ( 'members_only' === $key || 'require_membership' === $key ) {
			$audience = AudienceReader::get_by_id( $id );
			if ( null === $audience || ! empty( $audience->parent_id ) ) {
				return new \WP_Error( 'ffc_record_not_saved', __( 'This setting is set on the top-level audience.', 'ffcertificate' ) );
			}
			$members_only = 'members_only' === $key ? (bool) $value : ! empty( $audience->members_only );
			$require      = 'require_membership' === $key ? (bool) $value : ! empty( $audience->require_membership );
			if ( 'require_membership' === $key && $require && ! $members_only ) {
				return new \WP_Error( 'ffc_record_not_saved', __( 'Turn on "Visible to members only" first.', 'ffcertificate' ) );
			}
			if ( ! AudienceWriter::set_tree_access( $id, $members_only, $require ) ) {
				return new \WP_Error( 'ffc_record_not_saved', __( 'The audience could not be saved.', 'ffcertificate' ) );
			}
			return true;
		}

		// No column holds it: the choice is written as two flags.
		if ( 'self_join_scope' === $key ) {
			if ( ! AudienceWriter::apply_self_join_scope( $id, (string) $value ) ) {
				return new \WP_Error( 'ffc_record_not_saved', __( 'The audience could not be saved.', 'ffcertificate' ) );
			}
			return true;
		}

		if ( ! AudienceWriter::update( $id, array( $key => $value ) ) ) {
			return new \WP_Error( 'ffc_record_not_saved', __( 'The audience could not be saved.', 'ffcertificate' ) );
		}
		return true;
	}
}
