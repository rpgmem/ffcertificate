<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\FormEditorSaveValidator;
use PHPUnit\Framework\TestCase;

/**
 * The fields the required certificate tags call for (#1734): which tags ask
 * for a field, and which a form is missing.
 *
 * @covers \FreeFormCertificate\Admin\FormEditorSaveValidator
 */
class RequiredCertificateFieldsTest extends TestCase {

	/**
	 * The stored `required_certificate_tags`.
	 *
	 * @var string
	 */
	private string $tags = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'ffc_settings' === $key ? array( 'required_certificate_tags' => $this->tags ) : $default
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_default_tags_call_for_name_and_cpf_rf_only(): void {
		$this->assertSame( array( 'name', 'cpf_rf' ), FormEditorSaveValidator::required_field_names() );
	}

	public function test_generated_tags_never_call_for_a_field(): void {
		$this->tags = "{{auth_code}}\n{{print_date}}\n{{qr_code:size=120}}\n{{schedule}}\n{{course}}";
		$this->assertSame( array( 'course' ), FormEditorSaveValidator::required_field_names() );
	}

	public function test_nome_satisfies_name(): void {
		$this->assertTrue( FormEditorSaveValidator::is_required_field( 'nome' ) );
		$this->assertTrue( FormEditorSaveValidator::is_required_field( 'cpf_rf' ) );
		$this->assertFalse( FormEditorSaveValidator::is_required_field( 'curso' ) );
		$this->assertFalse( FormEditorSaveValidator::is_required_field( '' ) );
	}

	public function test_missing_fields_are_reported_in_required_order(): void {
		$validator = new FormEditorSaveValidator();
		$this->assertSame(
			array( '{{name}}', '{{cpf_rf}}' ),
			$validator->missing_required_fields( array( array( 'type' => 'email', 'name' => 'email' ) ) )
		);
		$this->assertSame(
			array(),
			$validator->missing_required_fields(
				array(
					array( 'type' => 'text', 'name' => 'nome' ),
					array( 'type' => 'text', 'name' => 'cpf_rf' ),
				)
			)
		);
	}

	public function test_an_info_block_named_like_a_tag_does_not_count(): void {
		$validator = new FormEditorSaveValidator();
		$this->assertSame(
			array( '{{name}}' ),
			$validator->missing_required_fields(
				array(
					array( 'type' => 'info', 'name' => 'name' ),
					array( 'type' => 'text', 'name' => 'cpf_rf' ),
				)
			)
		);
	}
}
