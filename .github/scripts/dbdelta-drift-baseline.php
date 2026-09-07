<?php
/**
 * Known dbDelta drift, frozen (#1087 passo 7).
 *
 * Every entry here is a `CREATE TABLE` that `dbDelta()` wants to ALTER against
 * the table it just created from that very statement — so the ALTER runs on
 * **every** activation, in every install, forever. That is the #997 class, and
 * the first measurement since #997 found it in 13 statements.
 *
 * **This is a debt register, not a target to grow.** It exists so the gate can
 * block from day one instead of shipping non-blocking and being ignored — the
 * shape the row ruler, the module boundary, the vacuous tests and the superglobal
 * casts all use in this repository. A ratchet that can only shrink: a change not
 * listed here fails, and a listed change that stops happening also fails, so a
 * fix is locked in the moment it lands.
 *
 * **Three families, and only one of them was avoidable.**
 *
 * 1. **Display width the server supplies.** The statement writes `int unsigned`
 *    and MariaDB stores `int(10) unsigned` (MySQL 8.0.19+ went the other way and
 *    dropped the width). dbDelta compares the two as text, so it ALTERs the
 *    difference on every run. 9 of the 13.
 * 2. **`json`, which MariaDB implements as `longtext` plus a CHECK.** The
 *    statement asks for `json`, `SHOW CREATE TABLE` answers `longtext`, and the
 *    ALTER repeats forever. 6 statements, all on `field_options` /
 *    `validation_rules` / `data` / `preferences`.
 * 3. **A column or index the live table does not have** — `ffc_reregistrations.
 *    audience_id`, and a `KEY auth_code` on two tables. This family is NOT
 *    cosmetic: it means the statement and the table genuinely disagree, either
 *    because two files declare the same table differently (three files declare
 *    `ffc_custom_fields`; three declare `ffc_reregistration_submissions`) or
 *    because the index was never created. Diagnosing it needs the live schema,
 *    which is why the gate prints `SHOW CREATE TABLE` for exactly these.
 *
 * Families 1 and 2 are a type spelling to fix; family 3 is a schema question to
 * answer. Both are follow-up work on #1087, deliberately not folded into the PR
 * that adds the measurement.
 *
 * **Regenerating:** there is no local path — it needs a live MariaDB, which only
 * the `fresh-install` CI job has. When the gate fails it prints the exact PHP
 * block to paste here. Review the diff; never paste it to make a red gate green
 * without understanding what changed.
 *
 * Keys are `<path relative to the repo>::<ffc_ table>`; values are dbDelta's own
 * change lines with the table prefix stripped, sorted.
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

return array(
	'includes/audience/class-ffc-audience-activator.php::ffc_audience_schedules' => array(
		'Changed type of ffc_audience_schedules.future_days_limit from int(10) unsigned to int unsigned',
	),
	'includes/class-ffc-activator.php::ffc_submissions' => array(
		'Added index ffc_submissions KEY `auth_code` (`auth_code`)',
		'Changed type of ffc_submissions.consent_date from bigint(20) unsigned to bigint unsigned',
		'Changed type of ffc_submissions.edited_at from bigint(20) unsigned to bigint unsigned',
	),
	'includes/migrations/class-ffc-migration-custom-fields-tables.php::ffc_custom_fields' => array(
		'Changed type of ffc_custom_fields.field_options from longtext to json',
		'Changed type of ffc_custom_fields.validation_rules from longtext to json',
	),
	'includes/migrations/class-ffc-migration-custom-fields-tables.php::ffc_reregistrations' => array(
		'Added column ffc_reregistrations.audience_id',
		'Added index ffc_reregistrations KEY `idx_audience_id` (`audience_id`)',
	),
	'includes/migrations/class-ffc-migration-custom-fields-tables.php::ffc_reregistration_submissions' => array(
		'Changed type of ffc_reregistration_submissions.data from longtext to json',
	),
	'includes/migrations/class-ffc-migration-dynamic-rereg-fields.php::ffc_custom_fields' => array(
		'Changed type of ffc_custom_fields.field_options from longtext to json',
		'Changed type of ffc_custom_fields.validation_rules from longtext to json',
	),
	'includes/migrations/class-ffc-migration-dynamic-rereg-fields.php::ffc_reregistration_submissions' => array(
		'Changed type of ffc_reregistration_submissions.data from longtext to json',
	),
	'includes/recruitment/class-ffc-recruitment-activator.php::ffc_recruitment_classification' => array(
		'Changed type of ffc_recruitment_classification.rank from int(10) unsigned to int unsigned',
	),
	'includes/recruitment/class-ffc-recruitment-activator.php::ffc_recruitment_import_jobs' => array(
		'Changed type of ffc_recruitment_import_jobs.processed_count from int(10) unsigned to int unsigned',
		'Changed type of ffc_recruitment_import_jobs.total from int(10) unsigned to int unsigned',
	),
	'includes/recruitment/class-ffc-recruitment-activator.php::ffc_recruitment_import_staging' => array(
		'Changed type of ffc_recruitment_import_staging.line_no from int(10) unsigned to int unsigned',
		'Changed type of ffc_recruitment_import_staging.rank_value from int(10) unsigned to int unsigned',
		'Changed type of ffc_recruitment_import_staging.row_no from int(10) unsigned to int unsigned',
	),
	'includes/reregistration/class-ffc-reregistration-activator.php::ffc_reregistration_submissions' => array(
		'Added index ffc_reregistration_submissions KEY `auth_code` (`auth_code`)',
		'Changed type of ffc_reregistration_submissions.data from longtext to json',
	),
	'includes/self-scheduling/class-ffc-self-scheduling-activator.php::ffc_self_scheduling_calendars' => array(
		'Changed type of ffc_self_scheduling_calendars.advance_booking_max from int(10) unsigned to int unsigned',
		'Changed type of ffc_self_scheduling_calendars.advance_booking_min from int(10) unsigned to int unsigned',
		'Changed type of ffc_self_scheduling_calendars.cancellation_min_hours from int(10) unsigned to int unsigned',
		'Changed type of ffc_self_scheduling_calendars.max_appointments_per_slot from int(10) unsigned to int unsigned',
		'Changed type of ffc_self_scheduling_calendars.max_blocks_per_user from int(10) unsigned to int unsigned',
		'Changed type of ffc_self_scheduling_calendars.minimum_interval_between_bookings from int(10) unsigned to int unsigned',
		'Changed type of ffc_self_scheduling_calendars.slot_duration from int(10) unsigned to int unsigned',
		'Changed type of ffc_self_scheduling_calendars.slot_interval from int(10) unsigned to int unsigned',
		'Changed type of ffc_self_scheduling_calendars.slots_per_day from int(10) unsigned to int unsigned',
		'Changed type of ffc_self_scheduling_calendars.waitlist_capacity from int(10) unsigned to int unsigned',
	),
	'includes/user-dashboard/class-ffc-user-dashboard-activator.php::ffc_custom_fields' => array(
		'Changed type of ffc_custom_fields.field_options from longtext to json',
		'Changed type of ffc_custom_fields.validation_rules from longtext to json',
	),
	'includes/user-dashboard/class-ffc-user-dashboard-activator.php::ffc_user_profiles' => array(
		'Changed type of ffc_user_profiles.preferences from longtext to json',
	),
);
