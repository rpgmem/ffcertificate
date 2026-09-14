<?php
/**
 * URL Shortener QR Handler
 *
 * Generates QR Codes for short URLs and handles download requests (PNG/SVG).
 * Reuses the existing QRCodeGenerator for PNG output.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since 5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\AjaxTrait;
use FreeFormCertificate\Generators\QRCodeGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for url shortener qr operations.
 */
class UrlShortenerQrHandler {

	use AjaxTrait;

	/**
	 * O UNICO tamanho que o cache de QR serve.
	 *
	 * O cache existe por causa de um chamador so: a metabox do editor de post,
	 * que redesenha o mesmo QR a cada abertura da tela. REST e download sao
	 * acoes eventuais do operador, sobre uma URL de cada vez -- nao ha repeticao
	 * para amortizar, e cachear os 901 tamanhos que o REST aceita (100 a 1000)
	 * trocaria um custo de CPU que ninguem mediu por um de armazenamento real.
	 *
	 * @var int
	 */
	public const CACHE_SIZE = 200;

	/**
	 * Versao do envelope gravado na coluna `qr_cache`.
	 *
	 * @var int
	 */
	private const CACHE_FORMAT = 1;

	/*
	 * DOIS CACHES DE QR COM O MESMO NOME POPULAR -- nao os confunda (#1233).
	 *
	 * 1. ESTE: `ffc_short_urls.qr_cache`, o QR da URL curta. Sem toggle --
	 *    grava sempre que recebe um `short_code` no tamanho canonico. Nao ha
	 *    botao no admin que o limpe: ele se auto-corrige, porque um envelope
	 *    que nao casa com o tamanho pedido e descartado na leitura.
	 *
	 * 2. `ffc_submissions.qr_code_cache`, o QR do certificado, em
	 *    {@see \FreeFormCertificate\Generators\QRCodeGenerator}. Indexado por
	 *    `submission_id`, governado pelo toggle `qr_cache_enabled`
	 *    (Configuracoes -> Cache), que esta DESLIGADO por decisao.
	 *
	 * O botao "Clear All QR Code Cache" daquela aba chama
	 * `SubmissionRepository::clearQrCodeCache()` e atinge somente o (2).
	 */

	/**
	 * Description.
	 *
	 * @var UrlShortenerService
	 */
	private UrlShortenerService $service;

	/**
	 * Constructor.
	 *
	 * @param UrlShortenerService $service Service.
	 */
	public function __construct( UrlShortenerService $service ) {
		$this->service = $service;
	}

	/**
	 * Register AJAX hooks.
	 */
	public function init(): void {
		add_action( 'wp_ajax_ffc_download_qr_png', array( $this, 'handle_download_png' ) );
		add_action( 'wp_ajax_ffc_download_qr_svg', array( $this, 'handle_download_svg' ) );
	}

	/**
	 * Generate a QR Code as base64 PNG, with database caching.
	 *
	 * O cache serve EXCLUSIVAMENTE {@see self::CACHE_SIZE}: qualquer outro
	 * tamanho passa ao largo dele, na leitura e na gravacao. Ate o #1233 a
	 * chave era so o `short_code`, ignorando o tamanho pedido -- entao uma
	 * chamada REST com `size=1000` lia o PNG de 200px que a metabox havia
	 * gravado, e, com o cache vazio, gravava 1000px la para a metabox renderizar
	 * num espaco de 200. Corrupcao de conteudo entre chamadores, silenciosa.
	 *
	 * **A ordem da correcao importou.** A leitura obvia do defeito era "dois
	 * chamadores furam o cache, basta passar o `short_code`" -- e era o
	 * contrario: `handle_download_png()` era o unico sitio que nao podia ser
	 * envenenado, justamente por omitir o codigo. Ligar os chamadores antes de
	 * corrigir a chave transformaria o unico sitio sao no terceiro doente.
	 * Com o portao por tamanho isso deixa de depender de quem passa o que: um
	 * chamador que peca 400 nao alcanca o cache nem querendo.
	 *
	 * @param string $url        The URL to encode.
	 * @param int    $size       Image size in pixels.
	 * @param string $short_code Optional short code for cache lookup.
	 * @return string Base64-encoded PNG data.
	 */
	public function generate_qr_base64( string $url, int $size = self::CACHE_SIZE, string $short_code = '' ): string {
		$cacheable = '' !== $short_code && self::CACHE_SIZE === $size;

		if ( $cacheable ) {
			$cached = $this->get_qr_cache( $short_code, $size );
			if ( '' !== $cached ) {
				return $cached;
			}
		}

		$generator = new QRCodeGenerator();

		$base64 = $generator->generate(
			$url,
			array(
				'size'        => $size,
				'margin'      => 2,
				'error_level' => 'M',
			)
		);

		// Persist to cache.
		if ( $cacheable && '' !== $base64 ) {
			$this->set_qr_cache( $short_code, $size, $base64 );
		}

		return $base64;
	}

