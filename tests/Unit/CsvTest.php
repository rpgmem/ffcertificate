<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Csv;
use FreeFormCertificate\Core\CsvReader;
use FreeFormCertificate\Core\CsvWriter;

/**
 * Tests for Csv / CsvWriter / CsvReader: round-trip integrity, BOM
 * placement, delimiter auto-detection (including ties), RFC 4180
 * quoting of cells with delimiters / quotes / newlines, ownership
 * semantics, and the resource-vs-string reader entrypoints.
 */
class CsvTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function tmp_handle() {
        $handle = fopen( 'php://memory', 'r+' );
        if ( false === $handle ) {
            $this->fail( 'Could not open php://memory' );
        }
        return $handle;
    }

    private function dump( $handle ): string {
        rewind( $handle );
        return (string) stream_get_contents( $handle );
    }

    // ==================================================================
    // Writer — BOM contract
    // ==================================================================

    public function test_writer_emits_bom_before_first_row(): void {
        $h = $this->tmp_handle();
        $w = Csv::writer( $h );
        $w->row( array( 'a', 'b' ) );
        $w->close();

        $bytes = $this->dump( $h );
        $this->assertStringStartsWith( "\xEF\xBB\xBF", $bytes, 'BOM should be the first three bytes' );
    }

    public function test_writer_emits_bom_only_once(): void {
        $h = $this->tmp_handle();
        $w = Csv::writer( $h );
        $w->row( array( 'h1', 'h2' ) );
        $w->row( array( 'r1', 'r2' ) );
        $w->row( array( 'r3', 'r4' ) );

        $bytes      = $this->dump( $h );
        $bom_count  = substr_count( $bytes, "\xEF\xBB\xBF" );
        $this->assertSame( 1, $bom_count, 'BOM must appear exactly once' );
    }

    public function test_writer_no_bom_when_no_rows_written(): void {
        $h = $this->tmp_handle();
        Csv::writer( $h )->close();

        $bytes = $this->dump( $h );
        $this->assertSame( '', $bytes );
    }

    public function test_writer_skip_bom_suppresses_emission(): void {
        $h = $this->tmp_handle();
        $w = Csv::writer( $h, ';', true );
        $w->row( array( 'a', 'b' ) );
        $w->close();

        $bytes = $this->dump( $h );
        $this->assertStringStartsNotWith( "\xEF\xBB\xBF", $bytes, 'skip_bom must suppress BOM' );
        $this->assertSame( "a;b\n", $bytes );
    }

    // ==================================================================
    // Writer — delimiter
    // ==================================================================

    public function test_writer_uses_semicolon_by_default(): void {
        $h = $this->tmp_handle();
        $w = Csv::writer( $h );
        $w->row( array( 'a', 'b', 'c' ) );

        $bytes = ltrim( $this->dump( $h ), "\xEF\xBB\xBF" );
        $this->assertSame( "a;b;c\n", $bytes );
    }

    public function test_writer_respects_custom_delimiter(): void {
        $h = $this->tmp_handle();
        $w = Csv::writer( $h, ',' );
        $w->row( array( 'a', 'b', 'c' ) );

        $bytes = ltrim( $this->dump( $h ), "\xEF\xBB\xBF" );
        $this->assertSame( "a,b,c\n", $bytes );
    }

    // ==================================================================
    // Writer — quoting (RFC 4180)
    // ==================================================================

    public function test_writer_quotes_cell_with_delimiter(): void {
        $h = $this->tmp_handle();
        Csv::writer( $h )->row( array( 'a;b', 'c' ) );

        $bytes = ltrim( $this->dump( $h ), "\xEF\xBB\xBF" );
        $this->assertSame( "\"a;b\";c\n", $bytes );
    }

    public function test_writer_quotes_cell_with_quote(): void {
        $h = $this->tmp_handle();
        Csv::writer( $h )->row( array( 'a"b', 'c' ) );

        $bytes = ltrim( $this->dump( $h ), "\xEF\xBB\xBF" );
        $this->assertSame( "\"a\"\"b\";c\n", $bytes );
    }

    public function test_writer_quotes_cell_with_newline(): void {
        $h = $this->tmp_handle();
        Csv::writer( $h )->row( array( "a\nb", 'c' ) );

        $bytes = ltrim( $this->dump( $h ), "\xEF\xBB\xBF" );
        $this->assertStringContainsString( "\"a\nb\"", $bytes );
    }

    // ==================================================================
    // Writer — formula-injection neutralization (CSV injection / DDE)
    // ==================================================================

    /**
     * A cell whose first character is a spreadsheet formula trigger is
     * written with a leading single quote. Asserted through a writer→reader
     * round-trip so RFC-4180 quoting (which wraps tab/CR cells) is transparent.
     *
     * @dataProvider formula_trigger_provider
     */
    public function test_writer_neutralizes_formula_triggers( string $cell ): void {
        $h = $this->tmp_handle();
        Csv::writer( $h )->row( array( $cell, 'safe' ) );
        rewind( $h );
        $row = Csv::reader( $h )->header();

        $this->assertSame( "'" . $cell, $row[0], 'Trigger cell must gain a leading quote' );
        $this->assertSame( 'safe', $row[1], 'Adjacent safe cell must be untouched' );
    }

    public function formula_trigger_provider(): array {
        return array(
            'equals'   => array( '=HYPERLINK("http://evil.example","x")' ),
            'plus'     => array( '+1+2' ),
            'minus'    => array( '-2+3' ),
            'at'       => array( '@SUM(A1:A9)' ),
            'tab'      => array( "\t=1+1" ),
            'carriage' => array( "\r=1+1" ),
        );
    }

    public function test_writer_leaves_safe_cells_untouched(): void {
        // No cell STARTS with a trigger char, so nothing is prefixed — an
        // interior '=' (Programa =2) is not a formula and must stay verbatim.
        $h = $this->tmp_handle();
        Csv::writer( $h )->row( array( 'Alice', 'a@example.com', '123.456.789-09', 'Programa =2' ) );
        rewind( $h );
        $row = Csv::reader( $h )->header();

        $this->assertSame( array( 'Alice', 'a@example.com', '123.456.789-09', 'Programa =2' ), $row );
    }

    public function test_writer_neutralized_value_round_trips_with_quote_prefix(): void {
        // Neutralization is intentionally not lossless: the leading quote is
        // what stops formula evaluation, so the reader reads it back with the
        // quote. Documents the transform as explicit and observable.
        $h = $this->tmp_handle();
        Csv::writer( $h )->row( array( '=1+1', 'x' ) );
        rewind( $h );
        $this->assertSame( array( "'=1+1", 'x' ), Csv::reader( $h )->header() );
    }

    // ==================================================================
    // Writer — rows() iterable
    // ==================================================================

    public function test_writer_rows_accepts_array(): void {
        $h = $this->tmp_handle();
        Csv::writer( $h )->rows( array(
            array( 'h1', 'h2' ),
            array( 'a', 'b' ),
            array( 'c', 'd' ),
        ) );

        $bytes = ltrim( $this->dump( $h ), "\xEF\xBB\xBF" );
        $this->assertSame( "h1;h2\na;b\nc;d\n", $bytes );
    }

    public function test_writer_rows_accepts_generator(): void {
        $h   = $this->tmp_handle();
        $gen = ( static function () {
            yield array( 'h1', 'h2' );
            yield array( 'a', 'b' );
        } )();
        Csv::writer( $h )->rows( $gen );

        $bytes = ltrim( $this->dump( $h ), "\xEF\xBB\xBF" );
        $this->assertSame( "h1;h2\na;b\n", $bytes );
    }

    // ==================================================================
    // Writer — close() ownership
    // ==================================================================

    public function test_writer_close_idempotent(): void {
        $h = $this->tmp_handle();
        $w = Csv::writer( $h );
        $w->row( array( 'a' ) );
        $w->close();
        $w->close(); // must not throw
        $this->assertTrue( true );
    }

    public function test_writer_throws_on_write_after_close(): void {
        $h = $this->tmp_handle();
        $w = Csv::writer( $h );
        $w->close();

        $this->expectException( \LogicException::class );
        $w->row( array( 'a' ) );
    }

    public function test_writer_does_not_close_borrowed_handle(): void {
        $h = $this->tmp_handle();
        $w = Csv::writer( $h );
        $w->row( array( 'a' ) );
        $w->close();
        $this->assertTrue( is_resource( $h ), 'Borrowed handle must remain open after writer close' );
    }

    // ==================================================================
    // Reader — delimiter auto-detection
    // ==================================================================

    public function test_reader_detects_semicolon(): void {
        $r = Csv::reader_from_string( "h1;h2;h3\na;b;c\n" );
        $this->assertSame( ';', $r->delimiter );
        $this->assertSame( array( 'h1', 'h2', 'h3' ), $r->header() );
    }

    public function test_reader_detects_comma(): void {
        $r = Csv::reader_from_string( "h1,h2,h3\na,b,c\n" );
        $this->assertSame( ',', $r->delimiter );
        $this->assertSame( array( 'h1', 'h2', 'h3' ), $r->header() );
    }

    public function test_reader_tie_resolves_to_comma(): void {
        // Equal counts of `,` and `;` — rule: comma wins (back-compat).
        $r = Csv::reader_from_string( "a,b;c,d;e\n" );
        $this->assertSame( ',', $r->delimiter );
    }

    public function test_reader_ignores_delimiter_inside_quotes(): void {
        // Two semicolons total but one is inside quotes; must count one.
        $r = Csv::reader_from_string( "\"a;b\",c\n" );
        $this->assertSame( ',', $r->delimiter, 'Quoted ; must not tip detection' );
    }

    public function test_reader_force_delimiter_overrides_detection(): void {
        $r = Csv::reader_from_string( "h1;h2\n", ',' );
        $this->assertSame( ',', $r->delimiter );
    }

    // ==================================================================
    // Reader — BOM tolerance
    // ==================================================================

    public function test_reader_strips_bom_before_detection(): void {
        $r = Csv::reader_from_string( "\xEF\xBB\xBFh1;h2\na;b\n" );
        $this->assertSame( ';', $r->delimiter );
        $this->assertSame( array( 'h1', 'h2' ), $r->header() );
    }

    public function test_reader_handles_no_bom(): void {
        $r = Csv::reader_from_string( "h1;h2\na;b\n" );
        $this->assertSame( array( 'h1', 'h2' ), $r->header() );
        $this->assertSame( array( array( 'a', 'b' ) ), $r->all() );
    }

    // ==================================================================
    // Reader — body iteration
    // ==================================================================

    public function test_reader_each_streams_body(): void {
        $r    = Csv::reader_from_string( "h1;h2\nx;1\ny;2\nz;3\n" );
        $rows = array();
        $r->each(
            static function ( array $row ) use ( &$rows ): void {
                $rows[] = $row;
            }
        );
        $this->assertSame(
            array(
                array( 'x', '1' ),
                array( 'y', '2' ),
                array( 'z', '3' ),
            ),
            $rows
        );
    }

    public function test_reader_all_returns_body_only(): void {
        $r = Csv::reader_from_string( "h1;h2\nx;1\ny;2\n" );
        $this->assertSame(
            array(
                array( 'x', '1' ),
                array( 'y', '2' ),
            ),
            $r->all()
        );
    }

    public function test_reader_handles_quoted_delimiter_in_body(): void {
        $r = Csv::reader_from_string( "h1;h2\n\"a;b\";c\n" );
        $this->assertSame( array( array( 'a;b', 'c' ) ), $r->all() );
    }

    public function test_reader_handles_escaped_quote_in_body(): void {
        $r = Csv::reader_from_string( "h1;h2\n\"a\"\"b\";c\n" );
        $this->assertSame( array( array( 'a"b', 'c' ) ), $r->all() );
    }

    // ==================================================================
    // Round-trip — writer → reader through the same stream
    // ==================================================================

    public function test_round_trip_preserves_simple_rows(): void {
        $rows = array(
            array( 'name', 'email', 'count' ),
            array( 'Alice', 'a@example.com', '5' ),
            array( 'Bob', 'b@example.com', '12' ),
        );

        $h = $this->tmp_handle();
        Csv::writer( $h )->rows( $rows );
        rewind( $h );
        $r = Csv::reader( $h );

        $this->assertSame( ';', $r->delimiter );
        $this->assertSame( $rows[0], $r->header() );
        $this->assertSame( array_slice( $rows, 1 ), $r->all() );
    }

    public function test_round_trip_preserves_special_characters(): void {
        $rows = array(
            array( 'col1', 'col2' ),
            array( 'has;semicolon', 'has"quote' ),
            array( "has\nnewline", 'plain' ),
            array( 'unicode: ção', 'plain' ),
        );

        $h = $this->tmp_handle();
        Csv::writer( $h )->rows( $rows );
        rewind( $h );
        $r = Csv::reader( $h );

        $this->assertSame( $rows[0], $r->header() );
        $this->assertSame( array_slice( $rows, 1 ), $r->all() );
    }

    // ==================================================================
    // Reader_from_string ownership
    // ==================================================================

    public function test_reader_from_string_close_idempotent(): void {
        $r = Csv::reader_from_string( "h1;h2\na;b\n" );
        $r->all();
        $r->close();
        $r->close();
        $this->assertTrue( true );
    }
}
