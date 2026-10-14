<?php
/**
 * ReregistrationRecordAutosave
 *
 * Registers the reregistration campaign with the record autosave (#1757).
 *
 * The status is left out on purpose: moving a campaign to active reopens
 * and seeds the submissions, which belongs to the Save button and its
 * redirect notice, not to a field that saves as it is touched.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Core\RecordAutosave;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The campaign entity of the record autosave.
 */
class ReregistrationRecordAutosave {

	/**
	 * Campaign columns saved as `1` / `0`.
	 *
	 * @var list<string>
	 */
	public const FLAGS = array(
		'auto_approve',
		'email_invitation_enabled',
		'email_reminder_enabled',
		'email_confirmation_enabled',
	);

	/**
	 * Register the entity.
	 *
	 * @return void
	 */
	public static function register(): void {
		RecordAutosave::register(
			'reregistration',
			array(
				'cap'    => 'ffc_manage_reregistration',
				'fields' => array_fill_keys( self::FLAGS, array( 'type' => 'bool' ) ),
				'exists' => static fn( int $id ): bool => null !== ReregistrationRepository::get_by_id( $id ),
				'save'   => array( self::class, 'save' ),
			)
		);
	}

	/**
	 * Save one campaign column.
	 *
	 * @param int    $id    Campaign id.
	 * @param string $key   Column.
	 * @param mixed  $value Typed value.
	 * @return true|\WP_Error
	 */
	public static function save( int $id, string $key, $value ) {
		if ( ! ReregistrationRepository::update( $id, array( $key => $value ) ) ) {
			return new \WP_Error( 'ffc_record_not_saved', __( 'The campaign could not be saved.', 'ffcertificate' ) );
		}
		return true;
	}
}