	/**
	 * Retrieve cached QR code from the ffc_short_urls table.
	 *
	 * Devolve '' -- isto e, MISS -- para tudo que nao seja um envelope desta
	 * versao declarando exatamente o tamanho pedido. Isso cobre o caminho de
	 * upgrade sem migracao nenhuma: uma linha gravada no esquema antigo e
	 * base64 puro, que nunca decodifica para um ARRAY, entao e descartada e
	 * regravada no formato novo na primeira leitura.
	 *
	 * "Nunca decodifica para um array" e mais forte do que parece, porque o
	 * alfabeto do base64 produz JSON VALIDO em alguns casos: um payload so de
	 * digitos decodifica para um numero, e um de quatro caracteres pode ser
	 * literalmente `true`. O que garante o miss nesses casos nao e o
	 * `is_array()` -- e a validacao do envelope logo abaixo, que reprova
	 * qualquer coisa sem `v`, `size` e `png` coerentes. Medido: trocar o
	 * `is_array()` por um `null === $payload` mantem os tres casos como miss.
	 *
	 * O `is_array()` esta aqui como guarda de TIPO, nao de conteudo: sem ele,
	 * `12345['v']` emite "Trying to access array offset on value of type int"
	 * a cada leitura de uma linha antiga. Nao o remova achando que e redundante
	 * -- o que ele evita e o warning, nao o cache errado.
	 *
	 * E tambem o que torna {@see self::CACHE_SIZE} seguro de mudar: as linhas
	 * gravadas sob o valor anterior passam a errar o tamanho e sao descartadas,
	 * em vez de servidas.
	 *
	 * @param string $short_code Short code.
	 * @param int    $size       Tamanho exigido, em pixels.
	 * @return string Base64 data or empty string.
	 */
	private function get_qr_cache( string $short_code, int $size ): string {
		$raw = $this->service->get_repository()->findQrCacheByShortCode( $short_code );
		if ( '' === $raw ) {
			return '';
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return '';
		}

		$version = $payload['v'] ?? null;
		$stored  = $payload['size'] ?? null;
		$png     = $payload['png'] ?? null;

		if ( self::CACHE_FORMAT !== $version || $size !== $stored || ! is_string( $png ) || '' === $png ) {
			return '';
		}

		return $png;
	}

	/**
	 * Store QR code cache in the ffc_short_urls table.
	 *
	 * Grava o tamanho JUNTO do PNG. A alternativa -- uma coluna
	 * `qr_cache_size` -- diria a mesma coisa ao custo de uma mudanca de schema
	 * que atravessa o `SchemaAgreementTest`, o portao de idempotencia do
	 * `dbDelta` e o manifesto do `uninstall.php`, sem que nada alem deste cache
	 * leia o valor.
	 *
	 * @param string $short_code Short code.
	 * @param int    $size       Tamanho do PNG, em pixels.
	 * @param string $base64     Base64-encoded PNG.
	 */
	private function set_qr_cache( string $short_code, int $size, string $base64 ): void {
		$payload = wp_json_encode(
			array(
				'v'    => self::CACHE_FORMAT,
				'size' => $size,
				'png'  => $base64,
			)
		);

		if ( ! is_string( $payload ) ) {
			return;
		}

		$this->service->get_repository()->setQrCacheForShortCode( $short_code, $payload );
	}

