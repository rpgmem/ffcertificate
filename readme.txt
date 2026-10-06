=== Free Form Certificate ===
Contributors: alexmeusburger
Tags: certificate, form builder, pdf generation, verification, validation
Requires at least: 6.4
Tested up to: 7.1.3
Stable tag: 6.33.0
Requires PHP: 8.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Create dynamic forms, generate PDF certificates, and validate authenticity with magic link access.

== Description ==

Free Form Certificate is a WordPress plugin for issuing PDF certificates from dynamic forms and verifying their authenticity. It also covers appointment scheduling, group bookings, reregistration campaigns, public-tender candidate queues, short URLs, QR codes and date-based e-mails. Each module, certificates included, can be switched on or off under Settings → Modules.

The full reference (every token, shortcode, capability and setting) ships inside the plugin, under Settings → Documentation.

= Certificates =

* **Form Builder** - Drag-and-drop fields: Text, Email, Number, Date, Textarea, Select, Radio, Checkbox, Info Block, Embed (Media) and Hidden.
* **Client-Side PDF Generation** - Certificates rendered in the browser with html2canvas and jsPDF, from reusable Document Templates with background images.
* **Magic Links** - One-click certificate access through a unique link carrying a random 32-character token, sent by e-mail.
* **Verification** - Authenticity check by authentication code or magic link, on the `/valid` page created at activation.
* **Quiz Mode** - Scored forms with `{{score}}`, `{{max_score}}` and `{{score_percent}}` tokens.

= QR Codes =

* **Certificate QR Codes** - The `{{qr_code}}` token links each certificate to its verification page.
* **QR Code Design** - Settings → QR Code sets dot and eye shapes, colours or a gradient, a centre logo, frames with a call to action, and a transparent background. The design applies to certificates, magic links and short URLs. Every shape offered is checked to scan.
* **QR Code Generator** - Short URLs → QR Code Generator builds standalone codes for a URL, text, Wi-Fi, e-mail, phone, SMS, WhatsApp, contact card (vCard), social profile or calendar event. Codes download as SVG or PNG, or print directly, and each user's last design is remembered. Manual short URLs are made here: for a website or a social profile, a switch puts a click-counted short URL in the code, created on download or print.

= URL Shortener =

* **Short Links** - A built-in short-link domain for plugin-generated URLs, with a QR code per link and cleanup of links that were never clicked.
* **WordPress Integration** - Exposed as the WordPress shortlink and as an `ffc_shortlink` REST field on the opted-in post types.

= Self-Scheduling (Personal Calendars) =

* **Calendar Management** - Multiple calendars with configurable time slots, durations, business hours and blocked dates.
* **Appointment Booking** - Frontend booking widget with real-time slot availability.
* **Email Notifications** - Confirmation, approval, cancellation and reminder e-mails.
* **PDF Receipts** - Downloadable appointment receipts generated client-side.
* **Admin Screens** - Manage, approve and export appointments.

= Audience Scheduling (Group Bookings) =

* **Audience Management** - Hierarchical audiences (groups) with colour coding.
* **Environment Management** - Physical spaces with calendars, working hours, holidays and capacity.
* **Group Bookings** - Schedule activities for whole audiences or individual users.
* **CSV Import & Export** - Import and export audiences and members, with user creation.
* **Conflict Detection** - Conflicts are checked before a booking is confirmed.
* **Email Notifications** - Automatic notifications for new bookings and cancellations.

= Reregistration =

* **Campaign Management** - Reregistration campaigns linked to audiences, with configurable periods.
* **Custom Fields** - Per-audience custom fields (text, textarea, number, date, select, checkbox) with validation, plus a seeded set of standard identity, contact and employment fields.
* **Email Notifications** - Invitation, reminder and confirmation e-mails with editable templates.
* **Approval Workflow** - Manual or automatic approval, with an admin review screen.
* **Record PDF** - PDF records of each submission, from a customisable template.
* **Dashboard Integration** - Users see reregistration banners and can submit and download their record from the dashboard.

