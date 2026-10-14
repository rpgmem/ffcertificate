<?php
/**
 * DateMessagesRecordAutosave
 *
 * Registers the Date Messages rule with the record autosave (#1757).
 *
 * A rule is saved whole -- `RuleWriter::save()` writes every column a
 * {@see Rule} carries -- so a field is changed by reading the rule,
 * changing one value and rebuilding it through `Rule::from_array()`, the
 * same read-modify-write the activate / deactivate link does. The domain
 * validation that runs on Save therefore runs here too.
 *
 * @package FreeFormCertificate\DateMessages
 * @since   6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Core\EmailBodyAppearance;
use FreeFormCertificate\Core\RecordAutosave;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rule entity of the record autosave.
 */
class DateMessagesRecordAutosave {

	/**
	 * Rule columns saved as `1` / `0`.
	 *
	 * @var list<string>
	 */
	public const FLAGS = array( 'send_to_user', 'is_active', 'digest_enabled' );

	/**
	 * Appearance fields, keyed by their autosave key.
	 *
	 * @var array<string, string>
	 */
	public const APPEARANCE = array(
		'appearance_position'   => 'position',
		'appearance_text_width' => 'text_width',
	);

	/**
	 * Register the entity.
	 *
	 * @return void
	 */
	public static function register(): void {
		$fields = array_fill_keys( self::FLAGS, array( 'type' => 'bool' ) );

		$fields['appearance_position']   = array(
			'type'    => 'enum',
			'options' => EmailBodyAppearance::POSITIONS,
		);
		$fields['appearance_text_width'] = array(
			'type'    => 'enum',
			'options' => array_map( 'strval', EmailBodyAppearance::WIDTHS ),
		);

		RecordAutosave::register(
			'date_message_rule',
			array(
				'cap'    => 'ffc_manage_date_messages',
				'fields' => $fields,
				'exists' => static fn( int $id ): bool => null !== RuleReader::get_by_id( $id ),
				'save'   => array( self::class, 'save' ),
			)
		);
	}

	/**
	 * Change one field of a rule.
	 *
	 * @param int    $id    Rule id.
	 * @param string $key   Field key.
	 * @param mixed  $value Typed value.
	 * @return true|\WP_Error
	 */
	public static function save( int $id, string $key, $value ) {
		$rule = RuleReader::get_by_id( $id );
		if ( null === $rule ) {
			return new \WP_Error( 'ffc_record_not_saved', __( 'That rule no longer exists.', 'ffcertificate' ) );
		}

		$data       = $rule->to_columns();
		$data['id'] = $rule->id;

		if ( isset( self::APPEARANCE[ $key ] ) ) {
			$appearance                             = $rule->appearance->to_array();
			$appearance[ self::APPEARANCE[ $key ] ] = 'text_width' === self::APPEARANCE[ $key ] ? (int) $value : (string) $value;
			$data['appearance']                     = $appearance;
		} else {
			$data[ $key ] = $value ? '1' : '0';
		}

		$changed = Rule::from_array( $data );
		if ( is_wp_error( $changed ) ) {
			return $changed;
		}

		if ( false === RuleWriter::save( $changed ) ) {
			return new \WP_Error( 'ffc_record_not_saved', __( 'The rule could not be saved.', 'ffcertificate' ) );
		}
		return true;
	}
}
