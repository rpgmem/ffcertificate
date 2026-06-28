<?php
/**
 * Lightweight real stand-ins for RecruitmentAdminPageRenderer's collaborators.
 *
 * The renderer reads real class constants (RecruitmentAdminPage::PAGE_SLUG,
 * RecruitmentSettings::OPTION_GROUP/OPTION_NAME) and calls static gate methods
 * + reader/list-table statics. Mockery `alias:` mocks cannot expose true
 * `::CONST` access, so we define narrow real stubs here instead.
 *
 * This file lives under tests/fixtures (NOT auto-discovered by PHPUnit) and is
 * `require_once`d from RecruitmentAdminPageRendererTest::setUp() — i.e. only
 * when the isolated test process actually runs, never at suite-discovery time.
 * Defining these at file scope inside a *Test.php file would declare the stubs
 * in the parent discovery process and poison the real classes for every other
 * recruitment test (see #563). Per-test behavior is driven through the public
 * static $flags, reset in setUp().
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! class_exists( __NAMESPACE__ . '\RecruitmentAdminPage', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class RecruitmentAdminPage {
		public const PAGE_SLUG       = 'ffc-recruitment';
		public static bool $view_settings = true;
		public static bool $view_reasons  = true;
		public static bool $edit_reasons  = true;
		public static bool $edit_settings = true;
		public static function can_view_settings(): bool { return self::$view_settings; }
		public static function can_view_reasons(): bool { return self::$view_reasons; }
		public static function can_edit_reasons(): bool { return self::$edit_reasons; }
		public static function can_edit_settings(): bool { return self::$edit_settings; }
	}
	// tabs.php (global-namespace template) references `RecruitmentAdminPage`
	// unqualified, which resolves to the GLOBAL `\RecruitmentAdminPage`.
	// Alias the stub into the global namespace so render_tabs() can execute
	// its template include.
	if ( ! class_exists( '\RecruitmentAdminPage', false ) ) {
		class_alias( __NAMESPACE__ . '\RecruitmentAdminPage', '\RecruitmentAdminPage' );
	}
}

if ( ! class_exists( __NAMESPACE__ . '\RecruitmentSettings', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class RecruitmentSettings {
		public const OPTION_NAME  = 'ffc_recruitment_settings';
		public const OPTION_GROUP = 'ffc_recruitment_settings_group';
		/** @var array<string,mixed> */
		public static array $values = array();
		/** @return array<string,mixed> */
		public static function all(): array { return self::$values; }
	}
}

if ( ! class_exists( __NAMESPACE__ . '\RecruitmentNoticeReader', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class RecruitmentNoticeReader {
		/** @var array<int,object> */
		public static array $rows = array();
		/** @return array<int,object> */
		public static function get_all(): array { return self::$rows; }
	}
}

if ( ! class_exists( __NAMESPACE__ . '\RecruitmentAdjutancyReader', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class RecruitmentAdjutancyReader {
		public const DEFAULT_COLOR = '#e9ecef';
	}
}

if ( ! class_exists( __NAMESPACE__ . '\RecruitmentReasonReader', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class RecruitmentReasonReader {
		public const DEFAULT_COLOR = '#e9ecef';
	}
}

// Stub list tables — no-op the methods the renderer drives.
if ( ! class_exists( __NAMESPACE__ . '\RecruitmentNoticesListTable', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class RecruitmentNoticesListTable {
		public function prepare_items(): void {}
		public function search_box( string $a, string $b ): void {}
		public function display(): void {}
	}
}
if ( ! class_exists( __NAMESPACE__ . '\RecruitmentAdjutanciesListTable', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class RecruitmentAdjutanciesListTable {
		public function prepare_items(): void {}
		public function search_box( string $a, string $b ): void {}
		public function display(): void {}
	}
}
if ( ! class_exists( __NAMESPACE__ . '\RecruitmentReasonsListTable', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class RecruitmentReasonsListTable {
		public function __construct( bool $can_edit = true ) {}
		public function prepare_items(): void {}
		public function search_box( string $a, string $b ): void {}
		public function display(): void {}
	}
}
if ( ! class_exists( __NAMESPACE__ . '\RecruitmentCandidatesListTable', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class RecruitmentCandidatesListTable {
		public function prepare_items(): void {}
		public function search_box( string $a, string $b ): void {}
		public function display(): void {}
	}
}

namespace FreeFormCertificate\Core;

if ( ! class_exists( __NAMESPACE__ . '\Capabilities', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class Capabilities {
		public static bool $admin_or = true;
		public static function current_user_can_admin_or( string $cap ): bool { return self::$admin_or; }
	}
}

namespace FreeFormCertificate\Admin;

if ( ! class_exists( __NAMESPACE__ . '\AdminUI', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class AdminUI {
		/** @param array<string,mixed> $args */
		public static function render_toggle( array $args ): void { echo '<input type="checkbox">'; }
	}
}
