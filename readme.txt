=== Free Form Certificate ===
Contributors: alexmeusburger
Tags: certificate, form builder, pdf generation, verification, validation
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 6.5.4
Requires PHP: 8.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Create dynamic forms, generate PDF certificates, and validate authenticity with magic link access.

== Description ==

Free Form Certificate is a complete WordPress solution for creating dynamic forms, generating PDF certificates, scheduling appointments, and verifying document authenticity. Built with a fully namespaced, modular architecture using the Repository pattern and Strategy pattern for maximum maintainability.

= Core Features =

* **Drag & Drop Form Builder** - Custom fields: Text, Email, Number, Date, Select, Radio, Textarea, Hidden, Info Block, and Embed (Media).
* **Client-Side PDF Generation** - A4 landscape certificates using html2canvas and jsPDF, with custom background images.
* **Magic Links** - One-click certificate access via unique, cryptographically secure URLs sent by email.
* **Verification System** - Certificate authenticity validation via unique code or magic token.
* **QR Codes** - Auto-generated QR codes on certificates linking to the verification page.

= Self-Scheduling (Personal Calendars) =

* **Calendar Management** - Create multiple calendars with configurable time slots, durations, and business hours.
* **Appointment Booking** - Frontend booking widget with real-time slot availability.
* **Email Notifications** - Confirmation, approval, cancellation, and reminder emails.
* **PDF Receipts** - Downloadable appointment receipts generated client-side.
* **Admin Dashboard** - Manage, approve, and export appointments.

= Audience Scheduling (Group Bookings) =

* **Audience Management** - Create audiences (groups) with hierarchical structure and color coding.
* **Environment Management** - Configure physical spaces with calendars, working hours, and capacity.
* **Group Bookings** - Schedule activities for entire audiences or individual users.
* **CSV Import & Export** - Import and export audiences and members from/to CSV files with user creation.
* **Conflict Detection** - Real-time conflict checking before booking confirmation.
* **Email Notifications** - Automatic notifications for new bookings and cancellations.

= Reregistration =

* **Campaign Management** - Create reregistration campaigns linked to audiences with configurable periods.
* **Custom Fields** - Define per-audience custom fields (text, textarea, number, date, select, checkbox) with validation.
* **Email Notifications** - Invitation, reminder, and confirmation emails with configurable templates.
* **Approval Workflow** - Manual or auto-approve submissions with admin review interface.
* **Ficha PDF** - Generate PDF records for submissions with customizable templates.
* **Dashboard Integration** - Users see reregistration banners and can submit/download ficha from their dashboard.

= Security & Restrictions =

* **Geofencing** - Restrict form access by GPS coordinates or IP-based areas.
* **Rate Limiting** - Configurable attempt limits per IP with automatic blocking.
* **ID-Based Restriction** - Control certificate issuance via CPF/RF document validation.
* **Ticket System** - Import single-use access codes for exclusive form access.
* **Allowlist / Denylist** - Whitelist or block specific IDs.
* **Math Captcha & Honeypot** - Built-in bot protection on all forms.
* **Data Encryption** - Sensitive fields (email, CPF, IP) encrypted at rest.

= Administration =

* **Activity Log** - Full audit trail of admin and user actions.
* **User Dashboard** - Personalized frontend dashboard for certificates and appointments.
* **CSV Export** - Export submissions and appointments with date and form filters.
* **Data Migrations** - Automated migration framework with progress tracking and rollback.
* **SMTP Configuration** - Built-in SMTP settings for reliable email delivery.
* **REST API** - Full REST API for external integrations.

== Installation ==

1. Upload the `ffcertificate` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Navigate to "Free Form Certificate" to create your first form.
4. Use the shortcode `[ffc_form id="FORM_ID"]` on any page or post.

== Frequently Asked Questions ==

= How do I create a form? =

1. Go to "Free Form Certificate" > "Add New Form".
2. Enter a title and use the Form Builder to add fields.
3. Configure the certificate layout in the "Certificate Layout" section.
4. Save and copy the generated shortcode.

= What are Magic Links? =

Magic Links are unique, secure URLs sent via email that allow recipients to instantly access and download their certificates with a single click. Each link contains a cryptographically secure 32-character token.

= How do I set up the verification page? =

The plugin creates a `/valid` page automatically during activation. You can also create a page manually with `[ffc_verification]`.

= How do I create a calendar? =