	/**
	 * Generate a QR Code as SVG string.
	 *
	 * Builds SVG directly from the phpqrcode raw matrix — no PNG
	 * generation, no GD image loading, no pixel-by-pixel scanning.
	 * This eliminates the two most CPU-intensive steps of the old
	 * implementation.
	 *
	 * @param string $url  The URL to encode.
	 * @param int    $size SVG viewBox size.
	 * @return string SVG markup.
	 */
	public function generate_svg( string $url, int $size = 200 ): string {
		// Ensure phpqrcode is loaded.
		if ( ! class_exists( '\\QRcode' ) ) {
			require_once FFC_PLUGIN_DIR . 'libs/phpqrcode/qrlib.php';
		}

		// Get the raw QR matrix directly (no temp files, no GD).
		$matrix = \QRcode::raw( $url, false, QR_ECLEVEL_M );

		if ( empty( $matrix ) ) {
			return '';
		}

		$margin      = 2;
		$matrix_size = count( $matrix );
		$total       = $matrix_size + $margin * 2;
		$module_size = (int) floor( $size / $total );

		if ( $module_size < 1 ) {
			$module_size = 1;
		}

		$svg_size = $module_size * $total;

		$parts   = array();
		$parts[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$parts[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $svg_size . ' ' . $svg_size . '" width="' . $svg_size . '" height="' . $svg_size . '">';
		$parts[] = '<rect width="100%" height="100%" fill="white"/>';

		for ( $y = 0; $y < $matrix_size; $y++ ) {
			$row = $matrix[ $y ];
			for ( $x = 0; $x < $matrix_size; $x++ ) {
				// Each cell is 0 (white) or non-zero (dark module).
				if ( isset( $row[ $x ] ) && $row[ $x ] ) {
					$px      = ( $x + $margin ) * $module_size;
					$py      = ( $y + $margin ) * $module_size;
					$parts[] = '<rect x="' . $px . '" y="' . $py
							. '" width="' . $module_size . '" height="' . $module_size . '" fill="black"/>';
				}
			}
		}

		$parts[] = '</svg>';

		return implode( "\n", $parts );
	}

	/**
	 * Resolve the QR target URL, filename prefix, and short code.
	 *
	 * Always encodes the short URL so that scans are tracked by the
	 * click counter — regardless of whether the request comes from
	 * the post meta box (post_id) or the admin listing (code).
	 *
	 * @return array{url: string, prefix: string, code: string} Target URL, filename prefix and short code.
	 */
	private function resolve_qr_target(): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce verified by the calling method. Cast to int, which sanitises; unslashing a value that becomes an int is a no-op.
		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			$record = $this->service->get_repository()->findByPostId( $post_id );
			if ( ! $record ) {
				wp_send_json_error( array( 'message' => __( 'Short URL not found for this post.', 'ffcertificate' ) ) );
			}
			$post = get_post( $post_id );
			$slug = $post ? $post->post_name : (string) $post_id;
			return array(
				'url'    => $this->service->get_short_url( $record['short_code'] ),
				'prefix' => 'qr-' . $slug,
				'code'   => $record['short_code'],
			);
		}

		$code = \FreeFormCertificate\Core\RequestInput::get_post_string( 'code' );
		if ( empty( $code ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid code.', 'ffcertificate' ) ) );
		}

		$record = $this->service->get_repository()->findByShortCode( $code );
		if ( ! $record ) {
			wp_send_json_error( array( 'message' => __( 'Short URL not found.', 'ffcertificate' ) ) );
		}

		return array(
			'url'    => $this->service->get_short_url( $code ),
			'prefix' => 'qr-' . $code,
			'code'   => $code,
		);
	}

	/**
	 * Gate the QR download on the url-shortener *view* tier (#739 §4.2):
	 * admin, the view cap, OR the manage cap. A `manage` role need not also
	 * carry the `view` cap (canView already includes manage), so accepting
	 * either keeps QR download available across the whole url-shortener ladder
	 * instead of the view-only tier. Dies with a JSON error when neither holds.
	 *
	 * @return void
	 */
	private function check_qr_view_permission(): void {
		if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_url_shortener' )
			|| \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_url_shortener' ) ) {
			return;
		}
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ) );
	}

	/**
	 * AJAX: Download QR Code as PNG.
	 */
	public function handle_download_png(): void {
		$this->verify_ajax_nonce( 'ffc_short_url_nonce' );
		$this->check_qr_view_permission();

		$target = $this->resolve_qr_target();
		$base64 = $this->generate_qr_base64( $target['url'], 400 );

		if ( empty( $base64 ) ) {
			wp_send_json_error( array( 'message' => __( 'QR generation failed.', 'ffcertificate' ) ) );
		}

		wp_send_json_success(
			array(
				'data'     => $base64,
				'filename' => $target['prefix'] . '.png',
				'mime'     => 'image/png',
			)
		);
	}

	/**
	 * AJAX: Download QR Code as SVG.
	 */
	public function handle_download_svg(): void {
		$this->verify_ajax_nonce( 'ffc_short_url_nonce' );
		$this->check_qr_view_permission();

		$target = $this->resolve_qr_target();
		$svg    = $this->generate_svg( $target['url'], 400 );

		if ( empty( $svg ) ) {
			wp_send_json_error( array( 'message' => __( 'SVG generation failed.', 'ffcertificate' ) ) );
		}

		wp_send_json_success(
			array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- benign: encoding SVG for client-side download payload.
				'data'     => base64_encode( $svg ),
				'filename' => $target['prefix'] . '.svg',
				'mime'     => 'image/svg+xml',
			)
		);
	}
}
