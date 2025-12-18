<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class FFC_Submission_List extends WP_List_Table {

    /**
     * @var FFC_Submission_Repository
     */
    protected $repository;

    /**
     * Construtor injetando o repositório.
     * @param FFC_Submission_Repository $repository
     */
    public function __construct( $repository ) {
        $this->repository = $repository;

        parent::__construct( array(
            'singular' => 'submission',
            'plural'   => 'submissions',
            'ajax'     => false
        ) );
    }

    public function no_items() {
        _e( 'No records found.', 'ffc' );
    }

    // --- VIEW TABS (Assets / Trash) ---
    protected function get_views() {
        $count_publish = $this->repository->count( array( 'status' => 'publish' ) );
        $count_trash   = $this->repository->count( array( 'status' => 'trash' ) );

        $status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish';
        if ( ! in_array( $status, array( 'publish', 'trash' ) ) ) {
            $status = 'publish';
        }

        $views = array(
            'publish' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url( admin_url('edit.php?post_type=ffc_form&page=ffc-submissions&status=publish') ),
                $status === 'publish' ? 'current' : '',
                __( 'Active', 'ffc' ),
                $count_publish
            ),
            'trash' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url( admin_url('edit.php?post_type=ffc_form&page=ffc-submissions&status=trash') ),
                $status === 'trash' ? 'current' : '',
                __( 'Trash', 'ffc' ),
                $count_trash
            )
        );
        return $views;
    }

    public function get_columns() {
        $columns = array(
            'cb'              => '<input type="checkbox" />',
            'id'              => __( 'ID', 'ffc' ),
            'submission_date' => __( 'Date', 'ffc' ),
            'email'           => __( 'Email', 'ffc' ),
            'form_name'       => __( 'Form', 'ffc' ),
        );

        // Colunas Dinâmicas baseadas no Formulário filtrado
        if ( ! empty( $_GET['filter_form_id'] ) ) {
            $form_id = absint( $_GET['filter_form_id'] );
            $fields  = get_post_meta( $form_id, '_ffc_form_fields', true );
            
            if ( is_array( $fields ) ) {
                foreach ( $fields as $field ) {
                    if ( ! empty( $field['name'] ) ) {
                        $col_key = sanitize_text_field( $field['name'] );
                        $col_label = ! empty( $field['label'] ) ? $field['label'] : $field['name'];
                        
                        if( ! isset( $columns[ $col_key ] ) ) {
                            $columns[ $col_key ] = esc_html( $col_label );
                        }
                    }
                }
            }
        } else {
            $columns['data_summary'] = __( 'Data Summary', 'ffc' );
        }

        $columns['actions'] = __( 'Actions', 'ffc' );
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
        $status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish';
        
        if ( $status === 'trash' ) {
            return array(
                'bulk_restore' => __( 'Restore', 'ffc' ),
                'bulk_delete'  => __( 'Delete Permanently', 'ffc' )
            );
        } else {
            return array(
                'bulk_print' => __( 'Print/Generate PDF', 'ffc' ), // Pode ser implementado no futuro no Controller
                'bulk_trash' => __( 'Move to Trash', 'ffc' )
            );
        }
    }

    // --- RENDERIZAÇÃO DE LINHAS ---

    public function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="submission[]" value="%s" />', esc_attr( $item['id'] ) );
    }

    public function column_id( $item ) {
        return '<strong>#' . esc_html( $item['id'] ) . '</strong>';
    }

    public function column_submission_date( $item ) {
        return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item['submission_date'] ) );
    }

    public function column_email( $item ) {
        return '<a href="mailto:' . esc_attr( $item['email'] ) . '">' . esc_html( $item['email'] ) . '</a>';
    }

    public function column_form_name( $item ) {
        $form = get_post( $item['form_id'] );
        $title = $form ? $form->post_title : __( '(Deleted)', 'ffc' );
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
            if ( in_array( $k, array( 'auth_code', 'cpf_rf', 'fill_date' ) ) ) continue;
            
            if ( is_string( $v ) || is_numeric( $v ) ) {
                $output[] = '<strong>' . esc_html( ucfirst($k) ) . ':</strong> ' . esc_html( $v );
                $count++;
            }
        }
        return implode( '<br>', $output );
    }

    public function column_default( $item, $column_name ) {
        $data = json_decode( $item['data'], true );
        if ( ! is_array( $data ) ) $data = json_decode( stripslashes( $item['data'] ), true );
        
        if ( is_array( $data ) && isset( $data[ $column_name ] ) ) {
            if ( is_array( $data[ $column_name ] ) ) {
                return esc_html( implode( ', ', $data[ $column_name ] ) );
            }
            return esc_html( $data[ $column_name ] );
        }
        return '-';
    }

    // --- COLUNA DE AÇÕES (Botões) ---
    public function column_actions( $item ) {
        $base_url = admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions' );
        $status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish';
        
        $actions = array();
        $id = absint( $item['id'] );

        // URLs de Ação
        if ( $status === 'trash' ) {
            // Restore & Delete Permanent
            $restore_url = wp_nonce_url( add_query_arg( array( 'action' => 'restore', 'submission_id' => $id ), $base_url ), 'ffc_restore_submission' );
            $delete_url  = wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'submission_id' => $id ), $base_url ), 'ffc_delete_submission' );
            
            $actions[] = sprintf( '<a href="%s" class="button button-small">%s</a>', esc_url( $restore_url ), __( 'Restore', 'ffc' ) );
            $actions[] = sprintf( '<a href="%s" class="button button-small button-link-delete" onclick="return confirm(\'%s\')">%s</a>', esc_url( $delete_url ), esc_js( __( 'Delete permanently?', 'ffc' ) ), __( 'Delete', 'ffc' ) );
        
        } else {
            // Edit
            $edit_url  = add_query_arg( array( 'ffc_action' => 'edit_submission', 'submission_id' => $id ), $base_url );
            $actions[] = sprintf( '<a href="%s" class="button button-small">%s</a>', esc_url( $edit_url ), __( 'Edit', 'ffc' ) );
            
            // PDF (ATUALIZADO)
            // Gera URL segura com Nonce para o Controller processar o download
            $pdf_url = wp_nonce_url( 
                add_query_arg( array(
                    'action'        => 'download_pdf',
                    'submission_id' => $id
                ), $base_url ),
                'ffc_download_pdf_' . $id
            );

            // Usamos target="_blank" para baixar sem sair da página
            $actions[] = sprintf( 
                '<a href="%s" target="_blank" class="button button-small button-primary">%s</a>', 
                esc_url( $pdf_url ), 
                __( 'PDF', 'ffc' ) 
            );

            // Trash
            $trash_url = wp_nonce_url( add_query_arg( array( 'action' => 'trash', 'submission_id' => $id ), $base_url ), 'ffc_trash_submission' );
            $actions[] = sprintf( '<a href="%s" class="button button-small button-link-delete" onclick="return confirm(\'%s\')">%s</a>', esc_url( $trash_url ), esc_js( __( 'Move to Trash?', 'ffc' ) ), __( 'Trash', 'ffc' ) );
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
                    <option value=""><?php _e( 'All Forms', 'ffc' ); ?></option>
                    <?php foreach ( $forms as $f ) : ?>
                        <option value="<?php echo esc_attr( $f->ID ); ?>" <?php selected( $current, $f->ID ); ?>>
                            <?php echo esc_html( $f->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button( __( 'Filter', 'ffc' ), 'secondary', 'filter_action', false ); ?>
            </div>
            <?php
        }
    }

    // --- PREPARAÇÃO DE DADOS ---
    public function prepare_items() {
        
        $per_page = 50;
        $columns  = $this->get_columns();
        $hidden   = array();
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = array( $columns, $hidden, $sortable );

        // 1. Coleta e Sanitização de Filtros
        $status = isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : 'publish';
        if( !in_array( $status, array('publish', 'trash') ) ) $status = 'publish';

        $form_id = ! empty( $_GET['filter_form_id'] ) ? absint( $_GET['filter_form_id'] ) : null;
        $search  = ! empty( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '';

        // Ordenação
        $orderby_allowlist = array( 'id', 'submission_date', 'email' );
        $orderby = ( ! empty( $_GET['orderby'] ) && in_array( $_GET['orderby'], $orderby_allowlist ) ) ? $_GET['orderby'] : 'submission_date';
        $order   = ( ! empty( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

        // Paginação
        $current_page = $this->get_pagenum();
        $offset = ( $current_page - 1 ) * $per_page;

        // 2. Montagem dos Argumentos para o Repositório
        $args = array(
            'status'  => $status,
            'form_id' => $form_id,
            'search'  => $search,
            'orderby' => $orderby,
            'order'   => $order,
            'limit'   => $per_page,
            'offset'  => $offset
        );

        // 3. Chamadas ao Repositório
        $this->items = $this->repository->find_all( $args );
        $total_items = $this->repository->count( $args );

        // 4. Configuração da Paginação
        $total_pages = ceil( $total_items / $per_page );

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => $total_pages
        ) );
    }
}