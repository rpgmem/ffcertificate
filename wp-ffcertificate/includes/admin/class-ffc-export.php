<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Responsável por processar a exportação de dados (CSV).
 */
class FFC_Export {

    /**
     * @var FFC_Submission_Repository
     */
    protected $repository;

    public function __construct( FFC_Submission_Repository $repository ) {
    $this->repository = $repository; // <-- Injeção de Dependência
}

    /**
     * Inicializa os hooks de admin-post.
     */
    public function init() {
        // O gancho 'admin_post_' é ideal para processar formulários sem renderizar a UI do admin
        add_action( 'admin_post_ffc_export_csv', array( $this, 'handle_csv_export' ) );
    }

    /**
     * Processa a requisição de exportação.
     */
    public function handle_csv_export() {
        // 1. Verificações de Segurança
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You do not have permission to export data.', 'ffc' ) );
        }

        check_admin_referer( 'ffc_export_csv_nonce' );

        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        if ( ! $form_id ) {
            wp_die( esc_html__( 'Invalid Form ID.', 'ffc' ) );
        }

        // 2. Busca os dados via Repositório (Abstração do Banco)
        // Buscamos 'any' status para ter um histórico completo, ou 'publish' para apenas válidos.
        // limit -1 traz tudo.
        $submissions = $this->repository->find_all( array(
            'form_id' => $form_id,
            'limit'   => -1,
            'status'  => 'any',
            'orderby' => 'submission_date',
            'order'   => 'DESC'
        ));

        if ( empty( $submissions ) ) {
            wp_die( esc_html__( 'No submissions found for this form.', 'ffc' ) );
        }

        // 3. Define os Headers para Download
        $filename = 'submissions-form-' . $form_id . '-' . date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // Adiciona BOM para compatibilidade com Microsoft Excel (para ler acentos corretamente)
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) );

        // 4. Determina as Colunas Dinamicamente e Prepara Linhas
        $fixed_headers = array( 'ID', 'Date', 'Status', 'Email', 'User IP' ); // Adicionei Status
        $dynamic_headers = array();
        $processed_rows = array();

        foreach ( $submissions as $sub ) {
            // O Repositório retorna array, não objeto
            $raw_json = isset($sub['data']) ? $sub['data'] : '';
            
            $data = json_decode( $raw_json, true );
            if ( ! is_array( $data ) ) {
                $data = json_decode( stripslashes( $raw_json ), true );
            }

            // Garante que $data é array
            $data = (array) $data;

            // Coleta chaves únicas do JSON para criar colunas
            foreach ( array_keys( $data ) as $key ) {
                // Ignora chaves internas se houver
                if ( ! in_array( $key, $fixed_headers ) && ! in_array( $key, $dynamic_headers ) ) {
                    $dynamic_headers[] = $key;
                }
            }
            
            $processed_rows[] = array(
                'meta' => $sub,
                'json' => $data
            );
        }

        // Une cabeçalhos fixos e dinâmicos
        $all_headers = array_merge( $fixed_headers, $dynamic_headers );
        fputcsv( $output, $all_headers );

        // 5. Escreve as Linhas
        foreach ( $processed_rows as $row ) {
            $sub  = $row['meta']; // Array vindo do DB
            $data = $row['json']; // Array do JSON
            
            $csv_row = array();
            
            foreach ( $all_headers as $header ) {
                $val = '';

                // Mapeamento de Colunas Fixas
                if ( $header === 'ID' ) {
                    $val = $sub['id'];
                } elseif ( $header === 'Date' ) {
                    $val = $sub['submission_date'];
                } elseif ( $header === 'Status' ) {
                    $val = $sub['status'];
                } elseif ( $header === 'Email' ) {
                    $val = $sub['email'];
                } elseif ( $header === 'User IP' ) {
                    $val = $sub['user_ip'];
                } else {
                    // Colunas Dinâmicas (do JSON)
                    if ( isset( $data[$header] ) ) {
                        $raw_val = $data[$header];
                        
                        // Correção Importante: Se for array (ex: checkbox), converte para string
                        if ( is_array( $raw_val ) ) {
                            $val = implode( ', ', $raw_val );
                        } else {
                            $val = $raw_val;
                        }
                    }
                }
                
                // Sanitiza contra Injeção de CSV
                $csv_row[] = $this->prevent_csv_injection( $val );
            }
            
            fputcsv( $output, $csv_row );
        }

        fclose( $output );
        exit;
    }

    /**
     * Evita execução de fórmulas no Excel (CSV Injection)
     */
    private function prevent_csv_injection( $value ) {
        if ( is_string( $value ) && preg_match( '/^[\=\+\-\@]/', $value ) ) {
            return "'" . $value; 
        }
        return $value;
    }
}