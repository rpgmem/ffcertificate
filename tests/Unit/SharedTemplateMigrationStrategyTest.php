<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\CertificateSigners;
use FreeFormCertificate\Core\CertificateTemplateLink;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Migrations\Strategies\SharedTemplateMigrationStrategy;

/**
 * Forms carrying copies of one declaration move onto a shared template only
 * when the certificate prints the same text (#2583). The layouts follow the
 * shapes the production export measured: the attendance declaration, a typed
 * or tokenised event date, the place and the action in one sentence, the
 * signer block, whitespace and character references as each form typed them.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\SharedTemplateMigrationStrategy
 */
class SharedTemplateMigrationStrategyTest extends TestCase {

	private const TEMPLATE_ID = 900;

	/** @var array<int, array<string, mixed>> Post meta per id. */
	private array $meta = array();

	/** @var array<int, \WP_Post> */
	private array $posts = array();

	/** @var array<string, mixed> Options. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->alias( static fn( $c ) => preg_match( '/^#[0-9a-f]{6}$/i', (string) $c ) ? $c : null );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $v ) ) ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => strip_tags( (string) $v ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $part = -1 ) => parse_url( $url, $part ) );
		Functions\when( 'wp_get_attachment_url' )->alias( static fn( $id ) => 'https://example.test/wp-content/uploads/sig-' . $id . '.png' );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		Functions\when( 'get_the_title' )->alias( static fn( $id ) => self::TEMPLATE_ID === $id ? 'Attendance declaration' : 'Form ' . $id );
		Functions\when( 'get_post' )->alias( fn( $id ) => $this->posts[ $id ] ?? null );
		Functions\when( 'get_posts' )->alias(
			function ( $args ) {
				$type = $args['post_type'] ?? '';
				return array_values( array_filter( array_keys( $this->meta ), fn( $id ) => ( isset( $this->posts[ $id ] ) ? $this->posts[ $id ]->post_type : 'ffc_form' ) === $type ) );
			}
		);
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key = '', $single = false ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		$maria = (object) array( 'ID' => 7, 'display_name' => 'Maria Souza', 'user_email' => 'maria@example.test', 'user_login' => 'maria' );
		Functions\when( 'get_userdata' )->alias( static fn( $id ) => 7 === $id ? $maria : false );
		$rf = Encryption::encrypt( '5181780' );
		Functions\when( 'get_user_meta' )->alias( static fn( $id, $key = '', $single = false ) => 7 === $id && CertificateSigners::RF_META_KEY === $key ? $rf : '' );

		$this->shared_template();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The shared declaration an administrator writes from one form's copy.
	 */
	private function shared_template( string $html = '' ): void {
		$post              = new \WP_Post( (object) array( 'ID' => self::TEMPLATE_ID ) );
		$post->post_type   = CertificateTemplateLink::TEMPLATE_POST_TYPE;
		$post->post_status = 'publish';
		$this->posts[ self::TEMPLATE_ID ] = $post;
		$this->meta[ self::TEMPLATE_ID ]  = array(
			CertificateTemplateLink::TEMPLATE_META_HTML     => '' !== $html ? $html : $this->layout( '{{schedule:date:format=d/m/Y}}', '{{model:place}}', '{{model:action}}', '{{signer:signature}}', '{{signer:name}}', '{{signer:rf}}', '{{signer:role}}' ),
			CertificateTemplateLink::TEMPLATE_META_BG_IMAGE => '',
			CertificateTemplateLink::TEMPLATE_META_BG_COLOR => '#ffffff',
			CertificateTemplateLink::META_FIELDS            => array(
				array( 'key' => 'place', 'label' => 'Place' ),
				array( 'key' => 'action', 'label' => 'Action', 'type' => 'textarea' ),
			),
		);
	}

