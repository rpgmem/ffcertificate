<?php
declare(strict_types=1);

/**
 * Reregistration Custom Fields Page
 *
 * Renders the admin submenu page for managing per-audience custom fields.
 *
 * @since 4.12.13  Extracted from ReregistrationAdmin
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceRepository;

if (!defined('ABSPATH')) {
    exit;
}

class ReregistrationCustomFieldsPage {

    /**
     * Render the Custom Fields overview page.
     *
     * Shows all audiences with their custom field counts and
     * provides direct links to edit each audience's fields.
     *
     * @return void
     */
    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'ffcertificate'));
        }

        $audiences = AudienceRepository::get_hierarchical('active');
        $edit_base = admin_url('admin.php?page=ffc-scheduling-audiences&action=edit&id=');

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Custom Fields', 'ffcertificate'); ?></h1>
            <p class="description">
                <?php esc_html_e('Custom fields are defined per audience. Select an audience to manage its fields.', 'ffcertificate'); ?>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="column-audience"><?php esc_html_e('Audience', 'ffcertificate'); ?></th>
                        <th class="column-fields" style="width:120px"><?php esc_html_e('Fields', 'ffcertificate'); ?></th>
                        <th class="column-actions" style="width:180px"><?php esc_html_e('Actions', 'ffcertificate'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($audiences)) : ?>
                        <tr><td colspan="3"><?php esc_html_e('No audiences found.', 'ffcertificate'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($audiences as $parent) : ?>
                            <?php self::render_row($parent, $edit_base); ?>
                            <?php if (!empty($parent->children)) : ?>
                                <?php foreach ($parent->children as $child) : ?>
                                    <?php self::render_row($child, $edit_base, true); ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render a single row in the custom fields overview table.
     *
     * @param object $audience  Audience object.
     * @param string $edit_base Base URL for edit links.
     * @param bool   $is_child  Whether this is a child audience.
     * @return void
     */
    private static function render_row(object $audience, string $edit_base, bool $is_child = false): void {
        $count = CustomFieldRepository::count_by_audience((int) $audience->id, false);
        $active = CustomFieldRepository::count_by_audience((int) $audience->id, true);
        $edit_url = $edit_base . $audience->id . '#ffc-custom-fields';

        ?>
        <tr>
            <td>
                <?php if (!empty($audience->color)) : ?>
                    <span class="ffc-color-dot" style="background:<?php echo esc_attr($audience->color); ?>"></span>
                <?php endif; ?>
                <?php echo $is_child ? '&mdash; ' : ''; ?>
                <?php echo esc_html($audience->name); ?>
            </td>
            <td>
                <?php if ($count > 0) : ?>
                    <?php
                    printf(
                        /* translators: 1: active count 2: total count */
                        esc_html__('%1$d active / %2$d total', 'ffcertificate'),
                        absint($active),
                        absint($count)
                    );
                    ?>
                <?php else : ?>
                    <span class="description"><?php esc_html_e('None', 'ffcertificate'); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">
                    <?php $count > 0 ? esc_html_e('Edit Fields', 'ffcertificate') : esc_html_e('Add Fields', 'ffcertificate'); ?>
                </a>
            </td>
        </tr>
        <?php
    }
}
