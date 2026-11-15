<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\TemplateFields;

/**
 * Fields a template declares and each form fills (#2581).
 *
 * @covers \FreeFormCertificate\Core\TemplateFields
 */
class TemplateFieldsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' ) )->format( $format )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<int, array{key: string, label: string, type: string, required: bool, default: string}>
	 */
	private function fields(): array {
		return TemplateFields::sanitize(
			array(
				array( 'key' => 'local', 'label' => 'Place', 'required' => '1', 'default' => '' ),
				array( 'key' => 'acao', 'label' => 'Action', 'type' => 'textarea', 'default' => 'took part' ),
				array( 'key' => 'dia', 'label' => 'Day', 'type' => 'date' ),
			)
		);
	}

	public function test_a_declaration_keeps_usable_rows_and_their_first_key(): void {
		$fields = TemplateFields::sanitize(
			array(
				array( 'key' => 'Local-Name', 'label' => '<b>Place</b>', 'type' => 'nonsense', 'required' => '1' ),
				array( 'key' => 'local_name', 'label' => 'Duplicate' ),
				array( 'key' => '', 'label' => 'No key' ),
				'not a row',
				array( 'key' => 'dia', 'type' => 'date', 'default' => '2025-02-30' ),
			)
		);
		$this->assertSame(
			array(
				array( 'key' => 'local_name', 'label' => 'Place', 'type' => 'text', 'required' => true, 'default' => '' ),
				array( 'key' => 'dia', 'label' => 'dia', 'type' => 'date', 'required' => false, 'default' => '' ),
			),
			$fields,
			'hyphen to underscore, unknown type to text, label falls back to the key, an impossible date default is dropped'
		);
	}

	public function test_a_declaration_is_capped(): void {
		$rows = array();
		for ( $i = 0; $i < TemplateFields::MAX_FIELDS + 5; $i++ ) {
			$rows[] = array( 'key' => 'f' . $i );
		}
		$this->assertCount( TemplateFields::MAX_FIELDS, TemplateFields::sanitize( $rows ) );
	}

	public function test_tokens_print_the_value_or_the_default_by_type(): void {
		$layout = '<p>{{model:local}} | {{model:acao}} | {{model:dia}} | {{model:dia:format=Y}} | {{model:outro}} | {{name}}</p>';
		$this->assertSame(
			'<p>Sala &lt;1&gt; | linha 1<br>' . "\n" . 'linha 2 | 19/12/2025 | 2025 | {{model:outro}} | {{name}}</p>',
			TemplateFields::replace_tokens( $layout, $this->fields(), array( 'local' => 'Sala <1>', 'acao' => "linha 1\nlinha 2", 'dia' => '2025-12-19' ) ),
			'escaped, line breaks kept in long text, a date in the plugin or the given format; an undeclared key and the form fields stay'
		);
		$this->assertSame(
			'<p> | took part | </p>',
			TemplateFields::replace_tokens( '<p>{{model:local}} | {{model:acao}} | {{model:dia}}</p>', $this->fields(), array( 'acao' => '  ' ) ),
			'an empty value prints the default'
		);
	}

	public function test_values_are_sanitised_by_their_field_and_unknown_keys_dropped(): void {
		$this->assertSame(
			array( 'local' => 'Sala', 'acao' => 'feito', 'dia' => '' ),
			TemplateFields::sanitize_values( $this->fields(), array( 'local' => ' <i>Sala</i> ', 'acao' => 'feito', 'dia' => '31/12/2025', 'extra' => 'x' ) )
		);
	}

	public function test_missing_required_names_fields_without_a_value_or_default(): void {
		$this->assertSame( array( 'Place' ), TemplateFields::missing_required( $this->fields(), array() ) );
		$this->assertSame( array(), TemplateFields::missing_required( $this->fields(), array( 'local' => 'Sala' ) ) );
	}

	public function test_used_keys_lists_each_key_once(): void {
		$this->assertSame( array( 'local', 'dia' ), TemplateFields::used_keys( '{{model:local}} {{model:dia:format=Y}} {{model:local}} {{local}}' ) );
	}
}