	/**
	 * The declaration, with what varies between the forms as arguments.
	 */
	private function layout( string $date, string $place, string $action, string $signature, string $name, string $rf, string $role, string $indent = "\n    " ): string {
		return '<div style="padding: 30px 80px;font-family: Arial, sans-serif">' . $indent
			. '<p style="font-size: 20pt;text-align: center"><strong>DECLARAÇÃO DE PRESENÇA</strong></p>' . $indent
			. '<div style="font-size: 14pt;text-align: justify">' . $indent
			. '    Declaramos para todos os devidos fins que <strong>{{name}}</strong>, portador(a) do RF: <strong>{{cpf_rf}}</strong>, compareceu no dia ' . $date . ', das {{schedule}}, ' . $place . ', ' . $action . $indent
			. '</div>' . $indent
			. '<table style="width: 100%;border: none"><tr><td style="width: 50%"></td><td style="width: 50%;text-align: right">' . $indent
			. '    <img src="' . $signature . '" alt="Assinatura" style="height: 110px;width: auto"><br>' . $indent
			. '    <div style="font-size: 11pt">Atenciosamente,<br><strong>' . $name . '</strong><br>R.F. ' . $rf . '<br>' . $role . '</div>' . $indent
			. '</td></tr></table>' . $indent
			. '<div style="font-size: 9pt">{{qr_code:size=50:margin=0}} Gerado em: {{submission_date}}. Código: <strong>{{auth_code}}</strong></div>' . "\n"
			. '</div>';
	}

	private function form( int $id, string $layout, string $event_date = '2025-12-19', array $config = array() ): void {
		$this->meta[ $id ] = array(
			'_ffc_form_config'     => array_merge( array( 'pdf_layout' => $layout, 'bg_color' => '#ffffff' ), $config ),
			'_ffc_geofence_config' => array( 'class_date_start' => $event_date ),
		);
	}

	private function copy( string $place, string $action, array $over = array() ): string {
		$v = array_merge(
			array(
				'date'      => '19/12/2025',
				'signature' => 'https://example.test/wp-content/uploads/sig-12.png',
				'name'      => 'Maria Souza',
				'rf'        => '518.178-0',
				'role'      => 'DIVISÃO PEDAGÓGICA - DRE EXEMPLO',
				'indent'    => "\n        ",
			),
			$over
		);
		return $this->layout( $v['date'], $place, $action, $v['signature'], $v['name'], $v['rf'], $v['role'], $v['indent'] );
	}

	private function add_signer( int $signature_id = 12 ): void {
		$this->options[ CertificateSigners::OPTION ] = array(
			array( 'id' => 3, 'user_id' => 7, 'role' => 'DIVISÃO PEDAGÓGICA - DRE EXEMPLO', 'signature_id' => $signature_id, 'active' => true ),
		);
	}

	private function strategy(): SharedTemplateMigrationStrategy {
		return new SharedTemplateMigrationStrategy();
	}

	public function test_a_copy_switches_to_the_shared_template_with_what_it_typed(): void {
		$this->add_signer();
		$this->form( 10, $this->copy( 'na Sala de Formação da DRE Exemplo', 'na Formação para os Professores de Matemática, que atuam nas unidades desta DRE.' ) );

		$status = $this->strategy()->calculate_status( 'shared_template_from_layout', array() );
		$this->assertSame( 1, $status['pending'] );
		$this->assertStringContainsString( 'Place: “na Sala de Formação da DRE Exemplo”', $status['preview'][0] );
		$this->assertStringContainsString( 'signer Maria Souza', $status['preview'][0] );

		$result = $this->strategy()->execute( 'shared_template_from_layout', array() );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( self::TEMPLATE_ID, $this->meta[10][ CertificateTemplateLink::META_TEMPLATE_ID ] );
		$this->assertSame( 3, $this->meta[10][ CertificateSigners::FORM_META ] );
		$this->assertSame(
			array(
				'place'  => 'na Sala de Formação da DRE Exemplo',
				'action' => 'na Formação para os Professores de Matemática, que atuam nas unidades desta DRE.',
			),
			$this->meta[10][ CertificateTemplateLink::META_VALUES ],
			'the place ends at the first comma after it; the identity check is what proves the split'
		);
		$this->assertStringContainsString( '19/12/2025', $this->meta[10]['_ffc_form_config']['pdf_layout'], 'the form keeps its own copy' );

		$this->assertSame( 0, $this->strategy()->execute( 'shared_template_from_layout', array() )['processed'], 'a second run changes nothing' );
		$this->assertSame( 0, $this->strategy()->calculate_status( 'shared_template_from_layout', array() )['pending'] );
	}