1. Go to "Free Form Certificate" > "Calendars" > "Add New".
2. Configure business hours, slot duration, and capacity.
3. Use the shortcode `[ffc_calendar id="CALENDAR_ID"]` on any page.

= Can I restrict who generates certificates? =

Yes. In each form's "Restriction & Security" section you can enable allowlist mode, use the ticket system, block IDs via denylist, or restrict by geographic area via geofencing.

= Does the plugin work with page cache plugins (WP Rocket, LiteSpeed Cache, W3 Total Cache)? =

Yes. The plugin includes built-in cache compatibility:

* **Forms (captcha & nonces):** A "Dynamic Fragments" system automatically refreshes captcha challenges and security nonces via AJAX after page load, so forms work correctly even when the HTML is served from a full-page cache.
* **Dashboard pages:** The `[user_dashboard_personal]` shortcode automatically sets the `DONOTCACHEPAGE` constant, sends standard no-cache headers, and triggers LiteSpeed-specific exclusion hooks. This ensures user-specific data is never cached.
* **AJAX endpoints:** All form submissions and data fetching use `admin-ajax.php`, which is excluded from page cache by default in all major cache plugins.
* **Diagnostics:** Go to FFC Settings > Cache tab to see the "Page Cache Compatibility" card, which shows the status of all cache-related features and detects your active cache plugin.

No manual configuration of cache exclusion rules is needed.

= How do I translate the plugin? =

The plugin is fully translation-ready with the `ffcertificate` text domain. Use Loco Translate or Poedit with the `languages/ffcertificate.pot` template file. Portuguese (Brazil) translation is included.

== Screenshots ==

1. Form Builder with drag & drop interface
2. Certificate layout editor with live preview
3. Submissions management with PDF download
4. Security settings (allowlist, tickets, denylist)
5. Frontend certificate generation
6. Magic link email with one-click access
7. Certificate preview page with download button
8. Appointment calendar frontend booking

== Shortcodes ==

= [ffc_form] =
Displays a certificate issuance form.

* `id` (required) - Form ID.

Example: `[ffc_form id="123"]`

= [ffc_verification] =
Displays the certificate verification interface. Automatically detects magic links via the `?token=` parameter.

Example: `[ffc_verification]`

= [ffc_calendar] =
Displays an appointment calendar with booking widget.

* `id` (required) - Calendar ID.

Example: `[ffc_calendar id="456"]`

= [ffc_audience_calendar] =
Displays the audience scheduling calendar for group bookings.

Example: `[ffc_audience_calendar]`

= [user_dashboard_personal] =
Displays the user's personal dashboard with certificates, appointments, audience bookings, and profile.

Example: `[user_dashboard_personal]`

== Layout & Placeholders ==

In the certificate layout editor, use these dynamic tags:

= System Tags =
* `{{auth_code}}` - 12-digit authentication code (formatted XXXX-XXXX-XXXX)
* `{{form_title}}` - Current form title
* `{{submission_date}}` - Issuance date (formatted per WordPress settings)
* `{{submission_id}}` - Numeric submission ID
* `{{validation_url}}` - Verification page URL

= Form Field Tags =
* `{{field_name}}` - Any field name defined in the Form Builder
* Common examples: `{{name}}`, `{{email}}`, `{{cpf_rf}}`, `{{ticket}}`

== Changelog ==

= 6.5.14 (2026-05-15) =

**Master-toggle UX consolidation across the form editor.** Closes #238.

* Change: Every user-facing reference to "Public CSV Download" renamed to **"Public Operator Access"** — editor metabox label, intro description, Settings tab card, docs (`13-features.php`, `01-shortcodes.php`). The master gates three sibling sub-features (CSV download + Start Form Early + Postpone Close), so the new name reflects the broadened scope. Aliases "(formerly Public CSV Download)" preserved so users coming from old screencasts still find the right place. Internal class / file / namespace + meta keys unchanged.
* Change: **Save-semantics — skip-on-off across the board.** 11 master toggles that previously rewrote sub-meta values on every save (Restriction × 4, Email send_user_email, DateTime, Geolocation, IP-Permissive, Quiz, CPF whitelist mode) now wrap sub-meta writes in `if ('1' === $master)`. Sub-options ride through unchanged when their master is off — disabling a section preserves its values for when you turn it back on.
* Change: **Unified visibility pattern (B / hidden).** Every master-toggle block uses the new `.ffc-collapsed-target` wrapper convention with a generic JS initializer. Three previously save-required toggles (Email send_user_email, IP-Permissive, CPF-whitelist-mode) gain live update behavior — toggle and the sub-options appear/disappear without a save+reload. wp_editor (TinyMCE) inside the Email metabox initializes normally; only the wrapper collapses, not the editor itself.