= Recruitment (Public-Tender Candidate Queues) =

* **Notice & Candidate Management** - Tender notices (editais), candidates, and classification lists with rank and score.
* **Atomic CSV Import** - Single-transaction wipe-and-reinsert with rollback on any validation error; semicolon (BR/EU) delimiter auto-detection.
* **Convocation Workflow** - Single and bulk calls with an append-only call history, cancellation, and an "Undo decision" action that returns a candidate to the queue.
* **State Machines** - Notice (draft → preliminary → active → closed) and classification lifecycles, with a reopen-freeze rule that protects hired and not-shown rows.
* **Public Queue & Candidate Dashboard** - `[ffc_recruitment_queue]` lists called and uncalled candidates per notice; `[ffc_recruitment_my_calls]` gives each candidate a self-service view of their classifications and calls.
* **Email Dispatch** - Automatic convocation e-mails with masked PII placeholders.

= Date Messages =

* **Birthday E-mails** - Automatic e-mails built from each user's birth date, sent on the day or a set number of days before. Nothing is sent until an administrator creates and activates a rule.
* **Rules** - Several independent rules, each with its own offset, optional audience, subject and HTML body.
* **Preview & Send Now** - See who would receive a rule, and why anyone would be skipped, before anything is sent. Run a date range by hand, or send yourself a test with sample data.
* **Manager Digest** - An optional summary e-mail to chosen users 24 hours after each run.
* **Upcoming Dates** - A panel listing the coming birthdays (day and month only), gated by its own capability.
* **One-Click Opt-Out** - Every message carries a signed unsubscribe link that confirms by POST, and users can also opt out from their dashboard.

= Security & Restrictions =

* **Geofencing** - Restrict form access by GPS coordinates or IP-based areas.
* **Rate Limiting** - Configurable limits per IP, e-mail, CPF/RF and device, with automatic blocking.
* **ID-Based Restriction** - Control certificate issuance by CPF/RF document validation.
* **Ticket System** - Import single-use access codes for exclusive form access.
* **Allowlist / Denylist** - Allow or block specific IDs.
* **Captcha & Honeypot** - A honeypot on every public form, plus a math challenge, an ALTCHA proof-of-work challenge, or both (ALTCHA with a math fallback when JavaScript is off).
* **Data Encryption** - E-mail, CPF/RF, IP address and other sensitive fields are encrypted at rest (AES-256), with salted hashes for lookups.

= Administration =

* **Activity Log** - Audit trail of admin and user actions.
* **User Dashboard** - Personal frontend dashboard for certificates, appointments, bookings, reregistration and profile.
* **CSV Export** - Batched, timeout-safe exports of submissions, appointments, bookings and other data.
* **Data Migrations** - Settings → Migrations runs batched migrations with progress tracking.
* **Maintenance Tools** - Clean up obsolete short URLs, switch off Public Operator Access on forms that have ended, and audit how submissions are linked to WordPress users. The audit is report-only.
* **Scheduled Tasks** - One screen that lists every background task with its next and last run, sets the daily times, and generates the server cron line to install.
* **Identity Resolution** - An audit of conflicting identity records (shared or invalid CPF/RF and e-mails) across the plugin's stores, with accept and resolve actions.
* **Email** - Built-in SMTP settings, one configurable e-mail layout shared by every message, and a global switch to disable all e-mails.
* **REST API** - REST endpoints for external integrations.
* **Capabilities & Roles** - Granular, delegable permissions with three states per domain (hidden / view only / view and edit), dedicated roles, and per-user and per-role editors, so the plugin can be delegated without granting WordPress administrator.
* **Dark Mode** - An admin dark theme (off, on, or following the operating system).

= Integrations =