	public function test_a_layout_already_on_the_event_date_token_and_character_references_still_match(): void {
		$this->add_signer();
		$this->form( 11, $this->copy( 'no Auditório do CEU Exemplo', 'no Encontro Formativo &mdash; Estágio, que atuam nas unidades da DRE.', array( 'date' => '{{schedule:date:format=d/m/Y}}', 'indent' => ' ' ) ) );
		$this->form( 12, $this->copy( 'no Instituto Federal', 'no &quot;Encontro Formativo&quot;, para Coordenadores &amp; Assistentes.', array( 'signature' => '/wp-content/uploads/sig-12.png' ) ) );

		$this->strategy()->execute( 'shared_template_from_layout', array() );
		$this->assertSame( 'no Encontro Formativo — Estágio, que atuam nas unidades da DRE.', $this->meta[11][ CertificateTemplateLink::META_VALUES ]['action'] );
		$this->assertSame( 'no "Encontro Formativo", para Coordenadores & Assistentes.', $this->meta[12][ CertificateTemplateLink::META_VALUES ]['action'], 'a root-relative signature is the same image' );
	}

	public function test_a_form_whose_signer_is_not_listed_waits_and_returns_when_the_signer_is_added(): void {
		$this->form( 10, $this->copy( 'na DRE Exemplo', 'na Formação de Supervisores.' ) );

		$this->strategy()->execute( 'shared_template_from_layout', array() );
		$status = $this->strategy()->calculate_status( 'shared_template_from_layout', array() );
		$this->assertSame( 0, $status['pending'] );
		$this->assertStringContainsString( 'no signer on the list matches “Maria Souza”', $status['failures'][0] );
		$this->assertArrayNotHasKey( CertificateTemplateLink::META_TEMPLATE_ID, $this->meta[10] );

		$this->add_signer();
		$this->assertSame( 1, $this->strategy()->calculate_status( 'shared_template_from_layout', array() )['pending'], 'a new signer brings the form back' );
		$this->assertSame( 1, $this->strategy()->execute( 'shared_template_from_layout', array() )['processed'] );
	}

	public function test_forms_that_would_print_differently_are_left_alone_with_the_reason(): void {
		$this->add_signer();
		$this->form( 20, str_replace( 'DECLARAÇÃO DE PRESENÇA', 'CERTIFICADO DE PARTICIPAÇÃO', $this->copy( 'na DRE', 'na Formação.' ) ) );
		$this->form( 21, $this->copy( 'na DRE', 'na Formação.' ), '' );
		$this->form( 22, $this->copy( 'na DRE', 'na Formação.' ), '2025-12-19', array( 'bg_image' => 'https://example.test/own-background.png' ) );
		$this->form( 23, $this->copy( 'na DRE', 'na Formação.', array( 'signature' => 'https://example.test/wp-content/uploads/old-signature.png' ) ) );
		$this->form( 24, $this->copy( 'na DRE', 'na Formação.', array( 'role' => 'Diretor Regional<br>DRE Exemplo' ) ) );
		$this->form( 25, $this->copy( 'na DRE', 'na Formação.', array( 'role' => 'DIRETOR SUBSTITUTO' ) ) );
		$this->form( 26, $this->copy( 'na DRE', 'na Formação.', array( 'date' => '18/12/2025' ) ) );

		$this->strategy()->execute( 'shared_template_from_layout', array() );
		$failures = implode( "\n", $this->strategy()->calculate_status( 'shared_template_from_layout', array() )['failures'] );

		$this->assertStringContainsString( 'Form 20 (#20) — the layout differs from “Attendance declaration” at “DECLARAÇÃO DE PRESENÇA', $failures );
		$this->assertStringContainsString( 'Form 21 (#21) — the form has no event date; run the event date card first', $failures );
		$this->assertStringContainsString( 'Form 22 (#22) — the background differs', $failures );
		$this->assertStringContainsString( 'Form 23 (#23) — the signer\'s signature image is not the one the layout shows (https://example.test/wp-content/uploads/old-signature.png)', $failures );
		$this->assertStringContainsString( 'Form 24 (#24) — the text in place of a token holds markup', $failures );
		$this->assertStringContainsString( 'Form 25 (#25) — the signer\'s role is not the one the layout types (“DIRETOR SUBSTITUTO”)', $failures );
		$this->assertStringContainsString( 'Form 26 (#26) — the layout differs', $failures, 'a typed date other than the event date is not the token' );
		foreach ( range( 20, 26 ) as $id ) {
			$this->assertArrayNotHasKey( CertificateTemplateLink::META_TEMPLATE_ID, $this->meta[ $id ], "form $id stays on its copy" );
		}
	}

