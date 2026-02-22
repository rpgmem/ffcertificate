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

    /**
     * @var \FreeFormCertificate\Submissions\SubmissionHandler
     */
    private $submission_handler;

    /**
     * @var \FreeFormCertificate\Repositories\SubmissionRepository
     */
    private $repository;

    /**
     * @var array<int, string>
     */
    private array $form_titles_cache = [];

    public function __construct( object $handler ) {
        parent::__construct([
            'singular' => 'submission',
            'plural' => 'submissions',
            'ajax' => false
        ]);
        $this->submission_handler = $handler;
        $this->repository = new SubmissionRepository();
    }

    /**
     * @return array<string, string>
     */
    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'id' => __('ID', 'ffcertificate'),
            'form' => __('Form', 'ffcertificate'),
            'email' => __('Email', 'ffcertificate'),
            'data' => __('Data', 'ffcertificate'),
            'status' => __('Status', 'ffcertificate'),
            'submission_date' => __('Date', 'ffcertificate'),
            'actions' => __('Actions', 'ffcertificate')
        ];
    }

    /**
     * @return array<string, array<int, bool|string>>
     */
    protected function get_sortable_columns() {
        return [
            'id' => ['id', true],
            'form' => ['form_id', false],
            'submission_date' => ['submission_date', false],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param string $column_name
     * @return string
     */
    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'id':
                return $item['id'];
                
            case 'form':
                $form_id = (int) $item['form_id'];
                $form_title = $this->form_titles_cache[ $form_id ] ?? '';
                return $form_title ? \FreeFormCertificate\Core\Utils::truncate($form_title, 30) : __('(Deleted)', 'ffcertificate');
                
            case 'email':
                return esc_html($item['email']);
                
            case 'data':
                return $this->format_data_preview($item['data']);

            case 'status':
                return $this->render_status_badge($item);

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
    
    /**
     * @param array<string, mixed> $item
     * @return string
     */
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
            $actions .= '<a href="' . esc_url($delete_url) . '" class="button button-small ffc-delete-btn" data-confirm="' . esc_attr(__('Permanently delete?', 'ffcertificate')) . '">' . __('Delete', 'ffcertificate') . '</a>';
        }
        
        return $actions;
    }

    /**
     * @param array<string, mixed> $item
     * @return string
     */
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

    /**
     * @param array<string, mixed> $item
     * @return string
     */
    private function render_status_badge( array $item ): string {
        $status = $item['status'] ?? 'publish';

        // Extract quiz score from data if available
        $score_html = '';
        $data_json = $item['data'] ?? '';
        if ( ! empty( $data_json ) ) {
            $data = json_decode( $data_json, true );
            if ( ! is_array( $data ) ) {
                $data = json_decode( wp_unslash( $data_json ), true );
            }
            if ( is_array( $data ) && isset( $data['_quiz_percent'] ) ) {
                $score_html = ' <small>(' . absint( $data['_quiz_percent'] ) . '%)</small>';
            }
        }

        switch ( $status ) {
            case 'publish':
                return '<span class="ffc-badge ffc-badge-success">' . esc_html__( 'Published', 'ffcertificate' ) . $score_html . '</span>';
            case 'trash':
                return '<span class="ffc-badge ffc-badge-muted">' . esc_html__( 'Trash', 'ffcertificate' ) . '</span>';
            case 'quiz_in_progress':
                return '<span class="ffc-badge ffc-badge-warning">' . esc_html__( 'Quiz: Retry', 'ffcertificate' ) . $score_html . '</span>';
            case 'quiz_failed':
                return '<span class="ffc-badge ffc-badge-danger">' . esc_html__( 'Quiz: Failed', 'ffcertificate' ) . $score_html . '</span>';
            default:
                return '<span class="ffc-badge">' . esc_html( $status ) . '</span>';
        }
    }

    private function format_data_preview( ?string $data_json ): string {
        if ($data_json === null || $data_json === 'null' || $data_json === '') {
            return '<em class="ffc-empty-data">' . __('Only mandatory fields', 'ffcertificate') . '</em>';
        }
        
        $data = json_decode($data_json, true);
        if (!is_array($data)) {
            $data = json_decode(wp_unslash($data_json), true);
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

    /**
     * @param array<string, mixed> $item
     * @return string
     */
    protected function column_cb($item) {
        return sprintf('<input type="checkbox" name="submission[]" value="%s" />', $item['id']);
    }

    /**
     * @return array<string, string>
     */
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

    /**
     * Process bulk actions.
     *
     * @return void
     */
    public function process_bulk_action() {
        // Intentionally empty: bulk actions are handled in the admin page callback.
    }

    /**
     * @return void
     */
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

        // Batch load form titles to avoid N+1 queries in column_default()
        $this->preload_form_titles();

        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page' => $per_page,
            'total_pages' => $result['pages']
        ]);
    }

    /**
     * Batch load form titles for all items to avoid N+1 queries.
     */
    private function preload_form_titles(): void {
        if ( empty( $this->items ) ) {
            return;
        }

        $form_ids = array_unique( array_filter( array_map( function ( $item ) {
            return (int) ( $item['form_id'] ?? 0 );
        }, $this->items ) ) );

        if ( empty( $form_ids ) ) {
            return;
        }

        $posts = get_posts( [
            'post_type'      => 'ffc_form',
            'include'        => $form_ids,
            'posts_per_page' => count( $form_ids ),
            'post_status'    => 'any',
        ] );

        foreach ( $posts as $post ) {
            $this->form_titles_cache[ $post->ID ] = $post->post_title;
        }
    }

    /**
     * @return array<string, string>
     */
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
            ),
            'quiz_in_progress' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                add_query_arg('status', 'quiz_in_progress'),
                ($current == 'quiz_in_progress' ? 'current' : ''),
                __('Quiz: Retry', 'ffcertificate'),
                $counts['quiz_in_progress']
            ),
            'quiz_failed' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                add_query_arg('status', 'quiz_failed'),
                ($current == 'quiz_failed' ? 'current' : ''),
                __('Quiz: Failed', 'ffcertificate'),
                $counts['quiz_failed']
            ),
        ];
    }

    /**
     * @return void
     */
    public function no_items() {
        esc_html_e('No submissions found.', 'ffcertificate');
    }

    /**
     * Display filters above the table
     *
     * @param string $which Position (top or bottom)
     * @return void
     */
    protected function extra_tablenav( $which ) {
        if ( $which !== 'top' ) {
            return;
        }

        // Get all forms ordered by ID descending (newest first)
        $forms = get_posts( [
            'post_type' => 'ffc_form',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'ID',
            'order' => 'DESC'
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

        $filter_count = count( $selected_form_ids );
        $btn_label = $filter_count > 0
            /* translators: %d: number of selected filters */
            ? sprintf( __( 'Filter (%d)', 'ffcertificate' ), $filter_count )
            : __( 'Filter', 'ffcertificate' );

        ?>
        <div class="alignleft actions ffc-filter-actions">
            <button type="button" class="button ffc-filter-btn" id="ffc-open-filter-overlay">
                <span class="dashicons dashicons-filter" style="vertical-align: middle; margin-right: 2px; font-size: 16px; line-height: 1.4;"></span>
                <?php echo esc_html( $btn_label ); ?>
            </button>
            <?php if ( $filter_count > 0 ) : ?>
                <a href="<?php echo esc_url( remove_query_arg( 'filter_form_id' ) ); ?>" class="button">
                    <?php esc_html_e( 'Clear Filter', 'ffcertificate' ); ?>
                </a>
            <?php endif; ?>

            <!-- Filter Overlay -->
            <div id="ffc-filter-overlay" class="ffc-filter-overlay" style="display: none;">
                <div class="ffc-filter-overlay-backdrop"></div>
                <div class="ffc-filter-overlay-content">
                    <div class="ffc-filter-overlay-header">
                        <h3><?php esc_html_e( 'Filter by Form', 'ffcertificate' ); ?></h3>
                        <button type="button" class="ffc-filter-overlay-close" title="<?php esc_attr_e( 'Close', 'ffcertificate' ); ?>">&times;</button>
                    </div>
                    <div class="ffc-filter-overlay-body">
                        <?php foreach ( $forms as $form ) :
                            $checked = in_array( $form->ID, $selected_form_ids ) ? 'checked' : '';
                        ?>
                            <label class="ffc-filter-form-item">
                                <input type="checkbox" name="filter_form_id[]" value="<?php echo esc_attr( (string) $form->ID ); ?>" <?php echo $checked; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 'checked' literal ?>>
                                <span class="ffc-filter-form-title"><?php echo esc_html( $form->post_title ); ?></span>
                                <span class="ffc-filter-form-id">#<?php echo esc_html( (string) $form->ID ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="ffc-filter-overlay-footer">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filter', 'ffcertificate' ); ?></button>
                        <button type="button" class="button ffc-filter-overlay-close"><?php esc_html_e( 'Cancel', 'ffcertificate' ); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var overlay = document.getElementById('ffc-filter-overlay');
            if (!overlay) return;
            document.getElementById('ffc-open-filter-overlay').addEventListener('click', function(){ overlay.style.display = 'flex'; });
            overlay.querySelectorAll('.ffc-filter-overlay-close, .ffc-filter-overlay-backdrop').forEach(function(el){
                el.addEventListener('click', function(){ overlay.style.display = 'none'; });
            });
            overlay.addEventListener('click', function(e){ if (e.target === overlay) overlay.style.display = 'none'; });
        })();
        </script>
        <?php
    }
}
