<?php
/**
 * Custom Field Answer Merge
 *
 * Moves the answers stored under an audience's own copy of a field onto the
 * field every audience now inherits (#2502).
 *
 * @since   6.44.0
 * @package FreeFormCertificate\Reregistration
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers are stored in the user's profile meta keyed `field_<id>`, by the
 * id of the field row. Before #2502 every audience carried its own copy of
 * the standard fields, so one question answered in two audiences sat under
 * two ids. When the copies are folded into the inherited field
 * ({@see ReregistrationStandardFieldsSeeder::consolidate_into_global()}),
 * the id each copy had is recorded here with the id it folded into, and
 * {@see self::converge()} rewrites a user's answers onto that id.
 *
 * The rewrite runs twice, on purpose. {@see CustomFieldReader::get_user_data()}
 * applies it to what it reads, so no answer is out of sight while the batched
 * migration has not reached its user; the migration then writes it, and the
 * map is deleted once every user has been passed.
 *
 * When one person has different answers under several copies, the one kept
 * is, in order: the value their latest reregistration submission carries for
 * that key; otherwise the copy of the deepest audience they belong to;
 * otherwise the copy with the lowest id.
 */
class CustomFieldAnswerMerge {

	/**
	 * Option holding the folded ids: old id => [base id, audience id, depth, key].
	 * Listed in `uninstall.php`.
	 */
	public const MAP_OPTION = 'ffc_custom_fields_id_map';

	/**
	 * The map, read once per request.
	 *
	 * @var array<int, array{0: int, 1: int, 2: int, 3: string}>|null
	 */
	private static ?array $map = null;

	/**
	 * The folded ids.
	 *
	 * @return array<int, array{0: int, 1: int, 2: int, 3: string}>
	 */
	public static function map(): array {
		if ( null !== self::$map ) {
			return self::$map;
		}
		$raw = get_option( self::MAP_OPTION, array() );
		$map = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $old => $entry ) {
				if ( ! is_numeric( $old ) || ! is_array( $entry ) || count( $entry ) < 4 ) {
					continue;
				}
				$map[ (int) $old ] = array(
					is_numeric( $entry[0] ) ? (int) $entry[0] : 0,
					is_numeric( $entry[1] ) ? (int) $entry[1] : 0,
					is_numeric( $entry[2] ) ? (int) $entry[2] : 0,
					is_scalar( $entry[3] ) ? (string) $entry[3] : '',
				);
			}
		}
		self::$map = $map;
		return $map;
	}

	/**
	 * Add folded ids to the map.
	 *
	 * @param array<int, array{0: int, 1: int, 2: int, 3: string}> $entries Old id => [base id, audience id, depth, key].
	 * @return void
	 */
	public static function record( array $entries ): void {
		if ( empty( $entries ) ) {
			return;
		}
		$map = self::map();
		foreach ( $entries as $old => $entry ) {
			$map[ (int) $old ] = $entry;
		}
		update_option( self::MAP_OPTION, $map, false );
		self::$map = $map;
	}

	/**
	 * Forget the map: every user's answers were moved.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::MAP_OPTION );
		self::$map = array();
	}

	/**
	 * Drop the request memo, for tests and after a write elsewhere.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$map = null;
	}

	/**
	 * The old ids folded into a field.
	 *
	 * @param int $field_id The field answers converge on.
	 * @return list<int>
	 */
	public static function old_ids_of( int $field_id ): array {
		$ids = array();
		foreach ( self::map() as $old => $entry ) {
			if ( $entry[0] === $field_id ) {
				$ids[] = $old;
			}
		}
		return $ids;
	}

	/**
	 * A user's answers with every folded id moved onto the field it folded
	 * into, and the old keys removed.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $data    Answers keyed `field_<id>`.
	 * @return array{data: array<string, mixed>, changed: bool}
	 */
	public static function converge( int $user_id, array $data ): array {
		$map = self::map();
		if ( empty( $map ) || empty( $data ) ) {
			return array(
				'data'    => $data,
				'changed' => false,
			);
		}

		$candidates = array();
		foreach ( $data as $meta_key => $value ) {
			if ( 0 !== strpos( (string) $meta_key, 'field_' ) ) {
				continue;
			}
			$old = (int) substr( (string) $meta_key, 6 );
			if ( isset( $map[ $old ] ) ) {
				$candidates[ $map[ $old ][0] ][ $old ] = $value;
			}
		}
		if ( empty( $candidates ) ) {
			return array(
				'data'    => $data,
				'changed' => false,
			);
		}

		$latest    = null;
		$audiences = null;
		foreach ( $candidates as $base => $values ) {
			foreach ( array_keys( $values ) as $old ) {
				unset( $data[ 'field_' . $old ] );
			}
			if ( array_key_exists( 'field_' . $base, $data ) ) {
				// An answer given since the fold is the newest there is.
				continue;
			}
			if ( null === $latest ) {
				$latest = ReregistrationSubmissionReader::get_latest_answers_for_user( $user_id, 0 );
			}
			$key = $map[ (int) array_key_first( $values ) ][3];
			if ( '' !== $key && array_key_exists( $key, $latest ) ) {
				foreach ( $values as $value ) {
					if ( self::same( $value, $latest[ $key ] ) ) {
						$data[ 'field_' . $base ] = $value;
						continue 2;
					}
				}
			}
			if ( null === $audiences ) {
				$audiences = array_map(
					static fn( $audience ) => (int) $audience->id,
					AudienceReader::get_user_audiences( $user_id )
				);
			}
			$data[ 'field_' . $base ] = self::pick( $values, $map, $audiences );
		}

		return array(
			'data'    => $data,
			'changed' => true,
		);
	}

	/**
	 * The copy of the deepest audience the user belongs to, otherwise the
	 * copy with the lowest id.
	 *
	 * @param array<int, mixed>                                    $values    Old id => value.
	 * @param array<int, array{0: int, 1: int, 2: int, 3: string}> $map       The folded ids.
	 * @param array<int, int>                                      $audiences The user's audience ids.
	 * @return mixed
	 */
	private static function pick( array $values, array $map, array $audiences ) {
		$best       = null;
		$best_depth = -1;
		foreach ( $values as $old => $value ) {
			if ( in_array( $map[ $old ][1], $audiences, true ) && $map[ $old ][2] > $best_depth ) {
				$best       = $old;
				$best_depth = $map[ $old ][2];
			}
		}
		if ( null !== $best ) {
			return $values[ $best ];
		}
		ksort( $values );
		return reset( $values );
	}

	/**
	 * Whether two stored values are the same answer.
	 *
	 * @param mixed $a A value.
	 * @param mixed $b Another value.
	 * @return bool
	 */
	private static function same( $a, $b ): bool {
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return (string) $a === (string) $b;
		}
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}
}
