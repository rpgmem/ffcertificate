# Calendar Deletion Behavior

## Overview

When a calendar is deleted, the plugin automatically handles associated appointments to prevent orphaned bookings and maintain data integrity.

## Default Behavior

By default, when you delete a calendar:

1. ✅ **All future appointments are cancelled** (appointments with dates >= today)
2. ✅ **Affected users receive email notifications** about the cancellation
3. ✅ **Past appointments are preserved** for historical records
4. ✅ **All cancellations are logged** with the reason "Calendar [name] was deleted"

## Customization via Filters

The behavior can be customized using WordPress filters in your theme's `functions.php` or a custom plugin.

### Disable Automatic Appointment Cancellation

To keep appointments when deleting a calendar (not recommended):

```php
add_filter('ffc_cancel_appointments_on_calendar_delete', '__return_false');
```

**Warning**: This will leave appointments orphaned without a valid calendar.

### Conditional Cancellation

Cancel appointments only for specific calendars:

```php
add_filter('ffc_cancel_appointments_on_calendar_delete', function($should_cancel, $calendar_id, $post_id) {
    // Don't cancel appointments for calendar ID 5
    if ($calendar_id === 5) {
        return false;
    }
    return $should_cancel;
}, 10, 3);
```

### Disable Email Notifications

To prevent sending notification emails to users (not recommended):

```php
add_filter('ffc_send_calendar_deletion_notification', '__return_false');
```

### Conditional Email Notifications

Send notifications only for certain conditions:

```php
add_filter('ffc_send_calendar_deletion_notification', function($should_send, $appointment) {
    // Don't send emails for appointments more than 30 days away
    $appointment_date = strtotime($appointment['appointment_date']);
    $days_until = ($appointment_date - current_time('timestamp')) / DAY_IN_SECONDS;

    if ($days_until > 30) {
        return false;
    }

    return $should_send;
}, 10, 2);
```

## Email Notification Content

The notification email includes:

- **Subject**: `[Site Name] Appointment Cancelled - Calendar No Longer Available`
- **Content**:
  - Cancellation reason (calendar deleted)
  - Original appointment date and time
  - Calendar name
  - Apology message

### Customize Email Content

You can use WordPress email filters to customize the notification:

```php
add_filter('wp_mail', function($args) {
    // Check if this is a calendar deletion notification
    if (strpos($args['subject'], 'Calendar No Longer Available') !== false) {
        // Customize the message
        $args['message'] = "Custom message here...";

        // Add custom headers
        $args['headers'][] = 'From: Your Name <your-email@example.com>';
    }

    return $args;
});
```

## Activity Logging

All calendar deletions and appointment cancellations are logged with:

- Calendar ID and title
- Number of appointments cancelled
- User ID who deleted the calendar
- Timestamp
- Email notifications sent

View logs in: **FFC Settings > Activity Log**

## Best Practices

### Before Deleting a Calendar

1. **Review active appointments** in the calendar
2. **Export appointment data** if needed (CSV export)
3. **Inform users** ahead of time if possible
4. **Consider disabling** the calendar instead of deleting it

### After Deleting a Calendar

1. **Check Activity Log** to confirm cancellations
2. **Verify email notifications** were sent
3. **Monitor support requests** from affected users
4. **Review backup/export data** if needed

## Alternative: Disable Instead of Delete

Instead of deleting, you can disable a calendar:

1. Edit the calendar
2. Set **Status** to "Inactive"
3. Calendar won't accept new bookings
4. Existing appointments remain valid

## Troubleshooting

### Emails Not Sending

Check:
- WordPress `wp_mail()` configuration
- SMTP plugin if using one
- Email logs in Activity Log
- Spam/junk folders

### Appointments Not Cancelled

Check:
- Filters that might be preventing cancellation
- Activity Log for errors
- Database appointments table directly

### Notification Content Issues

- Use `wp_mail` filter to customize
- Check email encoding settings
- Test with different email clients

## Technical Details

### Database Operations

When a calendar is deleted:

1. Future appointments query:
   ```sql
   SELECT * FROM wp_ffc_appointments
   WHERE calendar_id = X
   AND appointment_date >= TODAY
   AND status IN ('pending', 'confirmed')
   ```

2. Each appointment is cancelled via repository:
   ```php
   $appointment_repo->cancel($id, $user_id, $reason);
   ```

3. Calendar record is deleted:
   ```php
   $calendar_repository->delete($calendar_id);
   ```

### Hooks Execution Order

1. `before_delete_post` (WordPress core)
2. `ffc_cancel_appointments_on_calendar_delete` (filter)
3. For each appointment:
   - `$appointment_repo->cancel()`
   - `ffc_send_calendar_deletion_notification` (filter)
   - `wp_mail()`
4. Calendar deletion
5. Activity logging

## Support

For issues or questions:
- GitHub: https://github.com/anthropics/claude-code/issues
- Check Activity Log for detailed error messages
- Enable WP_DEBUG for development environments
