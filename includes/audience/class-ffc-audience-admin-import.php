<?php
declare(strict_types=1);

/**
 * Audience Admin Import & Export
 *
 * Handles CSV import and export functionality for members and audiences.
 *
 * @since 4.6.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

class AudienceAdminImport {

    /**
     * Menu slug prefix
     *
     * @var string
     */
    private string $menu_slug;

    /**
     * Constructor
     *
     * @param string $menu_slug Menu slug prefix.
     */
    public function __construct(string $menu_slug) {
        $this->menu_slug = $menu_slug;
    }

    /**
     * Render import page
     *
     * @return void
     */
    public function render_page(): void {
        $audiences = AudienceRepository::get_hierarchical();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Import & Export', 'ffcertificate'); ?></h1>

            <?php settings_errors('ffc_audience'); ?>

            <h2 class="nav-tab-wrapper">
                <a href="#ffc-import-tab" class="nav-tab nav-tab-active" data-tab="ffc-import-tab"><?php esc_html_e('Import', 'ffcertificate'); ?></a>
                <a href="#ffc-export-tab" class="nav-tab" data-tab="ffc-export-tab"><?php esc_html_e('Export', 'ffcertificate'); ?></a>
            </h2>

            <div id="ffc-import-tab" class="ffc-tab-content" style="display: block;">
            <div class="ffc-import-sections">
                <!-- Import Members -->
                <div class="ffc-import-section">
                    <h2><?php esc_html_e('Import Members', 'ffcertificate'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Import users as members of audience groups. Users will be created if they do not exist.', 'ffcertificate'); ?>
                    </p>

                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('ffc_import_members', 'ffc_import_members_nonce'); ?>
                        <input type="hidden" name="ffc_action" value="import_members">

                        <table class="form-table" role="presentation"><tbody>
                            <tr>
                                <th scope="row">
                                    <label for="members_csv"><?php esc_html_e('CSV File', 'ffcertificate'); ?></label>
                                </th>
                                <td>
                                    <input type="file" name="members_csv" id="members_csv" accept=".csv" required>
                                    <p class="description">
                                        <?php esc_html_e('Required columns: email. Optional: name, audience_id or audience_name.', 'ffcertificate'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="import_audience_id"><?php esc_html_e('Target Audience', 'ffcertificate'); ?></label>
                                </th>
                                <td>
                                    <select name="import_audience_id" id="import_audience_id">
                                        <option value=""><?php esc_html_e('Use audience from CSV', 'ffcertificate'); ?></option>
                                        <?php foreach ($audiences as $audience) : ?>
                                            <option value="<?php echo esc_attr($audience->id); ?>">
                                                <?php echo esc_html($audience->name); ?>
                                            </option>
                                            <?php if (!empty($audience->children)) : ?>
                                                <?php foreach ($audience->children as $child) : ?>
                                                    <option value="<?php echo esc_attr($child->id); ?>">
                                                        &nbsp;&nbsp;&nbsp;<?php echo esc_html($child->name); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e('Select a specific audience or leave empty to use audience_id/audience_name from CSV.', 'ffcertificate'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Options', 'ffcertificate'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="create_users" value="1" checked>
                                        <?php esc_html_e('Create users if they do not exist (with ffc_user role)', 'ffcertificate'); ?>
                                    </label>
                                </td>
                            </tr>
                        </tbody></table>

                        <?php submit_button(__('Import Members', 'ffcertificate'), 'primary', 'import_members'); ?>
                    </form>

                    <div class="ffc-sample-csv">
                        <h4><?php esc_html_e('Sample CSV Format', 'ffcertificate'); ?></h4>
                        <pre><?php echo esc_html(AudienceCsvImporter::get_sample_csv('members')); ?></pre>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=' . $this->menu_slug . '-import&download_sample=members'), 'download_sample')); ?>" class="button">
                            <?php esc_html_e('Download Sample', 'ffcertificate'); ?>
                        </a>
                    </div>
                </div>

                <!-- Import Audiences -->
                <div class="ffc-import-section">
                    <h2><?php esc_html_e('Import Audiences', 'ffcertificate'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Import audience groups from a CSV file. Parent groups are created first, then children.', 'ffcertificate'); ?>
                    </p>

                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('ffc_import_audiences', 'ffc_import_audiences_nonce'); ?>
                        <input type="hidden" name="ffc_action" value="import_audiences">

                        <table class="form-table" role="presentation"><tbody>
                            <tr>
                                <th scope="row">
                                    <label for="audiences_csv"><?php esc_html_e('CSV File', 'ffcertificate'); ?></label>
                                </th>
                                <td>
                                    <input type="file" name="audiences_csv" id="audiences_csv" accept=".csv" required>
                                    <p class="description">
                                        <?php esc_html_e('Required columns: name. Optional: color, parent (parent audience name).', 'ffcertificate'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody></table>

                        <?php submit_button(__('Import Audiences', 'ffcertificate'), 'primary', 'import_audiences'); ?>
                    </form>

                    <div class="ffc-sample-csv">
                        <h4><?php esc_html_e('Sample CSV Format', 'ffcertificate'); ?></h4>
                        <pre><?php echo esc_html(AudienceCsvImporter::get_sample_csv('audiences')); ?></pre>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=' . $this->menu_slug . '-import&download_sample=audiences'), 'download_sample')); ?>" class="button">
                            <?php esc_html_e('Download Sample', 'ffcertificate'); ?>
                        </a>
                    </div>
                </div>

            </div><!-- .ffc-import-sections -->
            </div><!-- #ffc-import-tab -->

            <div id="ffc-export-tab" class="ffc-tab-content" style="display: none;">
            <div class="ffc-import-sections">
                <!-- Export Members -->
                <div class="ffc-import-section">
                    <h2><?php esc_html_e('Export Members', 'ffcertificate'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Export audience members to a CSV file. The file will contain email, name, and audience name columns.', 'ffcertificate'); ?>
                    </p>

                    <form method="post">
                        <?php wp_nonce_field('ffc_export_members', 'ffc_export_members_nonce'); ?>
                        <input type="hidden" name="ffc_action" value="export_members">

                        <table class="form-table" role="presentation"><tbody>
                            <tr>
                                <th scope="row">
                                    <label for="export_audience_id"><?php esc_html_e('Audience', 'ffcertificate'); ?></label>
                                </th>
                                <td>
                                    <select name="export_audience_id" id="export_audience_id">
                                        <option value=""><?php esc_html_e('All Audiences', 'ffcertificate'); ?></option>
                                        <?php foreach ($audiences as $audience) : ?>
                                            <option value="<?php echo esc_attr($audience->id); ?>">
                                                <?php echo esc_html($audience->name); ?>
                                            </option>
                                            <?php if (!empty($audience->children)) : ?>
                                                <?php foreach ($audience->children as $child) : ?>
                                                    <option value="<?php echo esc_attr($child->id); ?>">
                                                        &nbsp;&nbsp;&nbsp;<?php echo esc_html($child->name); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e('Select a specific audience to export or leave empty to export all members.', 'ffcertificate'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody></table>

                        <?php submit_button(__('Export Members', 'ffcertificate'), 'primary', 'export_members'); ?>
                    </form>
                </div>

                <!-- Export Audiences -->
                <div class="ffc-import-section">
                    <h2><?php esc_html_e('Export Audiences', 'ffcertificate'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Export audience groups to a CSV file. The file will contain name, color, and parent columns.', 'ffcertificate'); ?>
                    </p>

                    <form method="post">
                        <?php wp_nonce_field('ffc_export_audiences', 'ffc_export_audiences_nonce'); ?>
                        <input type="hidden" name="ffc_action" value="export_audiences">

                        <?php submit_button(__('Export Audiences', 'ffcertificate'), 'primary', 'export_audiences'); ?>
                    </form>
                </div>
            </div><!-- .ffc-import-sections -->
            </div><!-- #ffc-export-tab -->
        </div><!-- .wrap -->

        <script>
        jQuery(function($) {
            $('.nav-tab-wrapper .nav-tab').on('click', function(e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                $('.nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.ffc-tab-content').hide();
                $('#' + tab).show();
            });
            // Restore tab from URL hash
            if (window.location.hash) {
                var hash = window.location.hash.substring(1);
                var $tab = $('.nav-tab[data-tab="' + hash + '"]');
                if ($tab.length) {
                    $tab.trigger('click');
                }
            }
        });
        </script>
        <?php
    }

    /**
     * Handle CSV import and export
     *
     * @return void
     */
    public function handle_csv_import(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle sample download
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['download_sample']) && isset($_GET['_wpnonce'])) {
            $type = sanitize_text_field(wp_unslash($_GET['download_sample']));
            if (wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'download_sample')) {
                $filename = $type === 'audiences' ? 'audiences-sample.csv' : 'members-sample.csv';
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo AudienceCsvImporter::get_sample_csv($type);
                exit;
            }
        }

        // Handle members export
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'export_members') {
            if (!isset($_POST['ffc_export_members_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_export_members_nonce'])), 'ffc_export_members')) {
                return;
            }
            $this->export_members_csv();
        }

        // Handle audiences export
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'export_audiences') {
            if (!isset($_POST['ffc_export_audiences_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_export_audiences_nonce'])), 'ffc_export_audiences')) {
                return;
            }
            $this->export_audiences_csv();
        }

        // Handle members import
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'import_members') {
            if (!isset($_POST['ffc_import_members_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_import_members_nonce'])), 'ffc_import_members')) {
                return;
            }

            if (!isset($_FILES['members_csv'], $_FILES['members_csv']['error']) || $_FILES['members_csv']['error'] !== UPLOAD_ERR_OK) {
                add_settings_error('ffc_audience', 'ffc_message', __('File upload failed.', 'ffcertificate'), 'error');
                return;
            }

            $audience_id = isset($_POST['import_audience_id']) ? absint($_POST['import_audience_id']) : 0;
            $create_users = isset($_POST['create_users']) && $_POST['create_users'] === '1';

            $tmp_name = isset($_FILES['members_csv']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['members_csv']['tmp_name'])) : '';
            $result = AudienceCsvImporter::import_members(
                $tmp_name,
                $audience_id,
                $create_users
            );

            if ($result['success']) {
                $message = sprintf(
                    /* translators: 1: number imported, 2: number skipped */
                    __('Import completed. %1$d imported, %2$d skipped.', 'ffcertificate'),
                    $result['imported'],
                    $result['skipped']
                );
                if (!empty($result['errors'])) {
                    $message .= ' ' . sprintf(
                        /* translators: %d: number of errors */
                        __('%d errors occurred.', 'ffcertificate'),
                        count($result['errors'])
                    );
                }
                add_settings_error('ffc_audience', 'ffc_message', $message, 'success');

                // Show first 5 errors
                foreach (array_slice($result['errors'], 0, 5) as $error) {
                    add_settings_error('ffc_audience', 'ffc_message', $error, 'warning');
                }
            } else {
                add_settings_error('ffc_audience', 'ffc_message', implode(' ', $result['errors']), 'error');
            }
        }

        // Handle audiences import
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'import_audiences') {
            if (!isset($_POST['ffc_import_audiences_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_import_audiences_nonce'])), 'ffc_import_audiences')) {
                return;
            }

            if (!isset($_FILES['audiences_csv'], $_FILES['audiences_csv']['error']) || $_FILES['audiences_csv']['error'] !== UPLOAD_ERR_OK) {
                add_settings_error('ffc_audience', 'ffc_message', __('File upload failed.', 'ffcertificate'), 'error');
                return;
            }

            $tmp_name = isset($_FILES['audiences_csv']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['audiences_csv']['tmp_name'])) : '';
            $result = AudienceCsvImporter::import_audiences($tmp_name);

            if ($result['success']) {
                $message = sprintf(
                    /* translators: 1: number imported, 2: number skipped */
                    __('Import completed. %1$d imported, %2$d skipped.', 'ffcertificate'),
                    $result['imported'],
                    $result['skipped']
                );
                if (!empty($result['errors'])) {
                    $message .= ' ' . sprintf(
                        /* translators: %d: number of errors */
                        __('%d errors occurred.', 'ffcertificate'),
                        count($result['errors'])
                    );
                }
                add_settings_error('ffc_audience', 'ffc_message', $message, 'success');

                // Show first 5 errors
                foreach (array_slice($result['errors'], 0, 5) as $error) {
                    add_settings_error('ffc_audience', 'ffc_message', $error, 'warning');
                }
            } else {
                add_settings_error('ffc_audience', 'ffc_message', implode(' ', $result['errors']), 'error');
            }
        }
    }

    /**
     * Export members to CSV
     *
     * @return void
     */
    private function export_members_csv(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in caller handle_actions().
        $audience_id = isset($_POST['export_audience_id']) ? absint($_POST['export_audience_id']) : 0;

        // Collect audience IDs to export
        $audience_ids = array();
        if ($audience_id > 0) {
            $audience_ids[] = $audience_id;
        } else {
            $all_audiences = AudienceRepository::get_all();
            foreach ($all_audiences as $aud) {
                $audience_ids[] = (int) $aud->id;
            }
        }

        // Build audience name map
        $audience_map = array();
        $all_audiences = AudienceRepository::get_all();
        foreach ($all_audiences as $aud) {
            $audience_map[(int) $aud->id] = $aud->name;
        }

        $filename = 'members-export-' . gmdate('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $output = fopen('php://output', 'w');
        fputcsv($output, array('email', 'name', 'audience_name'));

        $seen = array(); // Avoid duplicate rows for same user+audience
        foreach ($audience_ids as $aid) {
            $member_ids = AudienceRepository::get_members($aid);
            $audience_name = isset($audience_map[$aid]) ? $audience_map[$aid] : '';

            foreach ($member_ids as $user_id) {
                $key = $user_id . '-' . $aid;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $user = get_user_by('id', $user_id);
                if (!$user) {
                    continue;
                }

                fputcsv($output, array(
                    $user->user_email,
                    $user->display_name,
                    $audience_name,
                ));
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($output);
        exit;
    }

    /**
     * Export audiences to CSV
     *
     * @return void
     */
    private function export_audiences_csv(): void {
        $audiences = AudienceRepository::get_hierarchical();

        $filename = 'audiences-export-' . gmdate('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $output = fopen('php://output', 'w');
        fputcsv($output, array('name', 'color', 'parent'));

        // Parents first, then children (same order as import expects)
        foreach ($audiences as $audience) {
            fputcsv($output, array(
                $audience->name,
                $audience->color ?? '#3788d8',
                '', // Parents have no parent
            ));

            if (!empty($audience->children)) {
                foreach ($audience->children as $child) {
                    fputcsv($output, array(
                        $child->name,
                        $child->color ?? '#3788d8',
                        $audience->name,
                    ));
                }
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($output);
        exit;
    }
}
