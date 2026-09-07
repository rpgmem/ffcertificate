<?php
/**
 * Known dbDelta drift, frozen (#1087 passo 7, reduced in passo 8).
 *
 * Every entry here is a `CREATE TABLE` that `dbDelta()` wants to ALTER against
 * the table it just created from that very statement — so the ALTER would run
 * on every activation that reaches it. That is the #997 class, and the first
 * measurement since #997 found it in 14 statements / 33 changes. Passo 8 fixed
 * everything that could be fixed without a decision; what remains is one family
 * and one question.
 *
 * **This is a debt register, not a target to grow.** It exists so the gate can
 * block from day one instead of shipping non-blocking and being ignored — the
 * shape the row ruler, the module boundary, the vacuous tests and the superglobal
 * casts all use in this repository. A ratchet that can only shrink: a change not
 * listed here fails, and a listed change that stops happening also fails, so a
 * fix is locked in the moment it lands.
 *
 * **What remains: `json`, which MariaDB does not store.** MariaDB implements
 * `JSON` as an alias for `LONGTEXT` plus a CHECK constraint, so a statement
 * asking for `json` is answered by `SHOW CREATE TABLE` with `longtext`, and
 * dbDelta rewrites the difference every run. There is no spelling that satisfies
 * both servers here, unlike the display width below: `json` and `longtext` are
 * genuinely different types in MySQL 8, where `json` is native and carries
 * validation. Declaring `longtext` would clear the drift on MariaDB and, on
 * MySQL, convert a `json` column to `longtext` once — data survives, validation
 * does not. That is a product decision about which server the plugin's schema
 * describes, so it is deliberately left open rather than settled by a scan.
 *
 * `dbDelta` offers no relief: its `$text_fields` / `$blob_fields` "declared type
 * is smaller than stored, leave it alone" rule lists neither `json`, so the
 * comparison stays a string comparison.
 *
 * **What passo 8 fixed, and why those were not decisions.**
 *
 * - **Display width — 9 statements, 20 changes.** The statements wrote
 *   `int unsigned` and MariaDB stores `int(10) unsigned`. This looked like the
 *   same irreconcilable trade-off as `json` and is not: WP core's `dbDelta`
 *   ignores a display-width-only difference on **MySQL 8.0.17+ and explicitly
 *   not on MariaDB** ("Note: This is specific to MySQL and does not affect
 *   MariaDB"). So writing the width is correct on both servers — MariaDB matches
 *   it literally, MySQL ignores it — and it is what WP core writes in its own
 *   schema.
 * - **`ffc_reregistrations.audience_id`.** The migration still declared a column
 *   and index that `ReregistrationActivator` drops right after, having moved the
 *   relationship to a junction table. On a fresh install the column was created
 *   and destroyed in the same activation; the declaration described a table that
 *   never exists. (It was NOT a per-activation cycle — `create_reregistrations_table()`
 *   is guarded by `table_exists()`, so it never re-created the column on an
 *   established install.)
 * - **`KEY auth_code` on two tables.** `Activator::upgrade_auth_code_unique_constraints()`
 *   drops the non-unique indexes on `auth_code` and adds `UNIQUE INDEX uq_auth_code`,
 *   on `ffc_submissions` and `ffc_reregistration_submissions` alike. The
 *   statements declared the plain `KEY` that never survives, so dbDelta asked
 *   for it back on every run. They now declare the UNIQUE the code actually
 *   creates — the same shape CLAUDE.md records for `validation_code`.
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
	'includes/migrations/class-ffc-migration-custom-fields-tables.php::ffc_custom_fields' => array(
		'Changed type of ffc_custom_fields.field_options from longtext to json',
		'Changed type of ffc_custom_fields.validation_rules from longtext to json',
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
	'includes/reregistration/class-ffc-reregistration-activator.php::ffc_reregistration_submissions' => array(
		'Changed type of ffc_reregistration_submissions.data from longtext to json',
	),
	'includes/user-dashboard/class-ffc-user-dashboard-activator.php::ffc_custom_fields' => array(
		'Changed type of ffc_custom_fields.field_options from longtext to json',
		'Changed type of ffc_custom_fields.validation_rules from longtext to json',
	),
	'includes/user-dashboard/class-ffc-user-dashboard-activator.php::ffc_user_profiles' => array(
		'Changed type of ffc_user_profiles.preferences from longtext to json',
	),
);
