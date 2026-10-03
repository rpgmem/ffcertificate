<?php
/**
 * Date sources.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The date sources a rule can name (#1538). A plain map rather than a
 * filterable registry: a source has to be written as code anyway, so a hook
 * here would only let a third party name a class nothing tested.
 */
final class DateSources {

	/**
	 * Source id => class.
	 *
	 * @var array<string, class-string<DateSourceInterface>>
	 */
	private const SOURCES = array(
		BirthdaySource::ID => BirthdaySource::class,
	);

	/**
	 * Whether a source id is known.
	 *
	 * @param string $id Source id.
	 * @return bool
	 */
	public static function has( string $id ): bool {
		return isset( self::SOURCES[ $id ] );
	}

	/**
	 * A source by id.
	 *
	 * @param string $id Source id.
	 * @return DateSourceInterface|null
	 */
	public static function get( string $id ): ?DateSourceInterface {
		$class = self::SOURCES[ $id ] ?? null;
		return null === $class ? null : new $class();
	}

	/**
	 * Every source id.
	 *
	 * @return array<int, string>
	 */
	public static function ids(): array {
		return array_keys( self::SOURCES );
	}
}