	public function test_typed_markup_never_compares_equal_to_a_tag(): void {
		$this->add_signer();
		$this->shared_template( '<p><b>{{model:place}}</b></p>' );
		$this->form( 30, '<p>&lt;b&gt;na DRE&lt;/b&gt;</p>' );

		$plan = SharedTemplateMigrationStrategy::plan( 30, SharedTemplateMigrationStrategy::candidate_templates() );
		$this->assertSame( 'no_match', $plan['outcome'] );
	}

	public function test_a_value_that_sanitising_would_change_is_not_switched(): void {
		$this->shared_template( '<p>{{model:place}}</p>' );
		$this->form( 31, '<p>na DRE    &lt;script&gt;</p>' );

		$plan = SharedTemplateMigrationStrategy::plan( 31, SharedTemplateMigrationStrategy::candidate_templates() );
		$this->assertSame( 'output_differs', $plan['outcome'], 'the stored value would print without what sanitising dropped' );
	}

	public function test_without_a_shared_template_the_card_says_how_to_write_one(): void {
		$this->shared_template( '<p>{{name}}, a plain template with no field</p>' );
		$this->form( 40, $this->copy( 'na DRE', 'na Formação.' ) );

		$status = $this->strategy()->calculate_status( 'shared_template_from_layout', array() );
		$this->assertSame( 0, $status['total'] );
		$this->assertStringContainsString( 'Write the shared template first', $status['preview'][0] );
		$this->assertSame( 0, $this->strategy()->execute( 'shared_template_from_layout', array() )['processed'] );
	}

	public function test_a_form_already_referencing_a_template_is_not_pending(): void {
		$this->add_signer();
		$this->form( 41, $this->copy( 'na DRE', 'na Formação.' ) );
		$this->meta[41][ CertificateTemplateLink::META_TEMPLATE_ID ] = '77';

		$this->assertSame( 0, $this->strategy()->calculate_status( 'shared_template_from_layout', array() )['pending'] );
	}

	public function test_a_form_naming_another_signer_keeps_it(): void {
		$this->add_signer();
		$this->options[ CertificateSigners::OPTION ][] = array( 'id' => 4, 'user_id' => 7, 'role' => 'Outro', 'signature_id' => 12, 'active' => true );
		$this->form( 42, $this->copy( 'na DRE', 'na Formação.' ) );
		$this->meta[42][ CertificateSigners::FORM_META ] = '4';

		$plan = SharedTemplateMigrationStrategy::plan( 42, SharedTemplateMigrationStrategy::candidate_templates() );
		$this->assertSame( 'role_differs', $plan['outcome'], 'its own signer is tried first, and it prints another role' );
	}
}
