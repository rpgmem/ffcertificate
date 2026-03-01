<?php
declare(strict_types=1);

/**
 * URL Shortener QR Handler
 *
 * Generates QR Codes for short URLs and handles download requests (PNG/SVG).
 * Reuses the existing QRCodeGenerator for PNG output.
 *
 * @since 5.1.0
 * @package FreeFormCertificate\UrlShortener
 */

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\AjaxTrait;
use FreeFormCertificate\Generators\QRCodeGenerator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UrlShortenerQrHandler {

    use AjaxTrait;

    /** @var UrlShortenerService */
    private UrlShortenerService $service;

    public function __construct( UrlShortenerService $service ) {
        $this->service = $service;
    }

    /**
     * Register AJAX hooks.
     */
    public function init(): void {
        add_action( 'wp_ajax_ffc_download_qr_png', [ $this, 'handle_download_png' ] );
        add_action( 'wp_ajax_ffc_download_qr_svg', [ $this, 'handle_download_svg' ] );
    }

    /**
     * Generate a QR Code as base64 PNG.
     *
     * @param string $url  The URL to encode.
     * @param int    $size Image size in pixels.
     * @return string Base64-encoded PNG data.
     */
    public function generate_qr_base64( string $url, int $size = 200 ): string {
        $generator = new QRCodeGenerator();

        return $generator->generate( $url, [
            'size'        => $size,
            'margin'      => 2,
            'error_level' => 'M',
        ] );
    }

    /**
     * Generate a QR Code as SVG string.
     *
     * Uses phpqrcode to get the matrix, then renders as SVG.
     *
     * @param string $url  The URL to encode.
     * @param int    $size SVG viewBox size.
     * @return string SVG markup.
     */
    public function generate_svg( string $url, int $size = 200 ): string {
        // Ensure phpqrcode is loaded
        if ( ! class_exists( '\\QRcode' ) ) {
            require_once FFC_PLUGIN_DIR . 'libs/phpqrcode/qrlib.php';
        }

        // Get the QR code matrix as text - prefer wp_tempnam for hosting compatibility
        $temp_file = function_exists( 'wp_tempnam' )
            ? wp_tempnam( 'ffc_qr_svg_' )
            : tempnam( sys_get_temp_dir(), 'ffc_qr_svg_' );

        if ( ! $temp_file ) {
            return '';
        }

        \QRcode::png( $url, $temp_file, QR_ECLEVEL_M, 1, 0 );

        // Read the PNG and get dimensions
        if ( ! file_exists( $temp_file ) || filesize( $temp_file ) === 0 ) {
            if ( file_exists( $temp_file ) ) {
                wp_delete_file( $temp_file );
            }
            return '';
        }

        $image = @imagecreatefrompng( $temp_file );
        wp_delete_file( $temp_file );

        if ( ! $image ) {
            return '';
        }

        $width  = imagesx( $image );
        $height = imagesy( $image );

        $module_size = (int) floor( $size / $width );
        $svg_size    = $module_size * $width;

        $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $svg_size . ' ' . $svg_size . '" width="' . $svg_size . '" height="' . $svg_size . '">' . "\n";
        $svg .= '<rect width="100%" height="100%" fill="white"/>' . "\n";

        for ( $y = 0; $y < $height; $y++ ) {
            for ( $x = 0; $x < $width; $x++ ) {
                $rgb   = imagecolorat( $image, $x, $y );
                $red   = ( $rgb >> 16 ) & 0xFF;
                $green = ( $rgb >> 8 ) & 0xFF;
                $blue  = $rgb & 0xFF;

                // Dark module (black pixel)
                if ( $red < 128 && $green < 128 && $blue < 128 ) {
                    $svg .= '<rect x="' . ( $x * $module_size ) . '" y="' . ( $y * $module_size )
                          . '" width="' . $module_size . '" height="' . $module_size . '" fill="black"/>' . "\n";
                }
            }
        }

        $svg .= '</svg>';

        imagedestroy( $image );

        return $svg;
    }

    /**
     * Resolve the QR target URL and filename prefix.
     *
     * Always encodes the short URL so that scans are tracked by the
     * click counter — regardless of whether the request comes from
     * the post meta box (post_id) or the admin listing (code).
     *
     * @return array{url: string, prefix: string} Target URL and filename prefix.
     */
    private function resolve_qr_target(): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling method.
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( $post_id > 0 ) {
            $record = $this->service->get_repository()->findByPostId( $post_id );
            if ( ! $record ) {
                wp_send_json_error( [ 'message' => __( 'Short URL not found for this post.', 'ffcertificate' ) ] );
            }
            $post = get_post( $post_id );
            $slug = $post ? $post->post_name : (string) $post_id;
            return [ 'url' => $this->service->get_short_url( $record['short_code'] ), 'prefix' => 'qr-' . $slug ];
        }

        $code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
        if ( empty( $code ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid code.', 'ffcertificate' ) ] );
        }

        $record = $this->service->get_repository()->findByShortCode( $code );
        if ( ! $record ) {
            wp_send_json_error( [ 'message' => __( 'Short URL not found.', 'ffcertificate' ) ] );
        }

        return [ 'url' => $this->service->get_short_url( $code ), 'prefix' => 'qr-' . $code ];
    }

    /**
     * AJAX: Download QR Code as PNG.
     */
    public function handle_download_png(): void {
        $this->verify_ajax_nonce( 'ffc_short_url_nonce' );
        $this->check_ajax_permission();

        $target = $this->resolve_qr_target();
        $base64 = $this->generate_qr_base64( $target['url'], 400 );

        if ( empty( $base64 ) ) {
            wp_send_json_error( [ 'message' => __( 'QR generation failed.', 'ffcertificate' ) ] );
        }

        wp_send_json_success( [
            'data'     => $base64,
            'filename' => $target['prefix'] . '.png',
            'mime'     => 'image/png',
        ] );
    }

    /**
     * AJAX: Download QR Code as SVG.
     */
    public function handle_download_svg(): void {
        $this->verify_ajax_nonce( 'ffc_short_url_nonce' );
        $this->check_ajax_permission();

        $target = $this->resolve_qr_target();
        $svg    = $this->generate_svg( $target['url'], 400 );

        if ( empty( $svg ) ) {
            wp_send_json_error( [ 'message' => __( 'SVG generation failed.', 'ffcertificate' ) ] );
        }

        wp_send_json_success( [
            'data'     => base64_encode( $svg ),
            'filename' => $target['prefix'] . '.svg',
            'mime'     => 'image/svg+xml',
        ] );
    }
}