* **Total Mail Queue** - When the Total Mail Queue plugin is active, every e-mail is queued through it and labelled with the plugin feature that sent it.
* **Cloudflare** - In the opt-in "secure" client-IP mode (Settings → IP Diagnostics), Cloudflare is detected and its IP ranges are refreshed daily, so limits and geofencing use the real visitor IP.
* **IP Geolocation** - IP-based geofencing through ip-api.com or ipinfo.io (API key required for ipinfo.io).
* **Updates from GitHub** - Plugin updates come from the project's GitHub Releases through the native WordPress update screen, and each package's SHA-256 is verified before install.

== Installation ==

1. Upload the `ffcertificate` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to Certificate → Add New Form to create your first form.
4. Place the shortcode `[ffc_form id="FORM_ID"]` on any page or post.
5. Optional: enable or disable modules in Settings → Modules, and install the server cron line shown in Settings → Scheduled Tasks so background tasks run on time.

== Frequently Asked Questions ==

= How do I create a form? =

1. Go to Certificate → Add New Form.
2. Enter a title and add fields with the Form Builder.
3. Choose and adjust the certificate layout.
4. Save, then copy the generated shortcode.

= What are Magic Links? =

Magic Links are unique URLs, sent by e-mail, that let recipients open and download their certificate in one click. Each link carries a random 32-character token. Deleting the submission invalidates the link.

= How do I set up the verification page? =

The plugin creates a `/valid` page during activation. You can also place `[ffc_verification]` on any page.

= How do I create a calendar? =

1. Go to Scheduling → Personal Calendars → New Personal Calendar.
2. Configure business hours, slot duration and capacity.
3. Place the shortcode `[ffc_self_scheduling id="CALENDAR_ID"]` on any page.

= Can I restrict who generates certificates? =

Yes. In each form's restriction settings you can enable allowlist mode, use the ticket system, block IDs with a denylist, or restrict by area with geofencing.

= Do background tasks need a server cron? =

They run on WP-Cron, which only fires when the site gets visits. For reliable timing, especially for scheduled e-mails, open Settings → Scheduled Tasks. The screen shows the exact crontab line for your server (WP-CLI, wget or curl), the state of `DISABLE_WP_CRON`, and any task that is overdue.

= Does the plugin work with page cache plugins (WP Rocket, LiteSpeed Cache, W3 Total Cache)? =

Yes. The plugin includes built-in cache compatibility:

* **Forms (captcha & nonces):** A "Dynamic Fragments" system refreshes captcha challenges and security nonces via AJAX after page load, so forms work even when the HTML is served from a full-page cache.
* **Dashboard pages:** The `[user_dashboard_personal]` shortcode sets the `DONOTCACHEPAGE` constant, sends standard no-cache headers, and triggers LiteSpeed-specific exclusion hooks, so user-specific data is never cached.
* **AJAX endpoints:** Form submissions and data fetching use `admin-ajax.php`, which major cache plugins exclude by default.
* **Diagnostics:** Settings → Cache shows a "Page Cache Compatibility" card with the status of each cache-related feature and the cache plugin detected.

No manual cache exclusion rules are needed.

= Do I need any server configuration on nginx? =

One optional hardening step. Batched CSV exports stage a temporary file (which may contain decrypted PII) under `wp-content/uploads/ffc-tmp/`. On Apache the plugin protects it with a bundled `.htaccess`; **nginx ignores `.htaccess`**, so add a deny rule to your server block:

`location ^~ /wp-content/uploads/ffc-tmp/ { deny all; return 404; }`

The temp file is already short-lived (random name, deleted right after download, daily cleanup cron), so this is defence-in-depth. See `docs/DEPLOYMENT.md` for details.

= How do I translate the plugin? =

The plugin is translation-ready with the `ffcertificate` text domain. Use Loco Translate or Poedit with the `languages/ffcertificate.pot` template. A Portuguese (Brazil) translation is included.

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
Displays the certificate verification interface. Detects magic links via the `?token=` parameter.

Example: `[ffc_verification]`

= [ffc_csv_download] =
Displays the public operator page, where trusted operators can download a form's submissions CSV, start a form early, or postpone its close. Access requires the Form ID and the access hash generated by "Public Operator Access" in the form editor.

