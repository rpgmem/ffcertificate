<?php
declare(strict_types=1);

/**
 * SubmissionsList v3.0.0
 * Uses Repository Pattern
 * Fixed: PDF button now uses token directly from item
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Repositories\SubmissionRepository;

if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class SubmissionsList extends \WP_List_Table {
    
    private $submission_handler;
    private $repository;
    
    public function __construct( object $handler ) {
        parent::__construct([
            'singular' => 'submission',
            'plural' => 'submissions',
            'ajax' => false
        ]);
        $this->submission_handler = $handler;
        $this->repository = new SubmissionRepository();
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'id' => __('ID', 'ffcertificate'),
            'form' => __('Form', 'ffcertificate'),
            'email' => __('Email', 'ffcertificate'),
            'data' => __('Data', 'ffcertificate'),
            'submission_date' => __('Date', 'ffcertificate'),
            'actions' => __('Actions', 'ffcertificate')
        ];
    }

    protected function get_sortable_columns() {
        return [
            'id' => ['id', true],
            'form' => ['form_id', false],
            'email' => ['email', false],
            'submission_date' => ['submission_date', false],
        ];
    }

    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'id':
                return $item['id'];
                
            case 'form':
                $form_title = get_the_title((int) $item['form_id']);
                return $form_title ? \FreeFormCertificate\Core\Utils::truncate($form_title, 30) : __('(Deleted)', 'ffcertificate');
                
            case 'email':
                return esc_html($item['email']);
                
            case 'data':
                return $this->format_data_preview($item['data']);
                
            case 'submission_date':
                return date_i18n(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    strtotime($item['submission_date'])
                );
                
            case 'actions':
                return $this->render_actions($item);
                
            default:
                return '';
        }
    }
    
    private function render_actions( array $item ): string {
        $base_url = admin_url('edit.php?post_type=ffc_form&page=ffc-submissions');
        $edit_url = add_query_arg(['action' => 'edit', 'submission_id' => $item['id']], $base_url);
        
        $actions = '<a href="' . esc_url($edit_url) . '" class="button button-small">' . __('Edit', 'ffcertificate') . '</a> ';
        $actions .= $this->render_pdf_button($item);
        
        if (isset($item['status']) && $item['status'] === 'publish') {
            $trash_url = wp_nonce_url(
                add_query_arg(['action' => 'trash', 'submission_id' => $item['id']], $base_url),
                'ffc_action_' . $item['id']
            );
            $actions .= '<a href="' . esc_url($trash_url) . '" class="button button-small">' . __('Trash', 'ffcertificate') . '</a>';
        } else {
            $restore_url = wp_nonce_url(
                add_query_arg(['action' => 'restore', 'submission_id' => $item['id']], $base_url),
                'ffc_action_' . $item['id']
            );
            $delete_url = wp_nonce_url(
                add_query_arg(['action' => 'delete', 'submission_id' => $item['id']], $base_url),
                'ffc_action_' . $item['id']
            );
            
            $actions .= '<a href="' . esc_url($restore_url) . '" class="button button-small">' . __('Restore', 'ffcertificate') . '</a> ';
            $actions .= '<a href="' . esc_url($delete_url) . '" class="button button-small ffc-delete-btn" onclick="return confirm(\'' . esc_js(__('Permanently delete?', 'ffcertificate')) . '\')">' . __('Delete', 'ffcertificate') . '</a>';
        }
        
        return $actions;
    }

    private function render_pdf_button( array $item ): string {
        // Use token directly from item (more efficient, avoids extra DB query)
        if (!empty($item['magic_token'])) {
            $magic_link = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link($item['magic_token']);
        } else {
            // Fallback: generate token if missing (convert id to int - wpdb returns strings)
            $magic_link = \FreeFormCertificate\Generators\MagicLinkHelper::get_submission_magic_link((int) $item['id'], $this->submission_handler);
        }
        
        if (empty($magic_link)) {
            return '<em class="ffc-no-token">No token</em>';
        }
        
        return sprintf(
            '<a href="%s" target="_blank" class="button button-small" title="%s">%s</a>',
            esc_url($magic_link),
            esc_attr__('Opens PDF in new tab', 'ffcertificate'),
            __('PDF', 'ffcertificate')
        );
    }

    private function format_data_preview( ?string $data_json ): string {
        if ($data_json === null || $data_json === 'null' || $data_json === '') {
            return '<em class="ffc-empty-data">' . __('Only mandatory fields', 'ffcertificate') . '</em>';
        }
        
        $data = json_decode($data_json, true);
        if (!is_array($data)) {
            $data = json_decode(stripslashes($data_json), true);
        }
        
        if (!is_array($data) || empty($data)) {
            return '<em class="ffc-empty-data">' . __('Only mandatory fields', 'ffcertificate') . '</em>';
        }
        
        $skip_fields = ['email', 'user_email', 'e-mail', 'auth_code', 'cpf_rf', 'cpf', 'rf', 'is_edited', 'edited_at'];
        $preview_items = [];
        $count = 0;
        
        foreach ($data as $key => $value) {
            if (in_array($key, $skip_fields) || $count >= 3) {
                continue;
            }
            
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            
            $value = \FreeFormCertificate\Core\Utils::truncate($value, 40);
            $label = ucfirst(str_replace('_', ' ', $key));
            $preview_items[] = '<strong>' . esc_html($label) . ':</strong> ' . esc_html($value);
            $count++;
        }
        
        if (empty($preview_items)) {
            return '<em class="ffc-empty-data">' . __('Only mandatory fields', 'ffcertificate') . '</em>';
        }
        
        return '<div class="ffc-data-preview">' . implode('<br>', $preview_items) . '</div>';
    }

    protected function column_cb($item) {
        return sprintf('<input type="checkbox" name="submission[]" value="%s" />', $item['id']);
    }

    protected function get_bulk_actions() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Status is a display filter parameter.
        $status = isset($_GET['status']) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'publish';
        if ($status === 'trash') {
            return [
                'bulk_restore' => __('Restore', 'ffcertificate'),
                'bulk_delete' => __('Delete Permanently', 'ffcertificate')
            ];
        }
        return ['bulk_trash' => __('Move to Trash', 'ffcertificate')];
    }

    public function prepare_items() {
        $this->process_bulk_action();

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $per_page = 20;
        $current_page = $this->get_pagenum();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- These are standard WP_List_Table filter/sort parameters.
        $status = isset($_GET['status']) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'publish';
        $search = isset($_REQUEST['s']) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $orderby = (!empty($_GET['orderby'])) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
        $order = (!empty($_GET['order']) && sanitize_text_field( wp_unslash( $_GET['order'] ) ) === 'asc') ? 'ASC' : 'DESC';

        // ✅ NEW: Filter by form ID(s)
        $filter_form_ids = [];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- empty() existence check only.
        if ( !empty( $_GET['filter_form_id'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- is_array() type check only.
            if ( is_array( $_GET['filter_form_id'] ) ) {
                $filter_form_ids = array_map( 'absint', wp_unslash( $_GET['filter_form_id'] ) );
            } else {
                $filter_form_ids = [ absint( wp_unslash( $_GET['filter_form_id'] ) ) ];
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $result = $this->repository->findPaginated([
            'status' => $status,
            'search' => $search,
            'per_page' => $per_page,
            'page' => $current_page,
            'orderby' => $orderby,
            'order' => $order,
            'form_ids' => $filter_form_ids
        ]);

        $this->items = [];
        if (!empty($result['items'])) {
            foreach ($result['items'] as $item) {
                $this->items[] = $this->submission_handler->decrypt_submission_data($item);
            }
        }

        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page' => $per_page,
            'total_pages' => $result['pages']
        ]);
    }

    protected function get_views() {
        $counts = $this->repository->countByStatus();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display parameter for tab highlighting.
        $current = isset($_GET['status']) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'publish';
        
        return [
            'all' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                remove_query_arg('status'),
                ($current == 'publish' ? 'current' : ''),
                __('Published', 'ffcertificate'),
                $counts['publish']
            ),
            'trash' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                add_query_arg('status', 'trash'),
                ($current == 'trash' ? 'current' : ''),
                __('Trash', 'ffcertificate'),
                $counts['trash']
            )
        ];
    }

    public function no_items() {
        esc_html_e('No submissions found.', 'ffcertificate');
    }

    /**
     * Display filters above the table
     *
     * @param string $which Position (top or bottom)
     */
    protected function extra_tablenav( $which ) {
        if ( $which !== 'top' ) {
            return;
        }

        // Get all forms
        $forms = get_posts( [
            'post_type' => 'ffc_form',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        ] );

        if ( empty( $forms ) ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display filter parameter for form selection.
        $selected_form_ids = [];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- empty() existence check only.
        if ( !empty( $_GET['filter_form_id'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- is_array() type check only.
            if ( is_array( $_GET['filter_form_id'] ) ) {
                $selected_form_ids = array_map( 'absint', wp_unslash( $_GET['filter_form_id'] ) );
            } else {
                $selected_form_ids = [ absint( wp_unslash( $_GET['filter_form_id'] ) ) ];
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        ?>
        <div class="alignleft actions">
            <label for="filter-form-id" class="screen-reader-text"><?php esc_html_e( 'Filter by form', 'ffcertificate' ); ?></label>
            <select name="filter_form_id[]" id="filter-form-id" multiple style="min-width: 200px; height: 100px;">
                <?php foreach ( $forms as $form ) : ?>
                    <option value="<?php echo esc_attr( $form->ID ); ?>" <?php echo in_array( $form->ID, $selected_form_ids ) ? 'selected' : ''; ?>>
                        <?php echo esc_html( $form->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="submit" class="button" value="<?php esc_html_e( 'Filter', 'ffcertificate' ); ?>">
            <?php if ( !empty( $selected_form_ids ) ) : ?>
                <a href="<?php echo esc_url( remove_query_arg( 'filter_form_id' ) ); ?>" class="button">
                    <?php esc_html_e( 'Clear Filter', 'ffcertificate' ); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }
}
