<?php
/**
 * RecruitmentRecordAutosave
 *
 * Registers the recruitment notice and reason with the record autosave
 * (#1757).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\RecordAutosave;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The notice and reason entities of the record autosave.
 */
class RecruitmentRecordAutosave {

	/**
	 * Public-list columns that are always shown, whatever is posted.
	 *
	 * @var list<string>
	 */
	public const FIXED_COLUMNS = array( 'rank', 'name' );

	/**
	 * Register both entities.
	 *
	 * @return void
	 */
	public static function register(): void {
		RecordAutosave::register(
			'recruitment_notice',
			array(
				'cap'    => 'ffc_manage_recruitment',
				'fields' => array_fill_keys( self::toggleable_columns(), array( 'type' => 'bool' ) ),
				'exists' => static fn( int $id ): bool => null !== RecruitmentNoticeReader::get_by_id( $id ),
				'save'   => array( self::class, 'save_notice' ),
			)
		);

		RecordAutosave::register(
			'recruitment_reason',
			array(
				'cap'    => 'ffc_manage_recruitment_reasons',
				'fields' => array(
					'applies_to' => array(
						'type'    => 'list',
						'options' => RecruitmentReasonReader::APPLIES_TO_VALUES,
					),
				),
				'exists' => static fn( int $id ): bool => null !== RecruitmentReasonReader::get_by_id( $id ),
				'save'   => array( self::class, 'save_reason' ),
			)
		);
	}

	/**
	 * The public-list columns an operator can switch.
	 *
	 * @return list<string>
	 */
	public static function toggleable_columns(): array {
		$keys = array_map( 'strval', array_keys( self::default_columns() ) );
		return array_values( array_diff( $keys, self::FIXED_COLUMNS ) );
	}

	/**
	 * The default column map, decoded from the reader's JSON constant.
	 *
	 * @return array<string, mixed>
	 */
	private static function default_columns(): array {
		$decoded = json_decode( RecruitmentNoticeReader::DEFAULT_PUBLIC_COLUMNS_CONFIG, true );
		// The constant is a literal, so this always decodes; the guard keeps
		// the type honest for a reader that changes it.
		return is_array( $decoded ) ? $decoded : array(); // @phpstan-ignore function.alreadyNarrowedType (A literal today; a guard for the day it is not.)
	}

	/**
	 * Switch one public-list column. The stored JSON is read, merged over
	 * the defaults, changed in that one key and written back whole, the
	 * shape the Save button writes.
	 *
	 * @param int    $id    Notice id.
	 * @param string $key   Column key.
	 * @param mixed  $value Typed value (`1` / `0`).
	 * @return true|\WP_Error
	 */
	public static function save_notice( int $id, string $key, $value ) {
		$notice = RecruitmentNoticeReader::get_by_id( $id );
		$stored = null !== $notice ? json_decode( (string) ( $notice->public_columns_config ?? '' ), true ) : null;

		$config = array_merge( self::default_columns(), is_array( $stored ) ? $stored : array() );

		$config[ $key ] = (bool) $value;
		foreach ( self::FIXED_COLUMNS as $fixed ) {
			$config[ $fixed ] = true;
		}

		if ( ! RecruitmentNoticeWriter::update( $id, array( 'public_columns_config' => (string) wp_json_encode( $config ) ) ) ) {
			return new \WP_Error( 'ffc_record_not_saved', __( 'The notice could not be saved.', 'ffcertificate' ) );
		}
		return true;
	}

	/**
	 * Save which decisions a reason applies to. An empty list means every
	 * decision, as on Save.
	 *
	 * @param int    $id    Reason id.
	 * @param string $key   Field key (`applies_to`).
	 * @param mixed  $value Typed value (a list).
	 * @return true|\WP_Error
	 */
	public static function save_reason( int $id, string $key, $value ) {
		unset( $key );
		if ( ! RecruitmentReasonWriter::update( $id, array( 'applies_to' => is_array( $value ) ? $value : array() ) ) ) {
			return new \WP_Error( 'ffc_record_not_saved', __( 'The reason could not be saved.', 'ffcertificate' ) );
		}
		return true;
	}
}
