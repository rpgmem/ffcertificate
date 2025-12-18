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
        _e( 'No records found.', 'ffc' );
    }

    // --- VIEW TABS (Assets / Trash) ---
    protected function get_views() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';
        
        // Basic sanitation
        $status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish';
        if ( ! in_array( $status, array( 'publish', 'trash' ) ) ) {
            $status = 'publish';
        }

        // Safe counting (Assuming the 'status' column exists in DB)
        $count_publish = $wpdb->get_var("SELECT COUNT(id) FROM $table_name WHERE status = 'publish'");
        $count_trash   = $wpdb->get_var("SELECT COUNT(id) FROM $table_name WHERE status = 'trash'");
        
        $count_publish = $count_publish ? intval($count_publish) : 0;
        $count_trash   = $count_trash ? intval($count_trash) : 0;

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

        if ( ! empty( $_GET['filter_form_id'] ) ) {
            $form_id = absint( $_GET['filter_form_id'] );
            $fields  = get_post_meta( $form_id, '_ffc_form_fields', true );
            
            if ( is_array( $fields ) ) {
                foreach ( $fields as $field ) {
                    if ( ! empty( $field['name'] ) ) {
                        $col_key = sanitize_text_field( $field['name'] );
                        $col_label = ! empty( $field['label'] ) ? $field['label'] : $field['name'];
                        
                        // It does not overwrite default columns
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
                'bulk_print' => __( 'Print/Generate PDF', 'ffc' ),
                'bulk_trash' => __( 'Move to Trash', 'ffc' )
            );
        }
    }

    // --- LINE RENDERING (USING ARRAYS) ---

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
            
            // Checks if it's a string or a number to avoid an Array to String error
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
            // If it's an array (e.g., multiple checkboxes), convert it to a string
            if ( is_array( $data[ $column_name ] ) ) {
                return esc_html( implode( ', ', $data[ $column_name ] ) );
            }
            return esc_html( $data[ $column_name ] );
        }
        return '-';
    }

    // --- ACTIONS COLUMN ---
    public function column_actions( $item ) {
        $base_url = admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions' );
        $status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish';
        
        $actions = array();
        $id = absint( $item['id'] );

        if ( $status === 'trash' ) {
            $restore_url = wp_nonce_url( add_query_arg( array( 'action' => 'restore', 'submission_id' => $id ), $base_url ), 'ffc_restore_submission' );
            $delete_url  = wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'submission_id' => $id ), $base_url ), 'ffc_delete_submission' );
            
            $actions[] = sprintf( '<a href="%s" class="button button-small">%s</a>', esc_url( $restore_url ), __( 'Restore', 'ffc' ) );
            $actions[] = sprintf( '<a href="%s" class="button button-small button-link-delete" onclick="return confirm(\'%s\')">%s</a>', esc_url( $delete_url ), esc_js( __( 'Delete permanently?', 'ffc' ) ), __( 'Delete', 'ffc' ) );
        } else {
            $edit_url  = add_query_arg( array( 'action' => 'edit', 'submission_id' => $id ), $base_url );
            $trash_url = wp_nonce_url( add_query_arg( array( 'action' => 'trash', 'submission_id' => $id ), $base_url ), 'ffc_trash_submission' );

            $actions[] = sprintf( '<a href="%s" class="button button-small">%s</a>', esc_url( $edit_url ), __( 'Edit', 'ffc' ) );
            
            // PDF BUTTON FOR JS (Keeping classes and data-id)
            $actions[] = sprintf( 
                '<button type="button" class="button button-small button-primary ffc-admin-pdf-btn" data-id="%d">%s</button>', 
                $id,
                __( 'PDF', 'ffc' )
            );

            $actions[] = sprintf( '<a href="%s" class="button button-small button-link-delete" onclick="return confirm(\'%s\')">%s</a>', esc_url( $trash_url ), esc_js( __( 'Move to Trash?', 'ffc' ) ), __( 'Trash', 'ffc' ) );
        }

        return implode( ' ', $actions );
    }

    public function extra_tablenav( $which ) {
        if ( $which == 'top' ) {
            // `get_posts` can be heavy with many forms, but it's the WP standard
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

    // --- DATA PREPARATION ---
    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';

        $per_page = 50;
        $columns  = $this->get_columns();
        $hidden   = array();
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = array( $columns, $hidden, $sortable );

        // Status Filter
        $status = isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : 'publish';
        if( !in_array( $status, array('publish', 'trash') ) ) $status = 'publish';

        $where = $wpdb->prepare( "WHERE status = %s", $status );
        
        if ( ! empty( $_GET['filter_form_id'] ) ) {
            $where .= $wpdb->prepare( " AND form_id = %d", absint( $_GET['filter_form_id'] ) );
        }

        if ( ! empty( $_REQUEST['s'] ) ) {
            $s = '%' . $wpdb->esc_like( sanitize_text_field( $_REQUEST['s'] ) ) . '%';
            $where .= $wpdb->prepare( " AND ( email LIKE %s OR data LIKE %s )", $s, $s );
        }

        // Safe Ordering (Allowlist)
        $orderby_list = array( 'id', 'submission_date', 'email' );
        $orderby = ( ! empty( $_GET['orderby'] ) && in_array( $_GET['orderby'], $orderby_list ) ) ? $_GET['orderby'] : 'submission_date';
        
        $order = ( ! empty( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

        // Counting
        $total_items = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name} {$where}" );

        // Pagination
        $current_page = $this->get_pagenum();
        $total_pages = ceil( $total_items / $per_page );

        if ( $current_page > $total_pages && $total_items > 0 ) {
            $current_page = 1;
        }
        
        $offset = ( $current_page - 1 ) * $per_page;
        if ( $offset < 0 ) $offset = 0;

        // FINAL QUERY (Returning ARRAY_A)
        // Added direct sanitization in SQL string only where WPDB prepare doesn't cover (ordering)
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