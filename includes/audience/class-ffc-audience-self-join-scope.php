<?php
/**
 * AudienceSelfJoinScope
 *
 * The four self-join choices of a top-level audience with child groups
 * (#1901), and how each maps onto the two flags that store it: the
 * audience's own `allow_self_join`, and every descendant's.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The scope of self-join on an audience with children and grandchildren.
 *
 * No column holds the choice: it is written as the audience's own flag plus
 * the same flag on every descendant, and read back from those rows. When the
 * descendants disagree there is no choice to read, and the screen says so.
 */
class AudienceSelfJoinScope {

	/**
	 * Neither the audience nor anything below it.
	 *
	 * @var string
	 */
	public const OFF = 'off';

	/**
	 * The audience itself; its children and grandchildren closed.
	 *
	 * @var string
	 */
	public const SELF = 'self';

	/**
	 * The audience and every child and grandchild.
	 *
	 * @var string
	 */
	public const ALL = 'all';

	/**
	 * Every child and grandchild; the audience is only a heading over them.
	 *
	 * @var string
	 */
	public const DESCENDANTS = 'descendants';

	/**
	 * Each choice as `array( own flag, descendants' flag )`.
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	private const FLAGS = array(
		self::OFF         => array( 0, 0 ),
		self::SELF        => array( 1, 0 ),
		self::ALL         => array( 1, 1 ),
		self::DESCENDANTS => array( 0, 1 ),
	);

	/**
	 * Every choice, in the order the control draws them.
	 *
	 * @return list<string>
	 */
	public static function values(): array {
		return array_keys( self::FLAGS );
	}

	/**
	 * The two flags a choice writes.
	 *
	 * @param string $scope A choice.
	 * @return array{0: int, 1: int}|null Own flag and descendants' flag, null for anything else.
	 */
	public static function flags( string $scope ): ?array {
		return self::FLAGS[ $scope ] ?? null;
	}

	/**
	 * The choice two stored flags read as.
	 *
	 * @param bool        $own         The audience's own `allow_self_join`.
	 * @param string|null $descendants {@see AudienceReader::descendants_self_join_state()}.
	 * @return string|null Null when the descendants disagree or there are none.
	 */
	public static function from_flags( bool $own, ?string $descendants ): ?string {
		if ( '1' !== $descendants && '0' !== $descendants ) {
			return null;
		}
		$wanted = array( $own ? 1 : 0, (int) $descendants );
		$scope  = array_search( $wanted, self::FLAGS, true );
		return is_string( $scope ) ? $scope : null;
	}
}