* `title` (optional) - Page heading.

Example: `[ffc_csv_download title="Download attendees"]`

= [ffc_self_scheduling] =
Displays a personal calendar with its booking widget.

* `id` (required) - Calendar ID.

Example: `[ffc_self_scheduling id="456"]`

= [ffc_audience] =
Displays the audience scheduling calendar for group bookings.

Example: `[ffc_audience]`

= [user_dashboard_personal] =
Displays the user's personal dashboard with certificates, appointments, audience bookings, reregistration and profile.

Example: `[user_dashboard_personal]`

= [ffc_recruitment_queue] =
Displays the public candidate queue for a tender notice, split into called and uncalled lists.

* `notice` (required) - Notice code (edital).
* `adjutancy` (optional) - Restrict the list to a single adjutancy / role.

Example: `[ffc_recruitment_queue notice="EDITAL-2026-01"]`

= [ffc_recruitment_my_calls] =
Displays the logged-in candidate's own classifications and convocation history.

Example: `[ffc_recruitment_my_calls]`

== Layout & Placeholders ==

In a certificate template, use these dynamic tags. The complete list, including the standard identity, contact and employment field keys, is in Settings → Documentation → Template Variables / Tokens.

= System Tags =
* `{{auth_code}}` - 12-character alphanumeric authentication code (formatted XXXX-XXXX-XXXX)
* `{{form_title}}` - Form title
* `{{submission_date}}` - Issuance date, formatted per the plugin's date setting (`{{date}}` and `{{fill_date}}` are aliases)
* `{{print_date}}` - Date the PDF is generated
* `{{submission_id}}` - Numeric submission ID
* `{{validation_url}}` - Verification page URL
* `{{qr_code}}` - QR code linking to the verification page
* `{{site_name}}`, `{{main_address}}`, `{{logo_gov}}`, `{{logo_org}}` - Branding from Settings → General

= Form Field Tags =
* `{{field_name}}` - Any field name defined in the Form Builder
* Common examples: `{{name}}`, `{{email}}`, `{{cpf_rf}}`, `{{ticket}}`

== Changelog ==

The full changelog with per-release notes lives in [CHANGELOG.md](CHANGELOG.md).
This file used to mirror the last few release entries here, but keeping
two changelogs in sync was creating drift — the canonical record is
now CHANGELOG.md alone.

== Upgrade Notice ==

= 6.33.0 =
New Date Messages module (birthday e-mails, off by default) and a Scheduled Tasks screen. After updating, run two cards in Settings → Migrations: copy stored birth dates to the profile, and split full names into first and last name.

This section carries a short summary of the version being offered, and only
that one — the updater never offers an older release, so an entry for one
could never be shown. For what changed in any release, and for the issues
each change references, see [CHANGELOG.md](CHANGELOG.md).

== Privacy & Data Handling ==

= Data Collected =
* Form submissions (name, e-mail, CPF/RF and custom fields)
* User profile fields (contact, address, employment, birth date) when the dashboard or reregistration collects them
* IP addresses (for rate limiting, geofencing and the audit trail)
* Appointment and group bookings (date, time, contact details)
* Submission and action timestamps

= Data Storage =
All data lives in the plugin's own `wp_ffc_*` tables and in `ffc_`-prefixed user meta and options. The main tables:

* Submissions: `wp_ffc_submissions`, with sensitive fields encrypted
* Appointments: `wp_ffc_self_scheduling_appointments`
* Rate limiting: `wp_ffc_rate_limits` and `wp_ffc_rate_limit_logs`
* Activity log: `wp_ffc_activity_log`
* Profiles: `wp_ffc_user_profiles`

Deleting the plugin removes its tables, options and user meta only when "Delete all plugin data on uninstall" is enabled in Settings → Advanced.

= Data Retention =
* Configurable automatic cleanup for old submissions
* Manual deletion in the admin panel
* WordPress personal-data exporters and erasers are registered
* Deleting a submission invalidates its magic link and QR code
