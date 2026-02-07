<?php
declare(strict_types=1);

/**
 * Audience Admin Import
 *
 * Handles CSV import functionality for members and audiences.
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
            <h1><?php esc_html_e('Import', 'wp-ffcertificate'); ?></h1>

            <?php settings_errors('ffc_audience'); ?>

            <div class="ffc-import-sections">
                <!-- Import Members -->
                <div class="ffc-import-section">
                    <h2><?php esc_html_e('Import Members', 'wp-ffcertificate'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Import users as members of audience groups. Users will be created if they do not exist.', 'wp-ffcertificate'); ?>
                    </p>

                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('ffc_import_members', 'ffc_import_members_nonce'); ?>
                        <input type="hidden" name="ffc_action" value="import_members">

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="members_csv"><?php esc_html_e('CSV File', 'wp-ffcertificate'); ?></label>
                                </th>
                                <td>
                                    <input type="file" name="members_csv" id="members_csv" accept=".csv" required>
                                    <p class="description">
                                        <?php esc_html_e('Required columns: email. Optional: name, audience_id or audience_name.', 'wp-ffcertificate'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="import_audience_id"><?php esc_html_e('Target Audience', 'wp-ffcertificate'); ?></label>
                                </th>
                                <td>
                                    <select name="import_audience_id" id="import_audience_id">
                                        <option value=""><?php esc_html_e('Use audience from CSV', 'wp-ffcertificate'); ?></option>
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
                                        <?php esc_html_e('Select a specific audience or leave empty to use audience_id/audience_name from CSV.', 'wp-ffcertificate'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Options', 'wp-ffcertificate'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="create_users" value="1" checked>
                                        <?php esc_html_e('Create users if they do not exist (with ffc_user role)', 'wp-ffcertificate'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(__('Import Members', 'wp-ffcertificate'), 'primary', 'import_members'); ?>
                    </form>

                    <div class="ffc-sample-csv">
                        <h4><?php esc_html_e('Sample CSV Format', 'wp-ffcertificate'); ?></h4>
                        <pre><?php echo esc_html(AudienceCsvImporter::get_sample_csv('members')); ?></pre>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=' . $this->menu_slug . '-import&download_sample=members'), 'download_sample')); ?>" class="button">
                            <?php esc_html_e('Download Sample', 'wp-ffcertificate'); ?>
                        </a>
                    </div>
                </div>

                <!-- Import Audiences -->
                <div class="ffc-import-section">
                    <h2><?php esc_html_e('Import Audiences', 'wp-ffcertificate'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Import audience groups from a CSV file. Parent groups are created first, then children.', 'wp-ffcertificate'); ?>
                    </p>

                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('ffc_import_audiences', 'ffc_import_audiences_nonce'); ?>
                        <input type="hidden" name="ffc_action" value="import_audiences">

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="audiences_csv"><?php esc_html_e('CSV File', 'wp-ffcertificate'); ?></label>
                                </th>
                                <td>
                                    <input type="file" name="audiences_csv" id="audiences_csv" accept=".csv" required>
                                    <p class="description">
                                        <?php esc_html_e('Required columns: name. Optional: color, parent (parent audience name).', 'wp-ffcertificate'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(__('Import Audiences', 'wp-ffcertificate'), 'primary', 'import_audiences'); ?>
                    </form>

                    <div class="ffc-sample-csv">
                        <h4><?php esc_html_e('Sample CSV Format', 'wp-ffcertificate'); ?></h4>
                        <pre><?php echo esc_html(AudienceCsvImporter::get_sample_csv('audiences')); ?></pre>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=' . $this->menu_slug . '-import&download_sample=audiences'), 'download_sample')); ?>" class="button">
                            <?php esc_html_e('Download Sample', 'wp-ffcertificate'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Styles in ffc-audience-admin.css -->
        <?php
    }

    /**
     * Handle CSV import
     *
     * @return void
     */
    public function handle_csv_import(): void {
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

        // Handle members import
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'import_members') {
            if (!isset($_POST['ffc_import_members_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_import_members_nonce'])), 'ffc_import_members')) {
                return;
            }

            if (!isset($_FILES['members_csv'], $_FILES['members_csv']['error']) || $_FILES['members_csv']['error'] !== UPLOAD_ERR_OK) {
                add_settings_error('ffc_audience', 'ffc_message', __('File upload failed.', 'wp-ffcertificate'), 'error');
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
                    __('Import completed. %1$d imported, %2$d skipped.', 'wp-ffcertificate'),
                    $result['imported'],
                    $result['skipped']
                );
                if (!empty($result['errors'])) {
                    $message .= ' ' . sprintf(
                        /* translators: %d: number of errors */
                        __('%d errors occurred.', 'wp-ffcertificate'),
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
                add_settings_error('ffc_audience', 'ffc_message', __('File upload failed.', 'wp-ffcertificate'), 'error');
                return;
            }

            $tmp_name = isset($_FILES['audiences_csv']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['audiences_csv']['tmp_name'])) : '';
            $result = AudienceCsvImporter::import_audiences($tmp_name);

            if ($result['success']) {
                $message = sprintf(
                    /* translators: 1: number imported, 2: number skipped */
                    __('Import completed. %1$d imported, %2$d skipped.', 'wp-ffcertificate'),
                    $result['imported'],
                    $result['skipped']
                );
                if (!empty($result['errors'])) {
                    $message .= ' ' . sprintf(
                        /* translators: %d: number of errors */
                        __('%d errors occurred.', 'wp-ffcertificate'),
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
}
