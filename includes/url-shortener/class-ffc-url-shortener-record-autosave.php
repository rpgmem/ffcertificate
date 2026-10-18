<?php
/**
 * UrlShortenerRecordAutosave
 *
 * Registers the short URL with the record autosave (#1757), so the Short
 * URLs list switches a link on or off with a toggle that saves the moment
 * it flips (#1904), instead of a link that reloaded the page.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since   6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\RecordAutosave;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The short URL entity of the record autosave.
 */
class UrlShortenerRecordAutosave {

	/**
	 * The two stored statuses the toggle means. `trashed` is not one: a
	 * trashed link has no toggle and leaves the trash through Restore.
	 *
	 * @var array<int, string>
	 */
	public const STATUS_MAP = array(
		1 => 'active',
		0 => 'disabled',
	);

	/**
	 * Register the entity.
	 *
	 * @param UrlShortenerService $service The module's service.
	 * @return void
	 */
	public static function register( UrlShortenerService $service ): void {
		RecordAutosave::register(
			'short_url',
			array(
				// The capability the row actions' other writes already ask for.
				'cap'    => 'ffc_manage_url_shortener',
				'fields' => array(
					'status' => array(
						'type' => 'map',
						'map'  => self::STATUS_MAP,
					),
				),
				'exists' => static function ( int $id ) use ( $service ): bool {
					$record = $service->get_repository()->findById( $id );
					return is_array( $record ) && 'trashed' !== ( $record['status'] ?? '' );
				},
				'save'   => static function ( int $id, string $key, $value ) use ( $service ) {
					if ( ! $service->set_active( $id, self::STATUS_MAP[1] === $value ) ) {
						return new \WP_Error( 'ffc_record_not_saved', __( 'The short URL could not be saved.', 'ffcertificate' ) );
					}
					return true;
				},
			)
		);
	}
}
