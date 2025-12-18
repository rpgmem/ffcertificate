<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class FFC_Submission_List extends WP_List_Table {

    public function __construct() {
        parent::__construct( array(
            'singular' => 'submission',
            'plural'   => 'submissions',
            'ajax'     => false
        ) );
    }

    public function no_items() {
        _e( 'Nenhum registro encontrado.', 'ffc' );
    }

    // --- ABAS DE VIEW (Ativos / Lixeira) ---
    protected function get_views() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';
        $status = isset($_REQUEST['status']) ? $_REQUEST['status'] : 'publish';
        
        $count_publish = $wpdb->get_var("SELECT COUNT(id) FROM $table_name WHERE status = 'publish'");
        $count_trash   = $wpdb->get_var("SELECT COUNT(id) FROM $table_name WHERE status = 'trash'");
        
        // Garante que não seja null
        $count_publish = $count_publish ? $count_publish : 0;
        $count_trash   = $count_trash ? $count_trash : 0;

        $views = array(
            'publish' => sprintf(
                '<a href="%s" class="%s">Ativos <span class="count">(%s)</span></a>',
                admin_url('edit.php?post_type=ffc_form&page=ffc-submissions&status=publish'),
                $status === 'publish' ? 'current' : '',
                $count_publish
            ),
            'trash' => sprintf(
                '<a href="%s" class="%s">Lixeira <span class="count">(%s)</span></a>',
                admin_url('edit.php?post_type=ffc_form&page=ffc-submissions&status=trash'),
                $status === 'trash' ? 'current' : '',
                $count_trash
            )
        );
        return $views;
    }

    public function get_columns() {
        $columns = array(
            'cb'              => '<input type="checkbox" />',
            'id'              => __( 'ID', 'ffc' ),
            'submission_date' => __( 'Data', 'ffc' ),
            'email'           => __( 'Email', 'ffc' ),
            'form_name'       => __( 'Formulário', 'ffc' ),
        );

        if ( ! empty( $_GET['filter_form_id'] ) ) {
            $form_id = absint( $_GET['filter_form_id'] );
            $fields  = get_post_meta( $form_id, '_ffc_form_fields', true );
            
            if ( is_array( $fields ) ) {
                foreach ( $fields as $field ) {
                    if ( ! empty( $field['name'] ) ) {
                        $col_key = esc_attr( $field['name'] );
                        $col_label = ! empty( $field['label'] ) ? $field['label'] : $field['name'];
                        // Não sobrescreve colunas padrão
                        if(!isset($columns[$col_key])) {
                            $columns[ $col_key ] = $col_label;
                        }
                    }
                }
            }
        } else {
            $columns['data_summary'] = __( 'Resumo dos Dados', 'ffc' );
        }

        $columns['actions'] = __( 'Ações', 'ffc' );
        return $columns;
    }

    public function get_sortable_columns() {
        return array(
            'id'              => array( 'id', false ),
            'submission_date' => array( 'submission_date', false ),
            'email'           => array( 'email', false ),
        );
    }

    public function get_bulk_actions() {
        $status = isset($_REQUEST['status']) ? $_REQUEST['status'] : 'publish';
        
        if ( $status === 'trash' ) {
            return array(
                'bulk_restore' => __( 'Restaurar', 'ffc' ),
                'bulk_delete'  => __( 'Excluir Permanentemente', 'ffc' )
            );
        } else {
            return array(
                'bulk_print' => __( 'Imprimir/Gerar PDF', 'ffc' ),
                'bulk_trash' => __( 'Mover para Lixeira', 'ffc' )
            );
        }
    }

    // --- RENDERIZAÇÃO DAS LINHAS (USANDO ARRAYS) ---

    public function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="submission[]" value="%s" />', $item['id'] );
    }

    public function column_id( $item ) {
        return '<strong>#' . $item['id'] . '</strong>';
    }

    public function column_submission_date( $item ) {
        return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item['submission_date'] ) );
    }

    public function column_email( $item ) {
        return '<a href="mailto:' . esc_attr( $item['email'] ) . '">' . esc_html( $item['email'] ) . '</a>';
    }

    public function column_form_name( $item ) {
        $form = get_post( $item['form_id'] );
        $title = $form ? $form->post_title : '(Excluído)';
        $url = add_query_arg( 'filter_form_id', $item['form_id'] );
        return '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
    }

    public function column_data_summary( $item ) {
        $data = json_decode( $item['data'], true );
        if ( ! is_array( $data ) ) $data = json_decode( stripslashes( $item['data'] ), true );
        if ( ! is_array( $data ) ) return '-';

        $output = array();
        $count = 0;
        foreach ( $data as $k => $v ) {
            if ( $count >= 3 ) break;
            if ( $k === 'auth_code' || $k === 'cpf_rf' || $k === 'fill_date' ) continue;
            
            $output[] = '<strong>' . esc_html( $k ) . ':</strong> ' . esc_html( $v );
            $count++;
        }
        return implode( '<br>', $output );
    }

    public function column_default( $item, $column_name ) {
        $data = json_decode( $item['data'], true );
        if ( ! is_array( $data ) ) $data = json_decode( stripslashes( $item['data'] ), true );
        
        if ( is_array( $data ) && isset( $data[ $column_name ] ) ) {
            return esc_html( $data[ $column_name ] );
        }
        return '-';
    }

    // --- COLUNA AÇÕES (Lógica de Lixeira + PDF Direto) ---
    public function column_actions( $item ) {
        $base_url = admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions' );
        $status = isset($_REQUEST['status']) ? $_REQUEST['status'] : 'publish';
        
        $actions = array();
        $id = $item['id']; // Usando array access

        if ( $status === 'trash' ) {
            $restore_url = wp_nonce_url( add_query_arg( array( 'action' => 'restore', 'submission_id' => $id ), $base_url ), 'ffc_restore_submission' );
            $delete_url  = wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'submission_id' => $id ), $base_url ), 'ffc_delete_submission' );
            
            $actions[] = sprintf( '<a href="%s" class="button button-small">%s</a>', esc_url( $restore_url ), __( 'Restaurar', 'ffc' ) );
            $actions[] = sprintf( '<a href="%s" class="button button-small button-link-delete" onclick="return confirm(\'Excluir permanentemente?\')">%s</a>', esc_url( $delete_url ), __( 'Excluir', 'ffc' ) );
        } else {
            $edit_url  = add_query_arg( array( 'action' => 'edit', 'submission_id' => $id ), $base_url );
            $trash_url = wp_nonce_url( add_query_arg( array( 'action' => 'trash', 'submission_id' => $id ), $base_url ), 'ffc_trash_submission' );

            $actions[] = sprintf( '<a href="%s" class="button button-small">Editar</a>', esc_url( $edit_url ) );
            
            // BOTÃO PDF PARA JS (Classe .ffc-admin-pdf-btn)
            $actions[] = sprintf( 
                '<button type="button" class="button button-small button-primary ffc-admin-pdf-btn" data-id="%d">PDF</button>', 
                $id 
            );

            $actions[] = sprintf( '<a href="%s" class="button button-small button-link-delete" onclick="return confirm(\'Mover para Lixeira?\')">Lixeira</a>', esc_url( $trash_url ) );
        }

        return implode( ' ', $actions );
    }

    public function extra_tablenav( $which ) {
        if ( $which == 'top' ) {
            $forms = get_posts( array( 'post_type' => 'ffc_form', 'posts_per_page' => -1, 'post_status' => 'any' ) );
            $current = isset( $_GET['filter_form_id'] ) ? absint( $_GET['filter_form_id'] ) : 0;
            ?>
            <div class="alignleft actions">
                <select name="filter_form_id">
                    <option value="">Todos os Formulários</option>
                    <?php foreach ( $forms as $f ) : ?>
                        <option value="<?php echo $f->ID; ?>" <?php selected( $current, $f->ID ); ?>>
                            <?php echo esc_html( $f->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button( 'Filtrar', 'secondary', 'filter_action', false ); ?>
            </div>
            <?php
        }
    }

    // --- PREPARAÇÃO DOS DADOS ---
    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';

        $per_page = 50;
        $columns  = $this->get_columns();
        $hidden   = array();
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = array( $columns, $hidden, $sortable );

        // Filtro de Status (Padrão: publish)
        $status = isset( $_REQUEST['status'] ) ? $_REQUEST['status'] : 'publish';
        $where = $wpdb->prepare( "WHERE status = %s", $status );
        
        if ( ! empty( $_GET['filter_form_id'] ) ) {
            $where .= $wpdb->prepare( " AND form_id = %d", absint( $_GET['filter_form_id'] ) );
        }

        if ( ! empty( $_REQUEST['s'] ) ) {
            $s = '%' . $wpdb->esc_like( sanitize_text_field( $_REQUEST['s'] ) ) . '%';
            $where .= $wpdb->prepare( " AND ( email LIKE %s OR data LIKE %s )", $s, $s );
        }

        $orderby = ( ! empty( $_GET['orderby'] ) ) ? sanitize_sql_orderby( $_GET['orderby'] ) : 'submission_date';
        $order   = ( ! empty( $_GET['order'] ) ) ? sanitize_text_field( $_GET['order'] ) : 'DESC';

        // Contagem
        $total_items = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name} {$where}" );

        // Paginação
        $current_page = $this->get_pagenum();
        $total_pages = ceil( $total_items / $per_page );

        if ( $current_page > $total_pages && $total_items > 0 ) {
            $current_page = 1;
        }
        
        $offset = ( $current_page - 1 ) * $per_page;
        if ( $offset < 0 ) $offset = 0;

        // QUERY FINAL (Retornando ARRAY_A)
        $this->items = $wpdb->get_results( 
            "SELECT * FROM {$table_name} {$where} ORDER BY {$orderby} {$order} LIMIT {$per_page} OFFSET {$offset}",
            ARRAY_A 
        );

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => $total_pages
        ) );
    }
}