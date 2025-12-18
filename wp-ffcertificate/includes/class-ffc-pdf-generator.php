<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FFC_PDF_Generator
 * Serviço centralizado para geração de PDFs/Certificados.
 * Utilizado tanto pelo Frontend quanto pelo Admin.
 */
class FFC_PDF_Generator {

    private $repository;

    public function __construct( FFC_Submission_Repository $repository ) { // <-- Injeção
    $this->repository = $repository;
}

    /**
     * Gera e força o download do PDF.
     * * @param int $submission_id ID da submissão.
     * @param string $mode 'download' (attachment) ou 'inline' (abrir no navegador).
     */
    public function generate( $submission_id, $mode = 'download' ) {
        // 1. Busca os dados da submissão
        $submission = $this->repository->get_by_id( $submission_id );
        
        if ( ! $submission ) {
            wp_die( __( 'Submission not found.', 'ffc' ) );
        }

        // 2. Busca as configurações do Formulário (Layout do PDF)
        $form_id = $submission['form_id'];
        $config  = get_post_meta( $form_id, '_ffc_form_config', true );
        $layout  = isset( $config['pdf_layout'] ) ? $config['pdf_layout'] : '';

        if ( empty( $layout ) ) {
            wp_die( __( 'PDF Layout not configured for this form.', 'ffc' ) );
        }

        // 3. Prepara os dados para substituição (Merge Tags)
        $data = json_decode( $submission['data'], true );
        if ( ! is_array( $data ) ) {
            $data = json_decode( stripslashes( $submission['data'] ), true );
        }

        // Adiciona dados meta padrão
        $data['submission_date'] = date_i18n( get_option( 'date_format' ), strtotime( $submission['submission_date'] ) );
        $data['submission_id']   = $submission_id;
        $data['user_email']      = $submission['email'];

        // 4. Processa o HTML (Substituição de variáveis)
        $html_content = $this->process_html( $layout, $data );

        // 5. Gera o PDF (Aqui entra sua biblioteca: Dompdf, TCPDF, MPDF...)
        // Exemplo genérico usando uma suposta biblioteca carregada:
        $this->render_pdf_library( $html_content, "submission-{$submission_id}.pdf", $mode );
    }

    /**
     * Substitui as tags {nome_do_campo} pelos valores reais.
     */
    private function process_html( $layout, $data ) {
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }
            // Substitui {key} pelo valor
            $layout = str_replace( '{' . $key . '}', $value, $layout );
        }
        return $layout;
    }

    /**
     * Wrapper para a biblioteca de PDF (Ex: Dompdf)
     */
    private function render_pdf_library( $html, $filename, $mode ) {
        // --- Exemplo com DOMPDF (ajuste conforme sua biblioteca atual) ---
        /*
        require_once FFC_PLUGIN_DIR . 'lib/dompdf/autoload.inc.php';
        $dompdf = new Dompdf\Dompdf();
        $dompdf->loadHtml( $html );
        $dompdf->setPaper( 'A4', 'landscape' );
        $dompdf->render();
        $dompdf->stream( $filename, array( "Attachment" => ($mode === 'download' ? true : false) ) );
        exit;
        */

        // Simulação para teste se não houver lib instalada ainda:
        header("Content-type: application/pdf");
        header("Content-Disposition: attachment; filename={$filename}");
        echo "PDF GENERATION LOGIC HERE FOR: " . $filename; // Substituir pela lógica real
        exit;
    }
}