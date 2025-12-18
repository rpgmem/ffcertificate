<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FFC_Submission_Controller
 * Responsável por processar as ações do usuário (Salvar, Excluir, Restaurar, PDF, Ações em Massa).
 */
class FFC_Submission_Controller {

    /**
     * @var FFC_Submission_Repository
     */
    protected $repository;

    /**
     * @param FFC_Submission_Repository $repository Injeção de dependência.
     */
    public function __construct( $repository ) {
        $this->repository = $repository;
    }

    /**
     * Inicializa os hooks. Deve ser chamado no admin_init.
     */
    public function register_hooks() {
        // Ouve requisições de ações na página de submissões
        add_action( 'admin_init', array( $this, 'process_actions' ) );
        
        // Exibe as mensagens de feedback (sucesso/erro)
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
    }

    /**
     * Processa todas as ações (Single e Bulk).
     */
    public function process_actions() {
        // 1. Verifica se estamos na página correta do admin
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'ffc-submissions' ) {
            return;
        }

        // 2. [NOVO] Ação de Download de PDF
        // Verifica se é uma requisição para baixar o PDF
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'download_pdf' ) {
            $this->handle_pdf_download();
            return;
        }

        // 3. Processa Salvamento de Edição (POST)
        if ( isset( $_POST['ffc_action'] ) && $_POST['ffc_action'] === 'update_submission' ) {
            $this->process_save_submission();
            return;
        }

        // 4. Captura a ação atual (padrão WP List Table)
        // O WP List Table envia 'action' (top dropdown) ou 'action2' (bottom dropdown)
        $action = isset( $_REQUEST['action'] ) && -1 != $_REQUEST['action'] ? $_REQUEST['action'] : false;
        if ( ! $action && isset( $_REQUEST['action2'] ) && -1 != $_REQUEST['action2'] ) {
            $action = $_REQUEST['action2'];
        }

        if ( ! $action ) {
            return;
        }

        // 5. Identifica os IDs (Single ID ou Array para Bulk)
        $ids = array();
        if ( isset( $_REQUEST['submission_id'] ) ) {
            $ids[] = absint( $_REQUEST['submission_id'] );
        } elseif ( isset( $_REQUEST['submission'] ) && is_array( $_REQUEST['submission'] ) ) {
            $ids = array_map( 'absint', $_REQUEST['submission'] );
        }

        if ( empty( $ids ) ) {
            return;
        }

        // 6. Roteamento de Ações
        switch ( $action ) {
            case 'trash': // Single Trash
            case 'bulk_trash': // Bulk Trash
                $this->handle_status_change( $ids, 'trash', 'ffc_trash_submission' );
                break;

            case 'restore': // Single Restore
            case 'bulk_restore': // Bulk Restore
                $this->handle_status_change( $ids, 'restore', 'ffc_restore_submission' );
                break;

            case 'delete': // Single Delete Permanent
            case 'bulk_delete': // Bulk Delete Permanent
                $this->handle_delete( $ids, 'ffc_delete_submission' );
                break;
            
            // Outras ações customizadas entrariam aqui
        }
    }

    /**
     * [NOVO] Trata o pedido de download do PDF vindo do Admin.
     */
    private function handle_pdf_download() {
        // 1. Verifica ID
        $submission_id = isset( $_GET['submission_id'] ) ? absint( $_GET['submission_id'] ) : 0;
        if ( ! $submission_id ) {
            wp_die( __( 'Invalid Submission ID.', 'ffc' ) );
        }

        // 2. Verifica Nonce (Segurança contra CSRF)
        // O link na tabela deve ser gerado com wp_nonce_url(url, 'ffc_download_pdf_' . $id)
        check_admin_referer( 'ffc_download_pdf_' . $submission_id );

        // 3. Verifica Permissão
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have permission to download this file.', 'ffc' ) );
        }

        // 4. Carrega a classe de serviço se ainda não estiver carregada
        if ( ! class_exists( 'FFC_PDF_Generator' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/class-ffc-pdf-generator.php';
        }

        // 5. Gera o PDF
        $pdf_generator = new FFC_PDF_Generator();
        $pdf_generator->generate( $submission_id, 'download' );
        
        // O gerador deve forçar o download e dar exit, mas por segurança:
        exit; 
    }

    /**
     * Lógica para mover para lixeira ou restaurar.
     */
    private function handle_status_change( $ids, $method, $nonce_action ) {
        // Verifica Nonce (Segurança)
        $is_bulk = count($ids) > 1;
        if ( ! $is_bulk ) {
            check_admin_referer( $nonce_action );
        } else {
            check_admin_referer( 'bulk-submissions' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'ffc' ) );
        }

        $count = 0;
        foreach ( $ids as $id ) {
            if ( $method === 'trash' ) {
                $this->repository->trash( $id );
            } elseif ( $method === 'restore' ) {
                $this->repository->restore( $id );
            }
            $count++;
        }

        // Redireciona com mensagem
        $this->redirect_with_message( $method === 'trash' ? 'trashed' : 'restored', $count );
    }

    /**
     * Lógica para deletar permanentemente.
     */
    private function handle_delete( $ids, $nonce_action ) {
        $is_bulk = count($ids) > 1;
        if ( ! $is_bulk ) {
            check_admin_referer( $nonce_action );
        } else {
            check_admin_referer( 'bulk-submissions' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Permission denied.', 'ffc' ) );
        }

        $count = 0;
        foreach ( $ids as $id ) {
            $this->repository->delete( $id );
            $count++;
        }

        $this->redirect_with_message( 'deleted', $count );
    }

    /**
     * Processa o formulário de edição (POST).
     */
    private function process_save_submission() {
        check_admin_referer( 'ffc_save_submission_action', 'ffc_save_submission_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Permission denied.', 'ffc' ) );
        }

        $submission_id = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
        if ( ! $submission_id ) return;

        // Recupera os dados enviados (campos dinâmicos)
        $new_data = isset( $_POST['ffc_data'] ) ? $_POST['ffc_data'] : array();

        // Sanitização básica recursiva
        $new_data = map_deep( $new_data, 'sanitize_text_field' );

        // Atualiza via Repositório
        $this->repository->update( $submission_id, $new_data );

        // Redireciona para a lista principal
        $redirect_url = add_query_arg( 
            array( 
                'post_type' => 'ffc_form', 
                'page'      => 'ffc-submissions', 
                'message'   => 'updated' 
            ), 
            admin_url( 'edit.php' ) 
        );
        
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Utilitário de redirecionamento.
     */
    private function redirect_with_message( $msg_code, $count ) {
        // Mantém o status atual (trash ou publish) na URL para não perder o contexto
        $current_status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish';

        $redirect_url = add_query_arg( 
            array( 
                'post_type' => 'ffc_form', 
                'page'      => 'ffc-submissions',
                'status'    => $current_status,
                'message'   => $msg_code,
                'count'     => $count
            ), 
            admin_url( 'edit.php' ) 
        );
        
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Exibe os notices no topo da página.
     */
    public function admin_notices() {
        if ( empty( $_GET['message'] ) ) {
            return;
        }

        // Garante que count existe para evitar warning, padrão 1
        $count = isset($_GET['count']) ? absint( $_GET['count'] ) : 1;
        $msg   = sanitize_key( $_GET['message'] );
        $class = 'notice notice-success is-dismissible';
        $text  = '';

        switch ( $msg ) {
            case 'trashed':
                $text = sprintf( _n( '%s submission moved to Trash.', '%s submissions moved to Trash.', $count, 'ffc' ), $count );
                break;
            case 'restored':
                $text = sprintf( _n( '%s submission restored.', '%s submissions restored.', $count, 'ffc' ), $count );
                break;
            case 'deleted':
                $text = sprintf( _n( '%s submission permanently deleted.', '%s submissions permanently deleted.', $count, 'ffc' ), $count );
                break;
            case 'updated':
                $text = __( 'Submission updated successfully.', 'ffc' );
                break;
        }

        if ( $text ) {
            printf( '<div class="%s"><p>%s</p></div>', esc_attr( $class ), esc_html( $text ) );
        }
    }
}