= 6.5.13 (2026-05-15) =

**Audit summary clarity + postpone-close lifecycle.**

* Change: **Public CSV audit summary — three operator-facing buckets** replace the prior "Total / Successful / Failed" counters. New labels: (1) *Successful accesses* — CPF + CAPTCHA both validated. (2) *Successful downloads* — CSV files actually delivered (sourced from the long-lived counter, survives audit-log rotation). (3) *Failed accesses* — every `fail_*` row including new `fail_captcha` and `fail_other` tags so the third bucket is complete. Form-editor metabox in Section 7 displays all three side by side.
* Change: **Admin form save now resets the postpone-close one-shot.** When you save a form in the editor, the `_ffc_csv_public_end_postponed_at` flag is wiped — letting operators on the public download page postpone the close again within the newly-configured window. The admin save is the natural cycle boundary.

= 6.5.12 (2026-05-15) =

**"Postpone close" public operator action.** Sibling of "Start Form Now" but for the close boundary.

* Feat: Trusted operators can push a form's `time_end` later within the same calendar day, exactly once per form, using the same public hash as the credential. Strict constraints: form must already be open, new close must be strictly later than both now and the current close, and must stay within the configured close-date's calendar day.
* Feat: Per-form opt-IN — `_ffc_csv_public_extend_end_enabled` defaults to `'0'` (admin must consciously enable since extending a public window is destructive-ish). UI lives next to "Start Form Now" on the public CSV download page; modal reuses the cert-preview chrome with a `<input type="time">` picker.
* Feat: Aggressive page-cache purge fires so the form page reflects the new close immediately. New Activity Log event `end_postponed` (warning level) audits the rewrite with form_id, original_time_end, new_time_end, IP, UA, user_id.

For the complete changelog history, see [CHANGELOG.md](CHANGELOG.md).

== Upgrade Notice ==

= 6.5.14 =
**UX consolidation** across the form editor master toggles. "Public CSV Download" renamed user-facing to "Public Operator Access" (the master now gates 3 sibling sub-features — CSV download, Start Form Early, Postpone Close); aliases preserved so old links still find the section. Sub-options now ride through unchanged when their master is off (skip-on-off save semantics) — disabling a section preserves its values for next time. Unified visibility pattern: every master-toggle block hides its sub-options live, no more save+reload to see what's gated. No data migrations; safe upgrade.

= 6.5.13 =
**Audit summary clarity.** The Public CSV download audit summary on the form editor now shows three operator-facing buckets — *Successful accesses* (CPF + CAPTCHA validated), *Successful downloads* (CSV actually delivered), *Failed accesses* (wrong CPF + wrong CAPTCHA + other errors) — instead of the prior "Total / Successful / Failed" counters. Two new failure tags (`fail_captcha`, `fail_other`) make the "Failed" bucket comprehensive. Admin form save now also re-enables the postpone-close one-shot so operators can postpone again after admin intervention. No data migrations; safe upgrade.

= 6.5.12 =
**"Postpone close" public operator action.** Sibling of "Start Form Now" — trusted operators on the venue floor can push a form's close time later within the same calendar day, exactly once per form, using the same hash credential. Per-form opt-in (`_ffc_csv_public_extend_end_enabled` defaults off — admin must consciously enable). New Activity Log event `end_postponed` audits the rewrite. No data migrations; safe upgrade.

For older releases, see [CHANGELOG.md](CHANGELOG.md).

== Privacy & Data Handling ==

= Data Collected =
* User submissions (name, email, custom fields)
* IP addresses (for rate limiting and audit trail)
* Appointment bookings (date, time, contact details)
* Submission and action timestamps

= Data Storage =
* Submissions stored in `wp_ffc_submissions` table with optional field encryption
* Appointments stored in `wp_ffc_appointments` table
* Rate limiting data stored in `wp_ffc_rate_limits` table
* Activity logs stored in `wp_ffc_activity_logs` table

= Data Retention =
* Configurable automatic cleanup for old submissions
* Manual deletion available in admin panel
* Deleting a submission invalidates its magic link and QR code
