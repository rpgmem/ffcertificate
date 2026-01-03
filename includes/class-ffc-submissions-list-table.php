<?php
/**
 * FFC_Submission_List
 * Handles the admin submissions list table display.
 * 
 * v2.9.2: Mantendo toda a lógica original com correções de renderização
 * v2.9.16: FIXED - PDF button now uses Magic Link (simple & works!)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class FFC_Submission_List extends WP_List_Table {
    
    private $submission_handler;
    
    public function __construct( $handler ) {
        parent::__construct( array(
            'singular' => __( 'Submission', 'ffc' ),
            'plural'   => __( 'Submissions', 'ffc' ),
            'ajax'     => false
        ) );
        $this->submission_handler = $handler;
    }

    public function get_columns() {
        return array(
            'cb'              => '<input type="checkbox" />',
            'id'              => __( 'ID', 'ffc' ),
            'form'            => __( 'Form', 'ffc' ),
            'email'           => __( 'Email', 'ffc' ),
            'data'            => __( 'Data', 'ffc' ),
            'submission_date' => __( 'Date', 'ffc' ),
            'actions'         => __( 'Actions', 'ffc' )
        );
    }

    protected function get_sortable_columns() {
        return array(
            'id'              => array( 'id', true ),
            'form'            => array( 'form_id', false ),
            'email'           => array( 'email', false ),
            'submission_date' => array( 'submission_date', false ),
        );
    }

    protected function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'id':
                return $item['id'];
                
            case 'form':
                $form_title = get_the_title( $item['form_id'] );
                if ( class_exists( 'FFC_Utils' ) && method_exists( 'FFC_Utils', 'truncate' ) ) {
                    return $form_title ? FFC_Utils::truncate( $form_title, 30 ) : __( '(Deleted)', 'ffc' );
                }
                return $form_title ? ( ( strlen( $form_title ) > 30 ) ? substr( $form_title, 0, 30 ) . '...' : $form_title ) : __( '(Deleted)', 'ffc' );
                
            case 'email':
                return esc_html( $item['email'] );
                
            case 'data':
                return $this->format_data_preview( $item['data'] );
                
            case 'submission_date':
                return date_i18n( 
                    get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), 
                    strtotime( $item['submission_date'] ) 
                );
                
            case 'actions':
                $base_url = admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions' );
                
                $edit_url = add_query_arg( array( 'action' => 'edit', 'submission_id' => $item['id'] ), $base_url );
                $trash_url = wp_nonce_url( add_query_arg( array( 'action' => 'trash', 'submission_id' => $item['id'] ), $base_url ), 'ffc_action_' . $item['id'] );
                
                // Actions HTML
                $actions = '<a href="' . esc_url( $edit_url ) . '" class="button button-small">' . __( 'Edit', 'ffc' ) . '</a> ';
                
                // ✅ v2.9.16: PDF button using Magic Link (simple & works!)
                $actions .= $this->render_pdf_button( $item );
                
                if ( isset($item['status']) && $item['status'] === 'publish' ) {
                    $actions .= '<a href="' . esc_url( $trash_url ) . '" class="button button-small">' . __( 'Trash', 'ffc' ) . '</a>';
                } else {
                    $restore_url = wp_nonce_url( add_query_arg( array( 'action' => 'restore', 'submission_id' => $item['id'] ), $base_url ), 'ffc_action_' . $item['id'] );
                    $delete_url = wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'submission_id' => $item['id'] ), $base_url ), 'ffc_action_' . $item['id'] );
                    
                    $actions .= '<a href="' . esc_url( $restore_url ) . '" class="button button-small">' . __( 'Restore', 'ffc' ) . '</a> ';
                    $actions .= '<a href="' . esc_url( $delete_url ) . '" class="button button-small ffc-delete-btn" onclick="return confirm(\'' . esc_js( __( 'Permanently delete?', 'ffc' ) ) . '\')">' . __( 'Delete', 'ffc' ) . '</a>';
                }
                
                return $actions;
                
            default:
                return '';
        }
    }
    
    /**
     * Render PDF download button using Magic Link
     * 
     * ✅ v2.9.16: New method - Simplified PDF download
     * Uses magic link to frontend page that generates PDF client-side
     * No complex server-side PDF generation needed!
     * 
     * @param array $item Submission row data
     * @return string HTML button
     */
    private function render_pdf_button( $item ) { $magic_link = FFC_Magic_Link_Helper::get_submission_magic_link( $item['id'], $this->submission_handler );
    
    if ( empty( $magic_link ) ) { return '<em class="ffc-no-token">No token</em>'; }
    
    return sprintf( '<a href="%s" target="_blank" class="button button-small" title="%s">%s</a>', esc_url( $magic_link ), esc_attr__( 'Opens PDF in new tab', 'ffc' ), __( 'PDF', 'ffc' ));}

    private function format_data_preview( $data_json ) {
        // ✅ Tratar NULL, 'null', vazio
        if ( $data_json === null || $data_json === 'null' || $data_json === '' ) {
            return '<em class="ffc-empty-data">' . __( 'Only mandatory fields', 'ffc' ) . '</em>';
        }
        
        // Decodificar JSON
        $data = json_decode( $data_json, true );
        if ( ! is_array( $data ) ) {
            $data = json_decode( stripslashes( $data_json ), true );
        }
        
        // Se ainda não é array, erro no JSON
        if ( ! is_array( $data ) ) {
            return '<em class="ffc-invalid-data">' . __( 'Invalid data', 'ffc' ) . '</em>';
        }
        
        // ✅ Se array vazio (só campos obrigatórios), mostrar mensagem clara
        if ( empty( $data ) ) {
            return '<em class="ffc-empty-data">' . __( 'Only mandatory fields', 'ffc' ) . '</em>';
        }
        
        // Campos para ignorar (já exibidos em outras colunas)
        $skip_fields = array( 'email', 'user_email', 'e-mail', 'auth_code', 'cpf_rf', 'cpf', 'rf', 'is_edited', 'edited_at' );
        $preview_items = array();
        $max_items = 3;
        $count = 0;
        
        foreach ( $data as $key => $value ) {
            if ( in_array( $key, $skip_fields ) || $count >= $max_items ) {
                continue;
            }
            
            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }
            
            if ( class_exists( 'FFC_Utils' ) && method_exists( 'FFC_Utils', 'truncate' ) ) {
                $value = FFC_Utils::truncate( $value, 40 );
            } else {
                $value = strlen( $value ) > 40 ? substr( $value, 0, 40 ) . '...' : $value;
            }
            
            $label = ucfirst( str_replace( '_', ' ', $key ) );
            $preview_items[] = '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value );
            $count++;
        }
        
        // ✅ Se não há campos extras (só obrigatórios), mostrar mensagem
        if ( empty( $preview_items ) ) {
            return '<em class="ffc-empty-data">' . __( 'Only mandatory fields', 'ffc' ) . '</em>';
        }
        
        return '<div class="ffc-data-preview">' . implode( '<br>', $preview_items ) . '</div>';
    }

    protected function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="submission[]" value="%s" />', $item['id'] );
    }

    protected function get_bulk_actions() {
        $status = isset( $_GET['status'] ) ? $_GET['status'] : 'publish';
        if ( $status === 'trash' ) {
            return array( 'bulk_restore' => __( 'Restore', 'ffc' ), 'bulk_delete' => __( 'Delete Permanently', 'ffc' ) );
        }
        return array( 'bulk_trash' => __( 'Move to Trash', 'ffc' ) );
    }

    public function prepare_items() {
        global $wpdb;
        $table_name = FFC_Utils::get_submissions_table();
        
        // 1. Processar ações em lote antes de buscar os itens
        $this->process_bulk_action();

        // 2. Definir cabeçalhos (Obrigatório para evitar tela branca)
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array( $columns, $hidden, $sortable );

        // 3. Filtros e Busca
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'publish';
        $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '';
        
        $where = array( $wpdb->prepare( "status = %s", $status ) );
        if ( ! empty( $search ) ) {
            $where[] = $wpdb->prepare( "(email LIKE %s OR data LIKE %s)", '%' . $wpdb->esc_like( $search ) . '%', '%' . $wpdb->esc_like( $search ) . '%' );
        }
        $where_clause = 'WHERE ' . implode( ' AND ', $where );
        
        // 4. Ordenação
        $orderby = ( ! empty( $_GET['orderby'] ) ) ? sanitize_key( $_GET['orderby'] ) : 'id';
        $order = ( ! empty( $_GET['order'] ) && $_GET['order'] === 'asc' ) ? 'ASC' : 'DESC';

        // 5. Executar Query
        $total_items = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name $where_clause" );
        $offset = ( $current_page - 1 ) * $per_page;
        
        $this->items = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM $table_name $where_clause ORDER BY $orderby $order LIMIT %d OFFSET %d", $per_page, $offset ),
            ARRAY_A
        );

        // 6. Configurar Paginação
        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page )
        ) );
    }

    protected function get_views() {
        global $wpdb;
        $table_name = FFC_Utils::get_submissions_table();
        $counts = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM $table_name GROUP BY status", OBJECT_K );
        
        $pub_count = isset($counts['publish']) ? $counts['publish']->count : 0;
        $trash_count = isset($counts['trash']) ? $counts['trash']->count : 0;
        
        $current = isset( $_GET['status'] ) ? $_GET['status'] : 'publish';
        return array(
            'all'   => sprintf( '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>', remove_query_arg('status'), ($current=='publish'?'current':''), __('Published','ffc'), $pub_count ),
            'trash' => sprintf( '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>', add_query_arg('status','trash'), ($current=='trash'?'current':''), __('Trash','ffc'), $trash_count )
        );
    }

    public function no_items() {
        esc_html_e( 'No submissions found.', 'ffc' );
    }
}