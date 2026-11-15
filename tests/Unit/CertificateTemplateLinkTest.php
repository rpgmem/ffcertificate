<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertTemplateCpt;
use FreeFormCertificate\Core\CertificateTemplateLink;
use FreeFormCertificate\Core\FormBackground;

/**
 * A form that references a certificate template (#2581).
 *
 * @covers \FreeFormCertificate\Core\CertificateTemplateLink
 * @covers \FreeFormCertificate\Core\FormBackground
 */
class CertificateTemplateLinkTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta = array();

	/** @var array<int, \WP_Post> */
	private array $posts = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key = '', $single = false ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'get_post' )->alias( fn( $id ) => $this->posts[ $id ] ?? null );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->alias( static fn( $c ) => preg_match( '/^#[0-9a-f]{6}$/i', (string) $c ) ? $c : null );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function template( int $id, string $status = 'publish', string $kind = '' ): void {
		$post              = new \WP_Post( (object) array( 'ID' => $id ) );
		$post->post_type   = CertificateTemplateLink::TEMPLATE_POST_TYPE;
		$post->post_status = $status;
		$this->posts[ $id ] = $post;
		$this->meta[ $id ]  = array(
			CertificateTemplateLink::TEMPLATE_META_HTML     => '<p>{{model:local}}, {{name}}</p>',
			CertificateTemplateLink::TEMPLATE_META_BG_IMAGE => 'https://example.test/model.png',
			CertificateTemplateLink::TEMPLATE_META_BG_COLOR => '#112233',
			CertificateTemplateLink::TEMPLATE_META_KIND     => $kind,
			CertificateTemplateLink::META_FIELDS            => array( array( 'key' => 'local', 'label' => 'Place', 'default' => 'Sede' ) ),
		);
	}

	private function form( int $id, int $template_id, array $values = array() ): void {
		$this->meta[ $id ] = array(
			'_ffc_form_config'                         => array( 'pdf_layout' => '<p>own copy</p>', 'bg_image' => 'https://example.test/own.png', 'bg_color' => '#ffffff', 'orientation' => 'portrait' ),
			CertificateTemplateLink::META_TEMPLATE_ID => $template_id > 0 ? (string) $template_id : '',
			CertificateTemplateLink::META_VALUES      => $values,
		);
	}

	public function test_the_spellings_repeated_in_core_match_the_template_post_type(): void {
		$this->assertSame( CertTemplateCpt::POST_TYPE, CertificateTemplateLink::TEMPLATE_POST_TYPE );
		$this->assertSame( CertTemplateCpt::META_HTML, CertificateTemplateLink::TEMPLATE_META_HTML );
		$this->assertSame( CertTemplateCpt::META_BG_IMAGE, CertificateTemplateLink::TEMPLATE_META_BG_IMAGE );
		$this->assertSame( CertTemplateCpt::META_BG_COLOR, CertificateTemplateLink::TEMPLATE_META_BG_COLOR );
		$this->assertSame( CertTemplateCpt::META_KIND, CertificateTemplateLink::TEMPLATE_META_KIND );
		$this->assertSame( CertTemplateCpt::KIND_CERTIFICATE, 'certificate' );
	}

	public function test_a_referencing_form_prints_the_template_with_its_values_and_background(): void {
		$this->template( 50 );
		$this->form( 7, 50, array( 'local' => 'Sala 2' ) );

		$config = CertificateTemplateLink::form_config( 7 );
		$this->assertSame( '<p>Sala 2, {{name}}</p>', $config['pdf_layout'], 'the form fields are left to the renderer' );
		$this->assertSame( 'https://example.test/model.png', $config['bg_image'] );
		$this->assertSame( '#112233', $config['bg_color'] );
		$this->assertSame( 'portrait', $config['orientation'], 'the rest of the form config is kept' );
		$this->assertSame( 'https://example.test/model.png', FormBackground::url( 7 ) );
		$this->assertSame( '#112233', FormBackground::color( 7 ) );
	}

	public function test_an_empty_value_prints_the_template_default(): void {
		$this->template( 50 );
		$this->form( 7, 50 );
		$this->assertSame( '<p>Sede, {{name}}</p>', CertificateTemplateLink::form_config( 7 )['pdf_layout'] );
	}

	public function test_a_form_without_a_reference_prints_its_own_copy(): void {
		$this->form( 7, 0 );
		$this->assertSame( '<p>own copy</p>', CertificateTemplateLink::form_config( 7 )['pdf_layout'] );
		$this->assertSame( 'https://example.test/own.png', FormBackground::url( 7 ) );
		$this->assertSame( 0, CertificateTemplateLink::template_id( 7 ) );
	}

	/**
	 * @dataProvider unusable_templates
	 */
	public function test_an_unusable_template_falls_back_to_the_own_copy( string $status, string $kind, bool $exists ): void {
		if ( $exists ) {
			$this->template( 50, $status, $kind );
		}
		$this->form( 7, 50 );
		$this->assertSame( 0, CertificateTemplateLink::template_id( 7 ) );
		$this->assertSame( 50, CertificateTemplateLink::stored_template_id( 7 ), 'the editor still knows what it referenced' );
		$this->assertSame( '<p>own copy</p>', CertificateTemplateLink::form_config( 7 )['pdf_layout'] );
	}

	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public function unusable_templates(): array {
		return array(
			'binned'      => array( 'trash', '', true ),
			'auto-draft'  => array( 'auto-draft', '', true ),
			'a receipt'   => array( 'publish', 'appointment_receipt', true ),
			'deleted'     => array( 'publish', '', false ),
		);
	}

	public function test_a_post_of_another_type_is_not_a_template(): void {
		$this->template( 50 );
		$this->posts[50]->post_type = 'ffc_form';
		$this->assertFalse( CertificateTemplateLink::is_usable_template( 50 ) );
	}

	public function test_values_keep_only_string_keys_and_scalars(): void {
		$this->form( 7, 0, array( 'local' => 'Sala', 3 => 'x', 'list' => array( 'a' ), 'n' => 5 ) );
		$this->assertSame( array( 'local' => 'Sala', 'n' => '5' ), CertificateTemplateLink::values( 7 ) );
	}

	public function test_forms_using_queries_by_the_reference_meta(): void {
		Functions\expect( 'get_posts' )->once()->andReturnUsing(
			function ( array $args ) {
				$this->assertSame( 'ffc_form', $args['post_type'] );
				$this->assertSame( CertificateTemplateLink::META_TEMPLATE_ID, $args['meta_key'] );
				$this->assertSame( '50', $args['meta_value'] );
				return array( '7', '9' );
			}
		);
		$this->assertSame( array( 7, 9 ), CertificateTemplateLink::forms_using( 50 ) );
		$this->assertSame( array(), CertificateTemplateLink::forms_using( 0 ) );
	}
}
