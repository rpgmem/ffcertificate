<?php
/**
 * FFC_Submission_List
 * Manages the submissions list table using the WP_List_Table API.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class FFC_Submission_List extends WP_List_Table {

    protected $submission_handler;

    public function __construct( $handler ) {
        $this->submission_handler = $handler;
        
        parent::__construct( array(
            'singular' => 'submission',
            'plural'   => 'submissions',
            'ajax'     => false
        ) );
    }

    public function no_items() {
        _e( 'No records found.', 'ffc' );
    }

    /**
     * Define the view tabs (Active / Trash)
     */
    protected function get_views() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';
        
        $current_status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish';

        $count_publish = $wpdb->get_var("SELECT COUNT(id) FROM $table_name WHERE status = 'publish'");
        $count_trash   = $wpdb->get_var("SELECT COUNT(id) FROM $table_name WHERE status = 'trash'");
        
        $count_publish = $count_publish ? intval($count_publish) : 0;
        $count_trash   = $count_trash ? intval($count_trash) : 0;

        $base_url = admin_url('edit.php?post_type=ffc_form&page=ffc-submissions');

        $views = array(
            'publish' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url( add_query_arg('status', 'publish', $base_url) ),
                $current_status === 'publish' ? 'current' : '',
                __( 'Active', 'ffc' ),
                $count_publish
            ),
            'trash' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url( add_query_arg('status', 'trash', $base_url) ),
                $current_status === 'trash' ? 'current' : '',
                __( 'Trash', 'ffc' ),
                $count_trash
            )
        );
        return $views;
    }

    /**
     * Define table columns.
     * If a form filter is active, it displays the fields of that form as columns.
     */
    public function get_columns() {
        $columns = array(
            'cb'              => '<input type="checkbox" />',
            'id'              => __( 'ID', 'ffc' ),
            'submission_date' => __( 'Date', 'ffc' ),
            'form_name'       => __( 'Form', 'ffc' ),
            'email'           => __( 'Email', 'ffc' ),
        );

        // Dynamic columns logic when a specific form is filtered
        if ( ! empty( $_GET['filter_form_id'] ) ) {
            $form_id = absint( $_GET['filter_form_id'] );
            $fields  = get_post_meta( $form_id, '_ffc_form_fields', true );
            
            if ( is_array( $fields ) ) {
                foreach ( $fields as $field ) {
                    if ( ! empty( $field['name'] ) ) {
                        $col_key = sanitize_key( $field['name'] );
                        // Avoid duplicating default columns
                        if ( isset( $columns[$col_key] ) || $col_key === 'email' ) continue;
                        
                        $col_label = ! empty( $field['label'] ) ? $field['label'] : $field['name'];
                        $columns[ $col_key ] = esc_html( $col_label );
                    }
                }
            }
        } else {
            // Show summary if viewing all forms
            $columns['data_summary'] = __( 'Data Summary', 'ffc' );
        }

        $columns['user_ip'] = __( 'User IP', 'ffc' );
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
                'bulk_trash' => __( 'Move to Trash', 'ffc' )
            );
        }
    }

    // --- COLUMN RENDERING ---

    public function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="submission[]" value="%s" />', $item['id'] );
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
        $url = add_query_arg( 'filter_form_id', $item['form_id'], admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions' ) );
        return '<a href="' . esc_url( $url ) . '"><strong>' . esc_html( $title ) . '</strong></a>';
    }

    public function column_user_ip( $item ) {
        return ! empty( $item['user_ip'] ) ? esc_html( $item['user_ip'] ) : '-';
    }

    public function column_data_summary( $item ) {
        $data = json_decode( $item['data'], true );
        if ( ! is_array( $data ) ) $data = json_decode( stripslashes( $item['data'] ), true );
        if ( ! is_array( $data ) ) return '-';

        $output = array();
        $count = 0;
        foreach ( $data as $k => $v ) {
            if ( $count >= 3 ) break;
            // Skip internal metadata
            if ( in_array( $k, array( 'auth_code', 'fill_date', 'is_edited', 'edited_at', 'ticket' ) ) ) continue;
            
            if ( is_string( $v ) || is_numeric( $v ) ) {
                $label = str_replace( array('_', '-'), ' ', $k );
                $output[] = '<strong>' . esc_html( ucfirst($label) ) . ':</strong> ' . esc_html( wp_trim_words($v, 5) );
                $count++;
            }
        }
        return ! empty($output) ? implode( '<br>', $output ) : '<em>' . __('No extra data', 'ffc') . '</em>';
    }

    /**
     * Render dynamic columns (Form fields)
     */
    public function column_default( $item, $column_name ) {
        $data = json_decode( $item['data'], true );
        if ( ! is_array( $data ) ) $data = json_decode( stripslashes( $item['data'] ), true );
        
        if ( is_array( $data ) && isset( $data[ $column_name ] ) ) {
            $val = $data[ $column_name ];
            return is_array( $val ) ? esc_html( implode( ', ', $val ) ) : esc_html( $val );
        }
        return '-';
    }

    /**
     * Render action buttons (Edit, Trash, PDF, Delete, Restore)
     */
    public function column_actions( $item ) {
        $id = absint( $item['id'] );
        $status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish';
        
        $nonce = wp_create_nonce( 'ffc_action_' . $id );
        $base_url = admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions&submission_id=' . $id . '&_wpnonce=' . $nonce );
        $actions = array();

        if ( $status === 'trash' ) {
            $actions['restore'] = sprintf( '<a href="%s" class="button button-small">%s</a>', esc_url( add_query_arg( 'action', 'restore', $base_url ) ), __( 'Restore', 'ffc' ) );
            $actions['delete']  = sprintf( '<a href="%s" class="button button-small button-link-delete" onclick="return confirm(\'%s\')">%s</a>', esc_url( add_query_arg( 'action', 'delete', $base_url ) ), esc_js( __( 'Delete permanently?', 'ffc' ) ), __( 'Delete', 'ffc' ) );
        } else {
            $actions['edit']    = sprintf( '<a href="%s" class="button button-small">%s</a>', esc_url( add_query_arg( 'action', 'edit', $base_url ) ), __( 'Edit', 'ffc' ) );
            $actions['pdf']     = sprintf( '<button type="button" class="button button-small button-primary ffc-admin-pdf-btn" data-id="%d">PDF</button>', $id );
            $actions['trash']   = sprintf( '<a href="%s" class="button button-small button-link-delete">%s</a>', esc_url( add_query_arg( 'action', 'trash', $base_url ) ), __( 'Trash', 'ffc' ) );
        }

        return '<div class="ffc-row-actions">' . implode( ' ', $actions ) . '</div>';
    }

    /**
     * Form filter bar at the top of the table
     */
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

    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';

        $per_page = 50;
        $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

        // 1. Status Filter (Active or Trash)
        $status = ( isset( $_REQUEST['status'] ) && $_REQUEST['status'] === 'trash' ) ? 'trash' : 'publish';
        $where_parts = array( $wpdb->prepare( "status = %s", $status ) );

        // 2. Form Filter
        if ( ! empty( $_GET['filter_form_id'] ) ) {
            $where_parts[] = $wpdb->prepare( "form_id = %d", absint( $_GET['filter_form_id'] ) );
        }

        // 3. Search (Email or JSON data)
        if ( ! empty( $_REQUEST['s'] ) ) {
            $s = '%' . $wpdb->esc_like( sanitize_text_field( $_REQUEST['s'] ) ) . '%';
            $where_parts[] = $wpdb->prepare( "(email LIKE %s OR data LIKE %s)", $s, $s );
        }

        $where_clause = "WHERE " . implode( " AND ", $where_parts );

        // 4. Sorting
        $orderby_whitelist = array( 'id', 'submission_date', 'email' );
        $orderby = ( ! empty( $_GET['orderby'] ) && in_array( $_GET['orderby'], $orderby_whitelist ) ) ? $_GET['orderby'] : 'submission_date';
        $order = ( ! empty( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

        // 5. Pagination
        $total_items = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name} {$where_clause}" );
        $current_page = $this->get_pagenum();
        $offset = ( $current_page - 1 ) * $per_page;

        // 6. Final Query Execution
        $query = $wpdb->prepare(
            "SELECT * FROM {$table_name} {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
        
        $this->items = $wpdb->get_results( $query, ARRAY_A );

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page )
        ) );
    }
}