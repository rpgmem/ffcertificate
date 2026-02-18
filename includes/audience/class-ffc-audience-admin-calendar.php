<?php
/**
 * Audience Admin Calendar
 *
 * @package FreeFormCertificate\Audience
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

/**
 * Handles calendar admin pages (list, form, actions).
 */
class AudienceAdminCalendar {

    /**
     * Menu slug prefix.
     *
     * @var string
     */
    private string $menu_slug;

    /**
     * Constructor.
     *
     * @param string $menu_slug The menu slug prefix.
     */
    public function __construct(string $menu_slug) {
        $this->menu_slug = $menu_slug;
    }

    /**
     * Render calendars page
     *
     * @return void
     */
    public function render_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        ?>
        <div class="wrap">
            <?php
            switch ($action) {
                case 'new':
                case 'edit':
                    $this->render_form($id);
                    break;
                default:
                    $this->render_list();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render calendars list
     *
     * @return void
     */
    private function render_list(): void {
        $schedules = AudienceScheduleRepository::get_all(array('orderby' => 'name'));
        $add_url = admin_url('admin.php?page=' . $this->menu_slug . '-calendars&action=new');

        ?>
        <h1 class="wp-heading-inline"><?php esc_html_e('Calendars', 'ffcertificate'); ?></h1>
        <a href="<?php echo esc_url($add_url); ?>" class="page-title-action"><?php esc_html_e('Add New', 'ffcertificate'); ?></a>
        <hr class="wp-header-end">

        <?php settings_errors('ffc_audience'); ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="column-name"><?php esc_html_e('Name', 'ffcertificate'); ?></th>
                    <th scope="col" class="column-visibility"><?php esc_html_e('Visibility', 'ffcertificate'); ?></th>
                    <th scope="col" class="column-environments"><?php echo esc_html(AudienceScheduleRepository::get_environment_label()); ?></th>
                    <th scope="col" class="column-status"><?php esc_html_e('Status', 'ffcertificate'); ?></th>
                    <th scope="col" class="column-actions"><?php esc_html_e('Actions', 'ffcertificate'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($schedules)) : ?>
                    <tr>
                        <td colspan="5"><?php esc_html_e('No calendars found.', 'ffcertificate'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($schedules as $schedule) : ?>
                        <?php
                        $env_count = AudienceEnvironmentRepository::count(array('schedule_id' => $schedule->id));
                        $edit_url = admin_url('admin.php?page=' . $this->menu_slug . '-calendars&action=edit&id=' . $schedule->id);
                        $is_active = ($schedule->status === 'active');

                        $deactivate_url = '';
                        $delete_url = '';
                        if ($is_active) {
                            $deactivate_url = wp_nonce_url(
                                admin_url('admin.php?page=' . $this->menu_slug . '-calendars&action=deactivate&id=' . $schedule->id),
                                'deactivate_schedule_' . $schedule->id
                            );
                        } else {
                            $delete_url = wp_nonce_url(
                                admin_url('admin.php?page=' . $this->menu_slug . '-calendars&action=delete&id=' . $schedule->id),
                                'delete_schedule_' . $schedule->id
                            );
                        }
                        ?>
                        <tr>
                            <td class="column-name">
                                <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($schedule->name); ?></a></strong>
                                <?php if ($schedule->description) : ?>
                                    <p class="description"><?php echo esc_html(wp_trim_words($schedule->description, 15)); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="column-visibility">
                                <?php echo $schedule->visibility === 'public' ? esc_html__('Public', 'ffcertificate') : esc_html__('Private', 'ffcertificate'); ?>
                            </td>
                            <td class="column-environments"><?php echo esc_html((string) $env_count); ?></td>
                            <td class="column-status">
                                <span class="ffc-status-badge ffc-status-<?php echo esc_attr($schedule->status); ?>">
                                    <?php echo $is_active ? esc_html__('Active', 'ffcertificate') : esc_html__('Inactive', 'ffcertificate'); ?>
                                </span>
                            </td>
                            <td class="column-actions">
                                <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'ffcertificate'); ?></a> |
                                <?php if ($is_active) : ?>
                                    <a href="<?php echo esc_url($deactivate_url); ?>" class="delete-link" onclick="return confirm('<?php esc_attr_e('Are you sure you want to deactivate this calendar?', 'ffcertificate'); ?>');">
                                        <?php esc_html_e('Deactivate', 'ffcertificate'); ?>
                                    </a>
                                <?php else : ?>
                                    <a href="<?php echo esc_url($delete_url); ?>" class="delete-link" onclick="return confirm('<?php esc_attr_e('Are you sure you want to permanently delete this calendar?', 'ffcertificate'); ?>');">
                                        <?php esc_html_e('Delete', 'ffcertificate'); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Styles in ffc-audience-admin.css -->
        <?php
    }

    /**
     * Render calendar form
     *
     * @param int $id Schedule ID (0 for new)
     * @return void
     */
    private function render_form(int $id): void {
        $schedule = null;
        $page_title = __('Add New Calendar', 'ffcertificate');

        if ($id > 0) {
            $schedule = AudienceScheduleRepository::get_by_id($id);
            if (!$schedule) {
                wp_die(esc_html__('Calendar not found.', 'ffcertificate'));
            }
            $page_title = __('Edit Calendar', 'ffcertificate');
        }

        $back_url = admin_url('admin.php?page=' . $this->menu_slug . '-calendars');

        ?>
        <h1><?php echo esc_html($page_title); ?></h1>
        <a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to Calendars', 'ffcertificate'); ?></a>

        <?php settings_errors('ffc_audience'); ?>

        <form method="post" action="" class="ffc-form">
            <?php wp_nonce_field('save_schedule', 'ffc_schedule_nonce'); ?>
            <input type="hidden" name="schedule_id" value="<?php echo esc_attr((string) $id); ?>">
            <input type="hidden" name="ffc_action" value="save_schedule">

            <table class="form-table" role="presentation"><tbody>
                <?php if ($id > 0) : ?>
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Calendar ID', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <code><?php echo esc_html((string) $id); ?></code>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Shortcode', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <code>[ffc_audience schedule_id="<?php echo esc_attr((string) $id); ?>"]</code>
                        <p class="description">
                            <?php esc_html_e('Use this shortcode to display the calendar on any page or post.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row">
                        <label for="schedule_name"><?php esc_html_e('Name', 'ffcertificate'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="text" name="schedule_name" id="schedule_name" class="regular-text"
                               value="<?php echo esc_attr($schedule->name ?? ''); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="schedule_description"><?php esc_html_e('Description', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <textarea name="schedule_description" id="schedule_description" rows="3" class="large-text"><?php echo esc_textarea($schedule->description ?? ''); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="schedule_environment_label"><?php esc_html_e('Environment Label', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="schedule_environment_label" id="schedule_environment_label" class="regular-text"
                               value="<?php echo esc_attr($schedule->environment_label ?? ''); ?>"
                               placeholder="<?php esc_attr_e('Environments', 'ffcertificate'); ?>">
                        <p class="description">
                            <?php esc_html_e('Custom label for the environments of this calendar (e.g. "Rooms", "Categories", "Services"). Leave empty to use the default "Environments".', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="schedule_visibility"><?php esc_html_e('Visibility', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="schedule_visibility" id="schedule_visibility">
                            <option value="private" <?php selected($schedule->visibility ?? 'private', 'private'); ?>>
                                <?php esc_html_e('Private', 'ffcertificate'); ?>
                            </option>
                            <option value="public" <?php selected($schedule->visibility ?? '', 'public'); ?>>
                                <?php esc_html_e('Public', 'ffcertificate'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Public: visible to everyone. Private: only visible to logged-in users who belong to an audience group. Scheduling is always restricted to authorized members.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="schedule_future_days"><?php esc_html_e('Future Days Limit', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="schedule_future_days" id="schedule_future_days" class="small-text"
                               value="<?php echo esc_attr($schedule->future_days_limit ?? ''); ?>" min="1" max="365">
                        <p class="description">
                            <?php esc_html_e('Maximum days in advance that non-admin users can book. Leave empty for no limit.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Notifications', 'ffcertificate'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="schedule_notify_booking" value="1"
                                       <?php checked($schedule->notify_on_booking ?? 1, 1); ?>>
                                <?php esc_html_e('Send email on new booking', 'ffcertificate'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="schedule_notify_cancel" value="1"
                                       <?php checked($schedule->notify_on_cancellation ?? 1, 1); ?>>
                                <?php esc_html_e('Send email on cancellation', 'ffcertificate'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="schedule_include_ics" value="1"
                                       <?php checked($schedule->include_ics ?? 0, 1); ?>>
                                <?php esc_html_e('Include .ics calendar file in emails', 'ffcertificate'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Event List', 'ffcertificate'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="schedule_show_event_list" value="1"
                                       <?php checked($schedule->show_event_list ?? 0, 1); ?>>
                                <?php esc_html_e('Show event list alongside the calendar', 'ffcertificate'); ?>
                            </label>
                        </fieldset>
                        <br>
                        <label for="schedule_event_list_position"><?php esc_html_e('Position', 'ffcertificate'); ?></label>
                        <select name="schedule_event_list_position" id="schedule_event_list_position">
                            <option value="side" <?php selected($schedule->event_list_position ?? 'side', 'side'); ?>>
                                <?php esc_html_e('Side (responsive)', 'ffcertificate'); ?>
                            </option>
                            <option value="below" <?php selected($schedule->event_list_position ?? '', 'below'); ?>>
                                <?php esc_html_e('Below', 'ffcertificate'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Side: appears next to the calendar on desktop, moves below on mobile. Below: always below the calendar.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="schedule_booking_label_singular"><?php esc_html_e('Booking Badge Label', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" name="schedule_booking_label_singular" id="schedule_booking_label_singular" class="regular-text" style="max-width: 160px;"
                                   value="<?php echo esc_attr($schedule->booking_label_singular ?? ''); ?>"
                                   placeholder="<?php echo esc_attr__('booking', 'ffcertificate'); ?>">
                            <span>/</span>
                            <input type="text" name="schedule_booking_label_plural" id="schedule_booking_label_plural" class="regular-text" style="max-width: 160px;"
                                   value="<?php echo esc_attr($schedule->booking_label_plural ?? ''); ?>"
                                   placeholder="<?php echo esc_attr__('bookings', 'ffcertificate'); ?>">
                        </div>
                        <p class="description">
                            <?php esc_html_e('Custom singular/plural label for the booking count shown in calendar day cells (e.g. "session" / "sessions"). Leave empty to use default.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="schedule_audience_badge_format"><?php esc_html_e('Audience Badge Format', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="schedule_audience_badge_format" id="schedule_audience_badge_format">
                            <option value="name" <?php selected($schedule->audience_badge_format ?? 'name', 'name'); ?>>
                                <?php esc_html_e('Name only', 'ffcertificate'); ?>
                            </option>
                            <option value="parent_name" <?php selected($schedule->audience_badge_format ?? '', 'parent_name'); ?>>
                                <?php esc_html_e('Parent: Name', 'ffcertificate'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('How audience badges are displayed. "Name only" shows just the audience name. "Parent: Name" prefixes with the parent category when available.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="schedule_status"><?php esc_html_e('Status', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="schedule_status" id="schedule_status">
                            <option value="active" <?php selected($schedule->status ?? 'active', 'active'); ?>>
                                <?php esc_html_e('Active', 'ffcertificate'); ?>
                            </option>
                            <option value="inactive" <?php selected($schedule->status ?? '', 'inactive'); ?>>
                                <?php esc_html_e('Inactive', 'ffcertificate'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
            </tbody></table>

            <?php submit_button($id > 0 ? __('Update Calendar', 'ffcertificate') : __('Create Calendar', 'ffcertificate')); ?>
        </form>

        <?php if ($id > 0) : ?>
            <!-- User Access Section -->
            <hr>
            <h2><?php esc_html_e('User Access & Permissions', 'ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Manage which users have access to this calendar and what they can do. For private calendars, only listed users can see and book. For public calendars, these permissions control who can book and cancel.', 'ffcertificate'); ?>
            </p>

            <div class="ffc-user-access-add" style="margin-bottom: 20px; padding: 15px; background: #f6f7f7; border: 1px solid #ddd;">
                <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 250px;">
                        <label for="ffc-user-search"><strong><?php esc_html_e('Add User', 'ffcertificate'); ?></strong></label><br>
                        <input type="text" id="ffc-user-search" class="regular-text" placeholder="<?php esc_attr_e('Search by name or email...', 'ffcertificate'); ?>" autocomplete="off" style="width: 100%;">
                        <div id="ffc-user-search-results" style="display: none; position: absolute; z-index: 100; background: #fff; border: 1px solid #ddd; max-height: 200px; overflow-y: auto; width: 300px;"></div>
                    </div>
                    <div>
                        <button type="button" id="ffc-add-user-btn" class="button button-secondary" disabled>
                            <?php esc_html_e('Add User', 'ffcertificate'); ?>
                        </button>
                    </div>
                </div>
                <input type="hidden" id="ffc-selected-user-id" value="">
            </div>

            <?php
            $permissions = AudienceScheduleRepository::get_all_permissions($id);
            ?>
            <table class="wp-list-table widefat fixed striped" id="ffc-permissions-table">
                <thead>
                    <tr>
                        <th style="width: 30%;"><?php esc_html_e('User', 'ffcertificate'); ?></th>
                        <th style="width: 15%;"><?php esc_html_e('Can Book', 'ffcertificate'); ?></th>
                        <th style="width: 15%;"><?php esc_html_e('Cancel Others', 'ffcertificate'); ?></th>
                        <th style="width: 15%;"><?php esc_html_e('Override Conflicts', 'ffcertificate'); ?></th>
                        <th style="width: 10%;"><?php esc_html_e('Actions', 'ffcertificate'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($permissions)) : ?>
                        <tr id="ffc-no-permissions-row">
                            <td colspan="5"><em><?php esc_html_e('No users have been granted access yet.', 'ffcertificate'); ?></em></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($permissions as $perm) :
                            $user = get_userdata((int) $perm->user_id);
                            if (!$user) continue;
                        ?>
                            <tr data-user-id="<?php echo esc_attr($perm->user_id); ?>">
                                <td>
                                    <strong><?php echo esc_html($user->display_name); ?></strong>
                                    <br><span class="description"><?php echo esc_html($user->user_email); ?></span>
                                </td>
                                <td>
                                    <input type="checkbox" class="ffc-perm-toggle" data-perm="can_book" <?php checked($perm->can_book, 1); ?>>
                                </td>
                                <td>
                                    <input type="checkbox" class="ffc-perm-toggle" data-perm="can_cancel_others" <?php checked($perm->can_cancel_others, 1); ?>>
                                </td>
                                <td>
                                    <input type="checkbox" class="ffc-perm-toggle" data-perm="can_override_conflicts" <?php checked($perm->can_override_conflicts, 1); ?>>
                                </td>
                                <td>
                                    <button type="button" class="button button-small button-link-delete ffc-remove-user-btn"><?php esc_html_e('Remove', 'ffcertificate'); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <script>
            jQuery(document).ready(function($) {
                var scheduleId = <?php echo (int) $id; ?>;
                var permNonce = '<?php echo esc_js(wp_create_nonce('ffc_schedule_permissions')); ?>';
                var searchNonce = '<?php echo esc_js(wp_create_nonce('ffc_search_users')); ?>';
                var searchTimer = null;
                var selectedUserId = 0;

                // User search
                $('#ffc-user-search').on('input', function() {
                    clearTimeout(searchTimer);
                    var query = $(this).val().trim();
                    if (query.length < 2) {
                        $('#ffc-user-search-results').hide();
                        return;
                    }
                    searchTimer = setTimeout(function() {
                        $.get(ajaxurl, {
                            action: 'ffc_search_users',
                            query: query,
                            nonce: searchNonce
                        }, function(response) {
                            if (response.success && response.data.length > 0) {
                                var html = '';
                                var existingIds = [];
                                $('#ffc-permissions-table tbody tr[data-user-id]').each(function() {
                                    existingIds.push(parseInt($(this).data('user-id')));
                                });
                                $.each(response.data, function(i, user) {
                                    var disabled = existingIds.indexOf(user.id) !== -1;
                                    html += '<div class="ffc-user-result' + (disabled ? ' ffc-user-exists' : '') + '" data-id="' + user.id + '" data-name="' + $('<span>').text(user.name).html() + '" style="padding: 8px 12px; cursor: ' + (disabled ? 'default' : 'pointer') + '; border-bottom: 1px solid #eee;' + (disabled ? ' opacity: 0.5;' : '') + '">';
                                    html += '<strong>' + $('<span>').text(user.name).html() + '</strong>';
                                    html += '<br><small>' + $('<span>').text(user.email).html() + '</small>';
                                    if (disabled) html += ' <em>(<?php echo esc_js(__('already added', 'ffcertificate')); ?>)</em>';
                                    html += '</div>';
                                });
                                $('#ffc-user-search-results').html(html).show();
                            } else {
                                $('#ffc-user-search-results').html('<div style="padding: 8px 12px; color: #666;"><em><?php echo esc_js(__('No users found.', 'ffcertificate')); ?></em></div>').show();
                            }
                        });
                    }, 300);
                });

                // Select user from results
                $(document).on('click', '.ffc-user-result:not(.ffc-user-exists)', function() {
                    selectedUserId = parseInt($(this).data('id'));
                    $('#ffc-user-search').val($(this).data('name'));
                    $('#ffc-selected-user-id').val(selectedUserId);
                    $('#ffc-add-user-btn').prop('disabled', false);
                    $('#ffc-user-search-results').hide();
                });

                // Hide results on outside click
                $(document).on('click', function(e) {
                    if (!$(e.target).closest('#ffc-user-search, #ffc-user-search-results').length) {
                        $('#ffc-user-search-results').hide();
                    }
                });

                // Add user
                $('#ffc-add-user-btn').on('click', function() {
                    if (!selectedUserId) return;
                    var btn = $(this);
                    btn.prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'ffc_audience_add_user_permission',
                        schedule_id: scheduleId,
                        user_id: selectedUserId,
                        _wpnonce: permNonce
                    }, function(response) {
                        if (response.success) {
                            $('#ffc-no-permissions-row').remove();
                            $('#ffc-permissions-table tbody').append(response.data.html);
                            $('#ffc-user-search').val('');
                            selectedUserId = 0;
                            $('#ffc-selected-user-id').val('');
                        } else {
                            alert(response.data.message || '<?php echo esc_js(__('Error adding user.', 'ffcertificate')); ?>');
                            btn.prop('disabled', false);
                        }
                    });
                });

                // Toggle permission
                $(document).on('change', '.ffc-perm-toggle', function() {
                    var row = $(this).closest('tr');
                    var userId = row.data('user-id');
                    var perm = $(this).data('perm');
                    var value = $(this).is(':checked') ? 1 : 0;

                    $.post(ajaxurl, {
                        action: 'ffc_audience_update_user_permission',
                        schedule_id: scheduleId,
                        user_id: userId,
                        permission: perm,
                        value: value,
                        _wpnonce: permNonce
                    });
                });

                // Remove user
                $(document).on('click', '.ffc-remove-user-btn', function() {
                    if (!confirm('<?php echo esc_js(__('Remove this user\'s access?', 'ffcertificate')); ?>')) return;
                    var row = $(this).closest('tr');
                    var userId = row.data('user-id');

                    $.post(ajaxurl, {
                        action: 'ffc_audience_remove_user_permission',
                        schedule_id: scheduleId,
                        user_id: userId,
                        _wpnonce: permNonce
                    }, function(response) {
                        if (response.success) {
                            row.fadeOut(300, function() {
                                $(this).remove();
                                if ($('#ffc-permissions-table tbody tr').length === 0) {
                                    $('#ffc-permissions-table tbody').html('<tr id="ffc-no-permissions-row"><td colspan="5"><em><?php echo esc_js(__('No users have been granted access yet.', 'ffcertificate')); ?></em></td></tr>');
                                }
                            });
                        }
                    });
                });
            });
            </script>

            <!-- Holidays Section -->
            <hr>
            <h2><?php esc_html_e('Holidays / Closed Dates', 'ffcertificate'); ?></h2>
            <p class="description"><?php esc_html_e('Add specific dates when the calendar will be closed (holidays, maintenance, etc.).', 'ffcertificate'); ?></p>

            <form method="post" action="" class="ffc-holiday-form" style="margin-bottom: 20px; padding: 15px; background: #f6f7f7; border: 1px solid #ddd;">
                <?php wp_nonce_field('add_holiday', 'ffc_holiday_nonce'); ?>
                <input type="hidden" name="schedule_id" value="<?php echo esc_attr((string) $id); ?>">
                <input type="hidden" name="ffc_action" value="add_holiday">

                <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                    <div>
                        <label for="holiday_date"><strong><?php esc_html_e('Date', 'ffcertificate'); ?></strong></label><br>
                        <input type="date" name="holiday_date" id="holiday_date" required style="width: 180px;">
                    </div>
                    <div style="flex: 1; min-width: 200px;">
                        <label for="holiday_description"><strong><?php esc_html_e('Description (optional)', 'ffcertificate'); ?></strong></label><br>
                        <input type="text" name="holiday_description" id="holiday_description" class="regular-text" placeholder="<?php esc_attr_e('e.g., Christmas Day', 'ffcertificate'); ?>">
                    </div>
                    <div>
                        <?php submit_button(__('Add Holiday', 'ffcertificate'), 'secondary', 'submit', false); ?>
                    </div>
                </div>
            </form>

            <?php
            $holidays = AudienceEnvironmentRepository::get_holidays($id);
            if (!empty($holidays)) :
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 150px;"><?php esc_html_e('Date', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Description', 'ffcertificate'); ?></th>
                        <th style="width: 100px;"><?php esc_html_e('Actions', 'ffcertificate'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($holidays as $holiday) : ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($holiday->holiday_date))); ?></td>
                            <td><?php echo esc_html($holiday->description ?: '—'); ?></td>
                            <td>
                                <?php
                                $delete_url = wp_nonce_url(
                                    admin_url('admin.php?page=' . $this->menu_slug . '-calendars&action=edit&id=' . $id . '&delete_holiday=' . $holiday->id),
                                    'delete_holiday_' . $holiday->id
                                );
                                ?>
                                <a href="<?php echo esc_url($delete_url); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e('Delete this holiday?', 'ffcertificate'); ?>');">
                                    <?php esc_html_e('Delete', 'ffcertificate'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
                <p><em><?php esc_html_e('No holidays defined yet.', 'ffcertificate'); ?></em></p>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Styles in ffc-audience-admin.css -->
        <?php
    }

    /**
     * Handle calendar actions (save, delete)
     *
     * @return void
     */
    public function handle_actions(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Show feedback for redirect-based actions
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['message'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $msg = sanitize_text_field(wp_unslash($_GET['message']));
            $messages = array(
                'created'         => __('Calendar created successfully.', 'ffcertificate'),
                'deactivated'     => __('Calendar deactivated successfully.', 'ffcertificate'),
                'deleted'         => __('Calendar deleted successfully.', 'ffcertificate'),
                'holiday_deleted' => __('Holiday deleted successfully.', 'ffcertificate'),
            );
            if (isset($messages[$msg])) {
                add_settings_error('ffc_audience', 'ffc_message', $messages[$msg], 'success');
            }
        }

        // Handle save
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'save_schedule') {
            if (!isset($_POST['ffc_schedule_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_schedule_nonce'])), 'save_schedule')) {
                return;
            }

            $id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 0;
            $data = array(
                'name' => isset($_POST['schedule_name']) ? sanitize_text_field(wp_unslash($_POST['schedule_name'])) : '',
                'description' => isset($_POST['schedule_description']) ? sanitize_textarea_field(wp_unslash($_POST['schedule_description'])) : '',
                'environment_label' => isset($_POST['schedule_environment_label']) ? sanitize_text_field(wp_unslash($_POST['schedule_environment_label'])) : null,
                'visibility' => isset($_POST['schedule_visibility']) ? sanitize_text_field(wp_unslash($_POST['schedule_visibility'])) : 'private',
                'future_days_limit' => isset($_POST['schedule_future_days']) && $_POST['schedule_future_days'] !== '' ? absint($_POST['schedule_future_days']) : null,
                'notify_on_booking' => isset($_POST['schedule_notify_booking']) ? 1 : 0,
                'notify_on_cancellation' => isset($_POST['schedule_notify_cancel']) ? 1 : 0,
                'include_ics' => isset($_POST['schedule_include_ics']) ? 1 : 0,
                'show_event_list' => isset($_POST['schedule_show_event_list']) ? 1 : 0,
                'event_list_position' => isset($_POST['schedule_event_list_position']) && in_array($_POST['schedule_event_list_position'], array('side', 'below'), true)
                    ? sanitize_text_field(wp_unslash($_POST['schedule_event_list_position']))
                    : 'side',
                'audience_badge_format' => isset($_POST['schedule_audience_badge_format']) && in_array($_POST['schedule_audience_badge_format'], array('name', 'parent_name'), true)
                    ? sanitize_text_field(wp_unslash($_POST['schedule_audience_badge_format']))
                    : 'name',
                'booking_label_singular' => !empty($_POST['schedule_booking_label_singular'])
                    ? sanitize_text_field(wp_unslash($_POST['schedule_booking_label_singular']))
                    : null,
                'booking_label_plural' => !empty($_POST['schedule_booking_label_plural'])
                    ? sanitize_text_field(wp_unslash($_POST['schedule_booking_label_plural']))
                    : null,
                'status' => isset($_POST['schedule_status']) ? sanitize_text_field(wp_unslash($_POST['schedule_status'])) : 'active',
            );

            if ($id > 0) {
                AudienceScheduleRepository::update($id, $data);
                add_settings_error('ffc_audience', 'ffc_message', __('Calendar updated successfully.', 'ffcertificate'), 'success');
            } else {
                $new_id = AudienceScheduleRepository::create($data);
                if ($new_id) {
                    wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-calendars&action=edit&id=' . $new_id . '&message=created'));
                    exit;
                }
            }
        }

        // Handle deactivate (active items get deactivated instead of deleted)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['action']) && $_GET['action'] === 'deactivate' && isset($_GET['id'])) {
            $id = absint($_GET['id']);
            if (wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'deactivate_schedule_' . $id)) {
                AudienceScheduleRepository::update($id, array('status' => 'inactive'));
                wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-calendars&message=deactivated'));
                exit;
            }
        }

        // Handle delete (only inactive items can be permanently deleted)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            $id = absint($_GET['id']);
            if (wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'delete_schedule_' . $id)) {
                $schedule = AudienceScheduleRepository::get_by_id($id);
                if ($schedule && $schedule->status !== 'active') {
                    AudienceScheduleRepository::delete($id);
                    wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-calendars&message=deleted'));
                    exit;
                }
            }
        }

        // Handle add holiday
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'add_holiday') {
            if (!isset($_POST['ffc_holiday_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_holiday_nonce'])), 'add_holiday')) {
                return;
            }

            $schedule_id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 0;
            $holiday_date = isset($_POST['holiday_date']) ? sanitize_text_field(wp_unslash($_POST['holiday_date'])) : '';
            $description = isset($_POST['holiday_description']) ? sanitize_text_field(wp_unslash($_POST['holiday_description'])) : '';

            if ($schedule_id > 0 && $holiday_date) {
                AudienceEnvironmentRepository::add_holiday($schedule_id, $holiday_date, $description);
                add_settings_error('ffc_audience', 'ffc_message', __('Holiday added successfully.', 'ffcertificate'), 'success');
            }
        }

        // Handle delete holiday
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['delete_holiday']) && isset($_GET['id'])) {
            $holiday_id = absint($_GET['delete_holiday']);
            $schedule_id = absint($_GET['id']);
            if (wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'delete_holiday_' . $holiday_id)) {
                AudienceEnvironmentRepository::remove_holiday($holiday_id);
                wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-calendars&action=edit&id=' . $schedule_id . '&message=holiday_deleted'));
                exit;
            }
        }
    }
}
