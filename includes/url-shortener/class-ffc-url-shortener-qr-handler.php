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

        // Get the QR code matrix as text
        $temp_file = tempnam( sys_get_temp_dir(), 'ffc_qr_svg_' );
        \QRcode::png( $url, $temp_file, QR_ECLEVEL_M, 1, 0 );

        // Read the PNG and get dimensions
        if ( ! file_exists( $temp_file ) ) {
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
     * AJAX: Download QR Code as PNG.
     */
    public function handle_download_png(): void {
        $this->verify_ajax_nonce( 'ffc_short_url_nonce' );
        $this->check_ajax_permission();

        $code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
        if ( empty( $code ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid code.', 'ffcertificate' ) ] );
        }

        $record = $this->service->get_repository()->findByShortCode( $code );
        if ( ! $record ) {
            wp_send_json_error( [ 'message' => __( 'Short URL not found.', 'ffcertificate' ) ] );
        }

        $short_url = $this->service->get_short_url( $code );
        $base64    = $this->generate_qr_base64( $short_url, 400 );

        if ( empty( $base64 ) ) {
            wp_send_json_error( [ 'message' => __( 'QR generation failed.', 'ffcertificate' ) ] );
        }

        wp_send_json_success( [
            'data'     => $base64,
            'filename' => 'qr-' . $code . '.png',
            'mime'     => 'image/png',
        ] );
    }

    /**
     * AJAX: Download QR Code as SVG.
     */
    public function handle_download_svg(): void {
        $this->verify_ajax_nonce( 'ffc_short_url_nonce' );
        $this->check_ajax_permission();

        $code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
        if ( empty( $code ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid code.', 'ffcertificate' ) ] );
        }

        $record = $this->service->get_repository()->findByShortCode( $code );
        if ( ! $record ) {
            wp_send_json_error( [ 'message' => __( 'Short URL not found.', 'ffcertificate' ) ] );
        }

        $short_url = $this->service->get_short_url( $code );
        $svg       = $this->generate_svg( $short_url, 400 );

        if ( empty( $svg ) ) {
            wp_send_json_error( [ 'message' => __( 'SVG generation failed.', 'ffcertificate' ) ] );
        }

        wp_send_json_success( [
            'data'     => base64_encode( $svg ),
            'filename' => 'qr-' . $code . '.svg',
            'mime'     => 'image/svg+xml',
        ] );
    }
}
