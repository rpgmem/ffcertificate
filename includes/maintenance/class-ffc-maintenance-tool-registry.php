<?php
/**
 * MaintenanceToolRegistry
 *
 * Holds the set of {@see MaintenanceToolInterface} instances surfaced on the
 * Settings → Data Migrations tab. The admin tab renders one card per
 * registered tool and the form handler dispatches actions by looking the tool
 * up by id.
 *
 * `create_default()` returns a registry pre-populated with the built-in tools
 * so call sites don't have to know which tools ship with the plugin.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.7.x
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Migrations\ObsoleteShortcodeCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of pluggable maintenance tools.
 */
class MaintenanceToolRegistry {

	/**
	 * Registered tools keyed by id, in insertion (display) order.
	 *
	 * @var array<string, MaintenanceToolInterface>
	 */
	private array $tools = array();

	/**
	 * Register a tool. A later registration with the same id replaces the
	 * earlier one but keeps the original position.
	 *
	 * @param MaintenanceToolInterface $tool Tool instance.
	 * @return void
	 */
	public function register( MaintenanceToolInterface $tool ): void {
		$this->tools[ $tool->get_id() ] = $tool;
	}

	/**
	 * Get a single tool by id, or null when none is registered under it.
	 *
	 * @param string $id Tool id.
	 * @return MaintenanceToolInterface|null
	 */
	public function get( string $id ): ?MaintenanceToolInterface {
		return $this->tools[ $id ] ?? null;
	}

	/**
	 * Whether a tool is registered under the given id.
	 *
	 * @param string $id Tool id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->tools[ $id ] );
	}

	/**
	 * All registered tools in display order.
	 *
	 * @return array<int, MaintenanceToolInterface>
	 */
	public function all(): array {
		return array_values( $this->tools );
	}

	/**
	 * Build a registry pre-populated with the built-in maintenance tools.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		$registry = new self();
		$registry->register( new ObsoleteShortcodeCleaner() );
		$registry->register( new UrlShortenerCleaner() );
		$registry->register( new PublicOperatorAccessDisabler() );
		return $registry;
	}
}
