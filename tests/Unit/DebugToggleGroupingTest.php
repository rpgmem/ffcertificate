<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 6.6.4 follow-up (#361 Sprint 4) — Settings → Debug tab view is now
 * organized into three named sections (Client / Server / Admin). This
 * test pins that contract by scanning the view file and asserting:
 *   - All 3 section headings exist in the expected order.
 *   - Each toggle key lands in the correct section.
 *
 * Pure static-analysis: the view file is plain PHP-with-HTML, no DB
 * or admin context needed.
 */
class DebugToggleGroupingTest extends TestCase {

    /** Toggle key → expected section title. */
    private const EXPECTED_GROUPING = array(
        // Client (browser)
        'debug_frontend'      => 'Client (browser)',
        'debug_geofence'      => 'Client (browser)',
        'debug_qrcode'        => 'Client (browser)',
        'debug_browser_env'   => 'Client (browser)',
        // Server / Processing
        'debug_form_processor' => 'Server / Processing',
        'debug_pdf_generator'  => 'Server / Processing',
        'debug_email_handler'  => 'Server / Processing',
        'debug_encryption'     => 'Server / Processing',
        'debug_rest_api'       => 'Server / Processing',
        'debug_user_manager'   => 'Server / Processing',
        // Admin / Operational
        'debug_admin'          => 'Admin / Operational',
        'debug_self_scheduling' => 'Admin / Operational',
        'debug_audience'       => 'Admin / Operational',
        'debug_migrations'     => 'Admin / Operational',
        'debug_activity_log'   => 'Admin / Operational',
    );

    private const EXPECTED_SECTION_ORDER = array(
        'Client (browser)',
        'Server / Processing',
        'Admin / Operational',
    );

    public function test_view_file_contains_all_three_section_headings_in_order(): void {
        $content = file_get_contents( FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-advanced.php' );
        $this->assertIsString( $content );

        $offsets = array();
        foreach ( self::EXPECTED_SECTION_ORDER as $title ) {
            $needle = "esc_html_e( '" . $title . "'";
            $pos    = strpos( $content, $needle );
            $this->assertNotFalse( $pos, "section heading not found: $title" );
            $offsets[ $title ] = $pos;
        }

        // Order check: each subsequent section heading appears later in
        // the file than the previous one.
        $prev_pos = -1;
        foreach ( self::EXPECTED_SECTION_ORDER as $title ) {
            $this->assertGreaterThan(
                $prev_pos,
                $offsets[ $title ],
                "section '$title' must appear after the previous one"
            );
            $prev_pos = $offsets[ $title ];
        }
    }

    public function test_each_toggle_key_lands_in_its_expected_section(): void {
        $content = file_get_contents( FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-advanced.php' );
        $this->assertIsString( $content );

        // Compute section start offsets.
        $section_offsets = array();
        foreach ( self::EXPECTED_SECTION_ORDER as $title ) {
            $section_offsets[ $title ] = strpos( $content, "esc_html_e( '" . $title . "'" );
        }
        $section_offsets['_END_'] = strlen( $content );
        $boundaries = array_values( $section_offsets );

        foreach ( self::EXPECTED_GROUPING as $key => $expected_section ) {
            $toggle_pos = strpos( $content, 'label for="' . $key . '"' );
            $this->assertNotFalse( $toggle_pos, "toggle not found in view: $key" );

            // Find which section bracket the toggle falls in.
            $expected_start = $section_offsets[ $expected_section ];
            $expected_end_index = array_search( $expected_section, self::EXPECTED_SECTION_ORDER, true );
            $expected_end = $expected_end_index < count( self::EXPECTED_SECTION_ORDER ) - 1
                ? $section_offsets[ self::EXPECTED_SECTION_ORDER[ $expected_end_index + 1 ] ]
                : $section_offsets['_END_'];

            $this->assertGreaterThan(
                $expected_start,
                $toggle_pos,
                "toggle '$key' must be inside section '$expected_section' (after its heading)"
            );
            $this->assertLessThan(
                $expected_end,
                $toggle_pos,
                "toggle '$key' must be inside section '$expected_section' (before the next section heading)"
            );
        }
    }
}
