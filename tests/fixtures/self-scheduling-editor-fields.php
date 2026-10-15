<?php
// Captured from the pre-#1761 renderer (origin/develop 0eba0ec) by SelfSchedulingEditorFieldParityTest::render_normal_column() and tests/Support/FormControls.php. Never regenerate it from the new renderer: that would compare the screen with itself.
return array (
  'defaults' => 
  array (
	'controls' => 
	array (
	  0 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-email-restore-default',
		  'data-default-key' => 'selfsched_confirmation_body',
		  'data-editor' => 'user_confirmation_body',
		  'type' => 'button',
		),
		'text' => 'Restore Default Text',
	  ),
	  1 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-icon-plus',
		  'id' => 'ffc-add-custom-slot',
		  'type' => 'button',
		),
		'text' => 'Add Block',
	  ),
	  2 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-icon-plus',
		  'id' => 'ffc-add-working-hour',
		  'type' => 'button',
		),
		'text' => 'Add Working Hours',
	  ),
	  3 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-remove-hour',
		  'type' => 'button',
		),
		'text' => 'Remove',
	  ),
	  4 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-remove-hour',
		  'type' => 'button',
		),
		'text' => 'Remove',
	  ),
	  5 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-remove-hour',
		  'type' => 'button',
		),
		'text' => 'Remove',
	  ),
	  6 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-remove-hour',
		  'type' => 'button',
		),
		'text' => 'Remove',
	  ),
	  7 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-remove-hour',
		  'type' => 'button',
		),
		'text' => 'Remove',
	  ),
	  8 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'checked' => 'checked',
		  'class' => 'ffc-schedule-type-radio',
		  'name' => 'ffc_self_scheduling_config[schedule_type]',
		  'type' => 'radio',
		  'value' => 'regular',
		),
	  ),
	  9 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'checked' => 'checked',
		  'id' => 'admin_bypass',
		  'name' => 'ffc_self_scheduling_config[admin_bypass]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  10 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'checked' => 'checked',
		  'id' => 'allow_cancellation',
		  'name' => 'ffc_self_scheduling_config[allow_cancellation]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  11 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'class' => 'ffc-schedule-type-radio',
		  'name' => 'ffc_self_scheduling_config[schedule_type]',
		  'type' => 'radio',
		  'value' => 'custom',
		),
	  ),
	  12 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'class' => 'large-text',
		  'id' => 'admin_emails',
		  'name' => 'ffc_self_scheduling_email_config[admin_emails]',
		  'placeholder' => 'site@example.com',
		  'type' => 'text',
		  'value' => '',
		),
	  ),
	  13 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'class' => 'large-text',
		  'id' => 'user_confirmation_subject',
		  'name' => 'ffc_self_scheduling_email_config[user_confirmation_subject]',
		  'type' => 'text',
		  'value' => 'Appointment Confirmation - {{calendar_title}}',
		),
	  ),
	  14 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'advance_booking_max',
		  'max' => '365',
		  'min' => '1',
		  'name' => 'ffc_self_scheduling_config[advance_booking_max]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '30',
		),
	  ),
	  15 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'advance_booking_min',
		  'max' => '720',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[advance_booking_min]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '0',
		),
	  ),
	  16 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'cancellation_min_hours',
		  'max' => '168',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[cancellation_min_hours]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '24',
		),
	  ),
	  17 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'ffc_self_scheduling_config_nonce',
		  'name' => 'ffc_self_scheduling_config_nonce',
		  'type' => 'hidden',
		  'value' => 'nonce:ffc_self_scheduling_config_nonce',
		),
	  ),
	  18 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'ffc_send_admin_notification',
		  'name' => 'ffc_self_scheduling_email_config[send_admin_notification]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  19 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'ffc_send_approval_notification',
		  'name' => 'ffc_self_scheduling_email_config[send_approval_notification]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  20 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'ffc_send_cancellation_notification',
		  'name' => 'ffc_self_scheduling_email_config[send_cancellation_notification]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  21 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'ffc_send_reminder',
		  'name' => 'ffc_self_scheduling_email_config[send_reminder]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  22 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'ffc_send_user_confirmation',
		  'name' => 'ffc_self_scheduling_email_config[send_user_confirmation]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  23 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'max_appointments_per_slot',
		  'max' => '100',
		  'min' => '1',
		  'name' => 'ffc_self_scheduling_config[max_appointments_per_slot]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '1',
		),
	  ),
	  24 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'max_blocks_per_user',
		  'max' => '9999',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[max_blocks_per_user]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '0',
		),
	  ),
	  25 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'minimum_interval_between_bookings',
		  'max' => '720',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[minimum_interval_between_bookings]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '24',
		),
	  ),
	  26 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'reminder_hours_before',
		  'max' => '168',
		  'min' => '1',
		  'name' => 'ffc_self_scheduling_email_config[reminder_hours_before]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '24',
		),
	  ),
	  27 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'requires_approval',
		  'name' => 'ffc_self_scheduling_config[requires_approval]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  28 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'restrict_booking_to_hours',
		  'name' => 'ffc_self_scheduling_config[restrict_booking_to_hours]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  29 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'restrict_viewing_to_hours',
		  'name' => 'ffc_self_scheduling_config[restrict_viewing_to_hours]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  30 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'slot_duration',
		  'max' => '480',
		  'min' => '5',
		  'name' => 'ffc_self_scheduling_config[slot_duration]',
		  'required' => '',
		  'step' => '5',
		  'type' => 'number',
		  'value' => '30',
		),
	  ),
	  31 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'slot_interval',
		  'max' => '120',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[slot_interval]',
		  'required' => '',
		  'step' => '5',
		  'type' => 'number',
		  'value' => '0',
		),
	  ),
	  32 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'slots_per_day',
		  'max' => '200',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[slots_per_day]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '0',
		),
	  ),
	  33 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'waitlist_capacity',
		  'max' => '9999',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[waitlist_capacity]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '0',
		),
	  ),
	  34 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'waitlist_enabled',
		  'name' => 'ffc_self_scheduling_config[waitlist_enabled]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  35 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[0][end]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '17:00',
		),
	  ),
	  36 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[0][start]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '09:00',
		),
	  ),
	  37 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[1][end]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '17:00',
		),
	  ),
	  38 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[1][start]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '09:00',
		),
	  ),
	  39 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[2][end]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '17:00',
		),
	  ),
	  40 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[2][start]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '09:00',
		),
	  ),
	  41 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[3][end]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '17:00',
		),
	  ),
	  42 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[3][start]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '09:00',
		),
	  ),
	  43 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[4][end]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '17:00',
		),
	  ),
	  44 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[4][start]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '09:00',
		),
	  ),
	  45 => 
	  array (
		'tag' => 'select',
		'attrs' => 
		array (
		  'id' => 'calendar_status',
		  'name' => 'ffc_self_scheduling_config[status]',
		),
		'options' => 
		array (
		  0 => 
		  array (
			'value' => 'active',
			'selected' => true,
			'text' => 'Active',
		  ),
		  1 => 
		  array (
			'value' => 'inactive',
			'selected' => false,
			'text' => 'Inactive',
		  ),
		  2 => 
		  array (
			'value' => 'archived',
			'selected' => false,
			'text' => 'Archived',
		  ),
		),
	  ),
	  46 => 
	  array (
		'tag' => 'select',
		'attrs' => 
		array (
		  'id' => 'ffc_scheduling_visibility',
		  'name' => 'ffc_self_scheduling_config[scheduling_visibility]',
		),
		'options' => 
		array (
		  0 => 
		  array (
			'value' => 'public',
			'selected' => true,
			'text' => 'Public',
		  ),
		  1 => 
		  array (
			'value' => 'private',
			'selected' => false,
			'text' => 'Private',
		  ),
		),
	  ),
	  47 => 
	  array (
		'tag' => 'select',
		'attrs' => 
		array (
		  'id' => 'ffc_visibility',
		  'name' => 'ffc_self_scheduling_config[visibility]',
		),
		'options' => 
		array (
		  0 => 
		  array (
			'value' => 'public',
			'selected' => true,
			'text' => 'Public',
		  ),
		  1 => 
		  array (
			'value' => 'private',
			'selected' => false,
			'text' => 'Private',
		  ),
		),
	  ),
	  48 => 
	  array (
		'tag' => 'select',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[0][day]',
		  'required' => '',
		),
		'options' => 
		array (
		  0 => 
		  array (
			'value' => '0',
			'selected' => false,
			'text' => 'Sunday',
		  ),
		  1 => 
		  array (
			'value' => '1',
			'selected' => true,
			'text' => 'Monday',
		  ),
		  2 => 
		  array (
			'value' => '2',
			'selected' => false,
			'text' => 'Tuesday',
		  ),
		  3 => 
		  array (
			'value' => '3',
			'selected' => false,
			'text' => 'Wednesday',
		  ),
		  4 => 
		  array (
			'value' => '4',
			'selected' => false,
			'text' => 'Thursday',
		  ),
		  5 => 
		  array (
			'value' => '5',
			'selected' => false,
			'text' => 'Friday',
		  ),
		  6 => 
		  array (
			'value' => '6',
			'selected' => false,
			'text' => 'Saturday',
		  ),
		),
	  ),
	  49 => 
	  array (
		'tag' => 'select',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[1][day]',
		  'required' => '',
		),
		'options' => 
		array (
		  0 => 
		  array (
			'value' => '0',
			'selected' => false,
			'text' => 'Sunday',
		  ),
		  1 => 
		  array (
			'value' => '1',
			'selected' => false,
			'text' => 'Monday',
		  ),
		  2 => 
		  array (
			'value' => '2',
			'selected' => true,
			'text' => 'Tuesday',
		  ),
		  3 => 
		  array (
			'value' => '3',
			'selected' => false,
			'text' => 'Wednesday',
		  ),
		  4 => 
		  array (
			'value' => '4',
			'selected' => false,
			'text' => 'Thursday',
		  ),
		  5 => 
		  array (
			'value' => '5',
			'selected' => false,
			'text' => 'Friday',
		  ),
		  6 => 
		  array (
			'value' => '6',
			'selected' => false,
			'text' => 'Saturday',
		  ),
		),
	  ),
	  50 => 
	  array (
		'tag' => 'select',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[2][day]',
		  'required' => '',
		),
		'options' => 
		array (
		  0 => 
		  array (
			'value' => '0',
			'selected' => false,
			'text' => 'Sunday',
		  ),
		  1 => 
		  array (
			'value' => '1',
			'selected' => false,
			'text' => 'Monday',
		  ),
		  2 => 
		  array (
			'value' => '2',
			'selected' => false,
			'text' => 'Tuesday',
		  ),
		  3 => 
		  array (
			'value' => '3',
			'selected' => true,
			'text' => 'Wednesday',
		  ),
		  4 => 
		  array (
			'value' => '4',
			'selected' => false,
			'text' => 'Thursday',
		  ),
		  5 => 
		  array (
			'value' => '5',
			'selected' => false,
			'text' => 'Friday',
		  ),
		  6 => 
		  array (
			'value' => '6',
			'selected' => false,
			'text' => 'Saturday',
		  ),
		),
	  ),
	  51 => 
	  array (
		'tag' => 'select',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[3][day]',
		  'required' => '',
		),
		'options' => 
		array (
		  0 => 
		  array (
			'value' => '0',
			'selected' => false,
			'text' => 'Sunday',
		  ),
		  1 => 
		  array (
			'value' => '1',
			'selected' => false,
			'text' => 'Monday',
		  ),
		  2 => 
		  array (
			'value' => '2',
			'selected' => false,
			'text' => 'Tuesday',
		  ),
		  3 => 
		  array (
			'value' => '3',
			'selected' => false,
			'text' => 'Wednesday',
		  ),
		  4 => 
		  array (
			'value' => '4',
			'selected' => true,
			'text' => 'Thursday',
		  ),
		  5 => 
		  array (
			'value' => '5',
			'selected' => false,
			'text' => 'Friday',
		  ),
		  6 => 
		  array (
			'value' => '6',
			'selected' => false,
			'text' => 'Saturday',
		  ),
		),
	  ),
	  52 => 
	  array (
		'tag' => 'select',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[4][day]',
		  'required' => '',
		),
		'options' => 
		array (
		  0 => 
		  array (
			'value' => '0',
			'selected' => false,
			'text' => 'Sunday',
		  ),
		  1 => 
		  array (
			'value' => '1',
			'selected' => false,
			'text' => 'Monday',
		  ),
		  2 => 
		  array (
			'value' => '2',
			'selected' => false,
			'text' => 'Tuesday',
		  ),
		  3 => 
		  array (
			'value' => '3',
			'selected' => false,
			'text' => 'Wednesday',
		  ),
		  4 => 
		  array (
			'value' => '4',
			'selected' => false,
			'text' => 'Thursday',
		  ),
		  5 => 
		  array (
			'value' => '5',
			'selected' => true,
			'text' => 'Friday',
		  ),
		  6 => 
		  array (
			'value' => '6',
			'selected' => false,
			'text' => 'Saturday',
		  ),
		),
	  ),
	  53 => 
	  array (
		'tag' => 'textarea',
		'attrs' => 
		array (
		  'class' => 'large-text',
		  'id' => 'calendar_description',
		  'name' => 'ffc_self_scheduling_config[description]',
		  'rows' => '3',
		),
		'text' => '',
	  ),
	  54 => 
	  array (
		'tag' => 'textarea',
		'attrs' => 
		array (
		  'id' => 'user_confirmation_body',
		  'name' => 'ffc_self_scheduling_email_config[user_confirmation_body]',
		  'rows' => '10',
		),
		'text' => 'Hello {{user_name}},

Your appointment has been scheduled:

Calendar: {{calendar_title}}
Date: {{appointment_date}}
Time: {{appointment_time}}

Thank you!',
	  ),
	),
	'texts' => 
	array (
	  0 => ',',
	  1 => 'Actions',
	  2 => 'Active',
	  3 => 'Add Block',
	  4 => 'Add Working Hours',
	  5 => 'Admin Bypass',
	  6 => 'Admin Email Addresses',
	  7 => 'Admin must manually approve all bookings',
	  8 => 'Administrator bypass',
	  9 => 'Administrator recipients',
	  10 => 'Allow User Cancellation',
	  11 => 'Allow administrators to bypass booking restrictions on this calendar',
	  12 => 'Applies to both scheduling modes. When a spot frees up (a cancellation or a rejected approval), the next person in line is promoted automatically.',
	  13 => 'Appointment Duration',
	  14 => 'Approval and waitlist',
	  15 => 'Archived',
	  16 => 'Blocks per User (Custom mode)',
	  17 => 'Booking slots',
	  18 => 'Booking window',
	  19 => 'Bookings can only be made during working hours',
	  20 => 'Break Between Appointments',
	  21 => 'Brief description of this calendar (optional)',
	  22 => 'Buffer time between appointments (0 = no break)',
	  23 => 'Calendar',
	  24 => 'Calendar can only be viewed during working hours',
	  25 => 'Calendar status (inactive = no new bookings allowed)',
	  26 => 'Cancellation',
	  27 => 'Cancellation Deadline',
	  28 => 'Comma-separated email addresses for admin notifications (leave empty to use site admin email)',
	  29 => 'Confirmation Email Body',
	  30 => 'Confirmation Email Subject',
	  31 => 'Confirmation message',
	  32 => 'Custom — specific date/time blocks, each with its own number of vacancies',
	  33 => 'Daily Booking Limit',
	  34 => 'Date',
	  35 => 'Day',
	  36 => 'Default: All notifications disabled',
	  37 => 'Define the exact date/time blocks and how many vacancies each has. Each block is one bookable session.',
	  38 => 'Define which days and times appointments can be booked:',
	  39 => 'Description',
	  40 => 'Duration of each appointment slot',
	  41 => 'Each message on its own switch, and when the reminder goes out.',
	  42 => 'Enable Waitlist',
	  43 => 'End',
	  44 => 'End Time',
	  45 => 'Friday',
	  46 => 'How far ahead, and how often, a person may book.',
	  47 => 'How far in advance can users book?',
	  48 => 'Inactive',
	  49 => 'Include {{receipt_button}} / {{cancel_button}} to show the receipt and cancel buttons (each empty when unavailable). Leave the whole body empty to use the global default from Settings → SMTP → Email texts.',
	  50 => 'Label (optional)',
	  51 => 'Manual review of each booking, and a queue for full slots.',
	  52 => 'Max Bookings Per Slot',
	  53 => 'Maximum Advance Booking',
	  54 => 'Maximum appointments per day (0 = unlimited)',
	  55 => 'Maximum number of blocks a single user may book in this custom calendar (0 = no limit). Waitlisted bookings count toward the limit.',
	  56 => 'Maximum number of people per time slot (1 = exclusive)',
	  57 => 'Maximum number of people who can wait per slot (0 = unlimited).',
	  58 => 'Minimum Advance Booking',
	  59 => 'Minimum Interval Between Bookings',
	  60 => 'Minimum notice required to cancel (e.g., 24 hours)',
	  61 => 'Minimum time in advance required to book (0 = same day allowed)',
	  62 => 'Monday',
	  63 => 'Notifications',
	  64 => 'Off',
	  65 => 'On',
	  66 => 'Prevent users from booking another appointment within X hours of their last booking (0 = disabled, default: 24 hours)',
	  67 => 'Private',
	  68 => 'Public',
	  69 => 'Public: anyone can book. Private: only logged-in users can book.',
	  70 => 'Public: visible to everyone. Private: only visible to logged-in users.',
	  71 => 'Regular repeats weekly. Custom lets you list exact dates and times below, each with its own capacity.',
	  72 => 'Regular — weekly working hours',
	  73 => 'Reminder Timing',
	  74 => 'Remove',
	  75 => 'Require Manual Approval',
	  76 => 'Restore Default Text',
	  77 => 'Restrict Booking to Business Hours',
	  78 => 'Restrict Viewing to Business Hours',
	  79 => 'Saturday',
	  80 => 'Scheduling',
	  81 => 'Scheduling mode',
	  82 => 'Send confirmation email to user',
	  83 => 'Send notification to admin on new booking',
	  84 => 'Send notification when booking is approved',
	  85 => 'Send notification when booking is cancelled',
	  86 => 'Send reminder before appointment',
	  87 => 'Start',
	  88 => 'Start Time',
	  89 => 'Status',
	  90 => 'Sunday',
	  91 => 'The message content ("email body") — the shared Email Model chrome (header/footer) is added automatically. Available variables:',
	  92 => 'The subject and text of the email the person receives.',
	  93 => 'Thursday',
	  94 => 'Tuesday',
	  95 => 'Users can cancel their own appointments',
	  96 => 'Vacancies',
	  97 => 'Visibility',
	  98 => 'Visibility of the calendar, and the hours it can be seen or booked in.',
	  99 => 'Waitlist Capacity',
	  100 => 'Wednesday',
	  101 => 'Weekly hours or exact blocks, and how each slot is sized.',
	  102 => 'What the calendar is, and whether it takes bookings.',
	  103 => 'When a slot is full, let users join a waitlist instead of being turned away',
	  104 => 'When enabled, the calendar will only be visible during the configured working hours. Outside those hours, a message will be displayed instead.',
	  105 => 'When enabled, users can view the calendar at any time but can only make bookings during the configured working hours.',
	  106 => 'When enabled, users with the "manage_options" capability or the "ffc_bypass_appointments" capability can book outside the advance-booking window, on blocked dates, outside working hours, and past the daily/interval limits. Slot capacity is always enforced. Disable this to apply all booking rules uniformly, including to administrators.',
	  107 => 'Whether administrators are held to the rules above.',
	  108 => 'Whether people can cancel their own bookings, and until when.',
	  109 => 'Which emails are sent',
	  110 => 'Who can see and book',
	  111 => 'Who receives the new-booking notification.',
	  112 => 'days',
	  113 => 'hours',
	  114 => 'hours before',
	  115 => 'hours before appointment',
	  116 => 'minutes',
	  117 => 'per slot',
	  118 => '{{appointment_date}}',
	  119 => '{{appointment_time}}',
	  120 => '{{calendar_title}}',
	  121 => '{{cancel_button}}',
	  122 => '{{receipt_button}}',
	  123 => '{{status_label}}',
	  124 => '{{status_message}}',
	  125 => '{{user_email}}',
	  126 => '{{user_name}}',
	  127 => '{{user_notes_block}}',
	),
  ),
  'configured' => 
  array (
	'controls' => 
	array (
	  0 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-email-restore-default',
		  'data-default-key' => 'selfsched_confirmation_body',
		  'data-editor' => 'user_confirmation_body',
		  'type' => 'button',
		),
		'text' => 'Restore Default Text',
	  ),
	  1 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-icon-plus',
		  'id' => 'ffc-add-custom-slot',
		  'type' => 'button',
		),
		'text' => 'Add Block',
	  ),
	  2 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-icon-plus',
		  'id' => 'ffc-add-working-hour',
		  'type' => 'button',
		),
		'text' => 'Add Working Hours',
	  ),
	  3 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-remove-hour',
		  'type' => 'button',
		),
		'text' => 'Remove',
	  ),
	  4 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-remove-hour',
		  'type' => 'button',
		),
		'text' => 'Remove',
	  ),
	  5 => 
	  array (
		'tag' => 'button',
		'attrs' => 
		array (
		  'class' => 'button ffc-remove-slot',
		  'type' => 'button',
		),
		'text' => 'Remove',
	  ),
	  6 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'checked' => 'checked',
		  'class' => 'ffc-schedule-type-radio',
		  'disabled' => 'disabled',
		  'name' => 'ffc_self_scheduling_config[schedule_type]',
		  'type' => 'radio',
		  'value' => 'custom',
		),
	  ),
	  7 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'checked' => 'checked',
		  'id' => 'ffc_send_reminder',
		  'name' => 'ffc_self_scheduling_email_config[send_reminder]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  8 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'checked' => 'checked',
		  'id' => 'ffc_send_user_confirmation',
		  'name' => 'ffc_self_scheduling_email_config[send_user_confirmation]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  9 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'checked' => 'checked',
		  'id' => 'requires_approval',
		  'name' => 'ffc_self_scheduling_config[requires_approval]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  10 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'checked' => 'checked',
		  'id' => 'restrict_viewing_to_hours',
		  'name' => 'ffc_self_scheduling_config[restrict_viewing_to_hours]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  11 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'checked' => 'checked',
		  'id' => 'waitlist_enabled',
		  'name' => 'ffc_self_scheduling_config[waitlist_enabled]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  12 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'class' => 'ffc-schedule-type-radio',
		  'disabled' => 'disabled',
		  'name' => 'ffc_self_scheduling_config[schedule_type]',
		  'type' => 'radio',
		  'value' => 'regular',
		),
	  ),
	  13 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'class' => 'large-text',
		  'id' => 'admin_emails',
		  'name' => 'ffc_self_scheduling_email_config[admin_emails]',
		  'placeholder' => 'site@example.com',
		  'type' => 'text',
		  'value' => 'desk@example.com',
		),
	  ),
	  14 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'class' => 'large-text',
		  'id' => 'user_confirmation_subject',
		  'name' => 'ffc_self_scheduling_email_config[user_confirmation_subject]',
		  'type' => 'text',
		  'value' => 'Booked',
		),
	  ),
	  15 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'class' => 'regular-text',
		  'name' => 'ffc_self_scheduling_custom_slots[0][label]',
		  'type' => 'text',
		  'value' => 'Morning',
		),
	  ),
	  16 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'admin_bypass',
		  'name' => 'ffc_self_scheduling_config[admin_bypass]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  17 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'advance_booking_max',
		  'max' => '365',
		  'min' => '1',
		  'name' => 'ffc_self_scheduling_config[advance_booking_max]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '60',
		),
	  ),
	  18 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'advance_booking_min',
		  'max' => '720',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[advance_booking_min]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '2',
		),
	  ),
	  19 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'allow_cancellation',
		  'name' => 'ffc_self_scheduling_config[allow_cancellation]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  20 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'cancellation_min_hours',
		  'max' => '168',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[cancellation_min_hours]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '12',
		),
	  ),
	  21 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'ffc_self_scheduling_config_nonce',
		  'name' => 'ffc_self_scheduling_config_nonce',
		  'type' => 'hidden',
		  'value' => 'nonce:ffc_self_scheduling_config_nonce',
		),
	  ),
	  22 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'ffc_send_admin_notification',
		  'name' => 'ffc_self_scheduling_email_config[send_admin_notification]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  23 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'ffc_send_approval_notification',
		  'name' => 'ffc_self_scheduling_email_config[send_approval_notification]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  24 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'ffc_send_cancellation_notification',
		  'name' => 'ffc_self_scheduling_email_config[send_cancellation_notification]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  25 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'max_appointments_per_slot',
		  'max' => '100',
		  'min' => '1',
		  'name' => 'ffc_self_scheduling_config[max_appointments_per_slot]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '3',
		),
	  ),
	  26 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'max_blocks_per_user',
		  'max' => '9999',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[max_blocks_per_user]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '2',
		),
	  ),
	  27 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'minimum_interval_between_bookings',
		  'max' => '720',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[minimum_interval_between_bookings]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '24',
		),
	  ),
	  28 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'reminder_hours_before',
		  'max' => '168',
		  'min' => '1',
		  'name' => 'ffc_self_scheduling_email_config[reminder_hours_before]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '48',
		),
	  ),
	  29 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'restrict_booking_to_hours',
		  'name' => 'ffc_self_scheduling_config[restrict_booking_to_hours]',
		  'type' => 'checkbox',
		  'value' => '1',
		),
	  ),
	  30 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'slot_duration',
		  'max' => '480',
		  'min' => '5',
		  'name' => 'ffc_self_scheduling_config[slot_duration]',
		  'required' => '',
		  'step' => '5',
		  'type' => 'number',
		  'value' => '45',
		),
	  ),
	  31 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'slot_interval',
		  'max' => '120',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[slot_interval]',
		  'required' => '',
		  'step' => '5',
		  'type' => 'number',
		  'value' => '10',
		),
	  ),
	  32 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'slots_per_day',
		  'max' => '200',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[slots_per_day]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '12',
		),
	  ),
	  33 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'id' => 'waitlist_capacity',
		  'max' => '9999',
		  'min' => '0',
		  'name' => 'ffc_self_scheduling_config[waitlist_capacity]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '5',
		),
	  ),
	  34 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'max' => '10000',
		  'min' => '1',
		  'name' => 'ffc_self_scheduling_custom_slots[0][capacity]',
		  'required' => '',
		  'type' => 'number',
		  'value' => '40',
		),
	  ),
	  35 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_config[schedule_type]',
		  'type' => 'hidden',
		  'value' => 'custom',
		),
	  ),
	  36 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_config[scheduling_visibility]',
		  'type' => 'hidden',
		  'value' => 'private',
		),
	  ),
	  37 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_custom_slots[0][date]',
		  'type' => 'date',
		  'value' => '2026-11-03',
		),
	  ),
	  38 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_custom_slots[0][end]',
		  'type' => 'time',
		  'value' => '12:00',
		),
	  ),
	  39 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_custom_slots[0][start]',
		  'type' => 'time',
		  'value' => '08:00',
		),
	  ),
	  40 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[0][end]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '12:00',
		),
	  ),
	  41 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[0][start]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '08:00',
		),
	  ),
	  42 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[1][end]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '18:00',
		),
	  ),
	  43 => 
	  array (
		'tag' => 'input',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[1][start]',
		  'required' => '',
		  'type' => 'time',
		  'value' => '13:00',
		),
	  ),
	  44 => 
	  array (
		'tag' => 'select',
		'attrs' => 
		array (
		  'disabled' => 'disabled',
		  'id' => 'ffc_scheduling_visibility',
		  'name' => 'ffc_self_scheduling_config[scheduling_visibility]',
		),
		'options' => 
		array (
		  0 => 
		  array (
			'value' => 'public',
			'selected' => false,
			'text' => 'Public',
		  ),
		  1 => 
		  array (
			'value' => 'private',
			'selected' => true,
			'text' => 'Private',
		  ),
		),
	  ),
	  45 => 
	  array (
		'tag' => 'select',
		'attrs' => 
		array (
		  'id' => 'calendar_status',
		  'name' => 'ffc_self_scheduling_config[status]',
		),
		'options' => 
		array (
		  0 => 
		  array (
			'value' => 'active',
			'selected' => false,
			'text' => 'Active',
		  ),
		  1 => 
		  array (
			'value' => 'inactive',
			'selected' => true,
			'text' => 'Inactive',
		  ),
		  2 => 
		  array (
			'value' => 'archived',
			'selected' => false,
			'text' => 'Archived',
		  ),
		),
	  ),
	  46 => 
	  array (
		'tag' => 'select',
		'attrs' => 
		array (
		  'id' => 'ffc_visibility',
		  'name' => 'ffc_self_scheduling_config[visibility]',
		),
		'options' => 
		array (
		  0 => 
		  array (
			'value' => 'public',
			'selected' => false,
			'text' => 'Public',
		  ),
		  1 => 
		  array (
			'value' => 'private',
			'selected' => true,
			'text' => 'Private',
		  ),
		),
	  ),
	  47 => 
	  array (
		'tag' => 'select',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[0][day]',
		  'required' => '',
		),
		'options' => 
		array (
		  0 => 
		  array (
			'value' => '0',
			'selected' => false,
			'text' => 'Sunday',
		  ),
		  1 => 
		  array (
			'value' => '1',
			'selected' => false,
			'text' => 'Monday',
		  ),
		  2 => 
		  array (
			'value' => '2',
			'selected' => true,
			'text' => 'Tuesday',
		  ),
		  3 => 
		  array (
			'value' => '3',
			'selected' => false,
			'text' => 'Wednesday',
		  ),
		  4 => 
		  array (
			'value' => '4',
			'selected' => false,
			'text' => 'Thursday',
		  ),
		  5 => 
		  array (
			'value' => '5',
			'selected' => false,
			'text' => 'Friday',
		  ),
		  6 => 
		  array (
			'value' => '6',
			'selected' => false,
			'text' => 'Saturday',
		  ),
		),
	  ),
	  48 => 
	  array (
		'tag' => 'select',
		'attrs' => 
		array (
		  'name' => 'ffc_self_scheduling_working_hours[1][day]',
		  'required' => '',
		),
		'options' => 
		array (
		  0 => 
		  array (
			'value' => '0',
			'selected' => false,
			'text' => 'Sunday',
		  ),
		  1 => 
		  array (
			'value' => '1',
			'selected' => false,
			'text' => 'Monday',
		  ),
		  2 => 
		  array (
			'value' => '2',
			'selected' => false,
			'text' => 'Tuesday',
		  ),
		  3 => 
		  array (
			'value' => '3',
			'selected' => false,
			'text' => 'Wednesday',
		  ),
		  4 => 
		  array (
			'value' => '4',
			'selected' => true,
			'text' => 'Thursday',
		  ),
		  5 => 
		  array (
			'value' => '5',
			'selected' => false,
			'text' => 'Friday',
		  ),
		  6 => 
		  array (
			'value' => '6',
			'selected' => false,
			'text' => 'Saturday',
		  ),
		),
	  ),
	  49 => 
	  array (
		'tag' => 'textarea',
		'attrs' => 
		array (
		  'class' => 'large-text',
		  'id' => 'calendar_description',
		  'name' => 'ffc_self_scheduling_config[description]',
		  'rows' => '3',
		),
		'text' => 'Front desk',
	  ),
	  50 => 
	  array (
		'tag' => 'textarea',
		'attrs' => 
		array (
		  'id' => 'user_confirmation_body',
		  'name' => 'ffc_self_scheduling_email_config[user_confirmation_body]',
		  'rows' => '10',
		),
		'text' => '<p>See you</p>',
	  ),
	),
	'texts' => 
	array (
	  0 => ',',
	  1 => '0',
	  2 => '0 / 40',
	  3 => '0%',
	  4 => '03/11/2026',
	  5 => '08:00–12:00',
	  6 => 'Actions',
	  7 => 'Active',
	  8 => 'Add Block',
	  9 => 'Add Working Hours',
	  10 => 'Admin Bypass',
	  11 => 'Admin Email Addresses',
	  12 => 'Admin must manually approve all bookings',
	  13 => 'Administrator bypass',
	  14 => 'Administrator recipients',
	  15 => 'Allow User Cancellation',
	  16 => 'Allow administrators to bypass booking restrictions on this calendar',
	  17 => 'Applies to both scheduling modes. When a spot frees up (a cancellation or a rejected approval), the next person in line is promoted automatically.',
	  18 => 'Appointment Duration',
	  19 => 'Approval and waitlist',
	  20 => 'Archived',
	  21 => 'Blocks per User (Custom mode)',
	  22 => 'Booked / Capacity',
	  23 => 'Booking slots',
	  24 => 'Booking window',
	  25 => 'Bookings can only be made during working hours',
	  26 => 'Break Between Appointments',
	  27 => 'Brief description of this calendar (optional)',
	  28 => 'Buffer time between appointments (0 = no break)',
	  29 => 'Calendar',
	  30 => 'Calendar can only be viewed during working hours',
	  31 => 'Calendar status (inactive = no new bookings allowed)',
	  32 => 'Cancellation',
	  33 => 'Cancellation Deadline',
	  34 => 'Comma-separated email addresses for admin notifications (leave empty to use site admin email)',
	  35 => 'Confirmation Email Body',
	  36 => 'Confirmation Email Subject',
	  37 => 'Confirmation message',
	  38 => 'Custom — specific date/time blocks, each with its own number of vacancies',
	  39 => 'Daily Booking Limit',
	  40 => 'Date',
	  41 => 'Day',
	  42 => 'Default: All notifications disabled',
	  43 => 'Define the exact date/time blocks and how many vacancies each has. Each block is one bookable session.',
	  44 => 'Define which days and times appointments can be booked:',
	  45 => 'Description',
	  46 => 'Duration of each appointment slot',
	  47 => 'Each message on its own switch, and when the reminder goes out.',
	  48 => 'Enable Waitlist',
	  49 => 'End',
	  50 => 'End Time',
	  51 => 'Forced to Private because Visibility is Private.',
	  52 => 'Friday',
	  53 => 'How far ahead, and how often, a person may book.',
	  54 => 'How far in advance can users book?',
	  55 => 'Inactive',
	  56 => 'Include {{receipt_button}} / {{cancel_button}} to show the receipt and cancel buttons (each empty when unavailable). Leave the whole body empty to use the global default from Settings → SMTP → Email texts.',
	  57 => 'Label',
	  58 => 'Label (optional)',
	  59 => 'Manual review of each booking, and a queue for full slots.',
	  60 => 'Max Bookings Per Slot',
	  61 => 'Maximum Advance Booking',
	  62 => 'Maximum appointments per day (0 = unlimited)',
	  63 => 'Maximum number of blocks a single user may book in this custom calendar (0 = no limit). Waitlisted bookings count toward the limit.',
	  64 => 'Maximum number of people per time slot (1 = exclusive)',
	  65 => 'Maximum number of people who can wait per slot (0 = unlimited).',
	  66 => 'Minimum Advance Booking',
	  67 => 'Minimum Interval Between Bookings',
	  68 => 'Minimum notice required to cancel (e.g., 24 hours)',
	  69 => 'Minimum time in advance required to book (0 = same day allowed)',
	  70 => 'Monday',
	  71 => 'Morning',
	  72 => 'Notifications',
	  73 => 'Occupancy',
	  74 => 'Off',
	  75 => 'On',
	  76 => 'Prevent users from booking another appointment within X hours of their last booking (0 = disabled, default: 24 hours)',
	  77 => 'Private',
	  78 => 'Public',
	  79 => 'Public: visible to everyone. Private: only visible to logged-in users.',
	  80 => 'Regular — weekly working hours',
	  81 => 'Reminder Timing',
	  82 => 'Remove',
	  83 => 'Require Manual Approval',
	  84 => 'Restore Default Text',
	  85 => 'Restrict Booking to Business Hours',
	  86 => 'Restrict Viewing to Business Hours',
	  87 => 'Saturday',
	  88 => 'Scheduling',
	  89 => 'Scheduling mode',
	  90 => 'Send confirmation email to user',
	  91 => 'Send notification to admin on new booking',
	  92 => 'Send notification when booking is approved',
	  93 => 'Send notification when booking is cancelled',
	  94 => 'Send reminder before appointment',
	  95 => 'Start',
	  96 => 'Start Time',
	  97 => 'Status',
	  98 => 'Sunday',
	  99 => 'The message content ("email body") — the shared Email Model chrome (header/footer) is added automatically. Available variables:',
	  100 => 'The subject and text of the email the person receives.',
	  101 => 'This calendar already has bookings — the scheduling mode is locked.',
	  102 => 'This calendar has bookings — blocks with existing bookings cannot be removed or retimed on save; you may still add blocks or raise capacity.',
	  103 => 'Thursday',
	  104 => 'Time',
	  105 => 'Tuesday',
	  106 => 'Users can cancel their own appointments',
	  107 => 'Vacancies',
	  108 => 'Visibility',
	  109 => 'Visibility of the calendar, and the hours it can be seen or booked in.',
	  110 => 'Waitlist',
	  111 => 'Waitlist Capacity',
	  112 => 'Wednesday',
	  113 => 'Weekly hours or exact blocks, and how each slot is sized.',
	  114 => 'What the calendar is, and whether it takes bookings.',
	  115 => 'When a slot is full, let users join a waitlist instead of being turned away',
	  116 => 'When enabled, the calendar will only be visible during the configured working hours. Outside those hours, a message will be displayed instead.',
	  117 => 'When enabled, users can view the calendar at any time but can only make bookings during the configured working hours.',
	  118 => 'When enabled, users with the "manage_options" capability or the "ffc_bypass_appointments" capability can book outside the advance-booking window, on blocked dates, outside working hours, and past the daily/interval limits. Slot capacity is always enforced. Disable this to apply all booking rules uniformly, including to administrators.',
	  119 => 'Whether administrators are held to the rules above.',
	  120 => 'Whether people can cancel their own bookings, and until when.',
	  121 => 'Which emails are sent',
	  122 => 'Who can see and book',
	  123 => 'Who receives the new-booking notification.',
	  124 => 'days',
	  125 => 'hours',
	  126 => 'hours before',
	  127 => 'hours before appointment',
	  128 => 'minutes',
	  129 => 'per slot',
	  130 => '{{appointment_date}}',
	  131 => '{{appointment_time}}',
	  132 => '{{calendar_title}}',
	  133 => '{{cancel_button}}',
	  134 => '{{receipt_button}}',
	  135 => '{{status_label}}',
	  136 => '{{status_message}}',
	  137 => '{{user_email}}',
	  138 => '{{user_name}}',
	  139 => '{{user_notes_block}}',
	),
  ),
);
