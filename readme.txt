=== Free Form Certificate ===
Contributors: alexmeusburger
Tags: certificate, form builder, pdf generation, verification, validation
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 4.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Create dynamic forms, generate PDF certificates, and validate authenticity with magic link access.

== Description ==

Free Form Certificate is a robust WordPress solution for creating dynamic forms and automated certificate issuance. The plugin features a fully modular architecture with specialized classes for maximum maintainability and extensibility.

= Key Features =

* **Drag & Drop Form Builder:** Intuitive interface to create custom fields (Text, Email, Number, Date, Select, Radio, Textarea, Hidden).
* **Client-Side PDF Generation:** Uses html2canvas and jsPDF to generate A4 landscape certificates with support for custom background images.
* **Magic Links (NEW in v2.8.0):** One-click certificate access via unique URLs sent in emails.
* **Verification System:** Validation shortcode `[ffc_verification]` for certificate authenticity via unique code.
* **ID-Based Restriction (CPF/RF):** Control unique issuance or "reprint" mode based on document.
* **Ticket System:** Import list of exclusive codes for form access.
* **Advanced Security:** Bot protection with integrated Math Captcha, Honeypot, and rate limiting.
* **Data Export:** CSV export tool with filters by form and date.
* **Asynchronous Notifications:** Email sending to administrator via WP-Cron to not block user flow.
* **Automatic Cleanup:** Daily routine to delete old records according to configuration.
* **Modular Architecture:** 15 specialized classes following Single Responsibility Principle (SRP).

== Installation ==

1. Upload the `wp-ffcertificate` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Access the 'Free Form Certificate' menu to create your first form.
4. Use the shortcode `[ffc_form id="FORM_ID"]` on any page or post.
5. Check your email for the magic link to access your certificate instantly!

== Frequently Asked Questions ==

= How do I create a form? =

1. Navigate to "Free Form Certificate" > "Add New Form"
2. Enter a title for your form
3. Use the Form Builder to add fields
4. Configure the certificate layout in the "Certificate Layout" section
5. Save and copy the shortcode

= What are Magic Links? (NEW in v2.8.0) =

Magic Links are unique, secure URLs sent via email that allow recipients to instantly access and download their certificates without entering codes or solving captchas. Each link contains a cryptographically secure 32-character token that provides one-click access.

Example: `https://yoursite.com/valid?token=a1b2c3d4e5f6...`

= How do I enable the verification page? =

The plugin automatically creates a `/valid` page during activation. You can also manually create a page with the shortcode `[ffc_verification]`.

= Can I restrict who can generate certificates? =

Yes! In the "Restriction & Security" section of each form, you can:
- Enable whitelist mode (only listed IDs can generate)
- Use ticket system (unique codes)
- Block specific IDs via denylist

= Are Magic Links secure? =

Yes! Magic Links use:
- 32-character cryptographically secure tokens (340 undecillion possible combinations)
- Rate limiting (10 attempts per minute per IP)
- No expiration (links work forever, but can be disabled by deleting the submission)
- Unique per submission (cannot be guessed or reused)

= How do I translate the plugin? =

The plugin is fully translation-ready. Use tools like Poedit or Loco Translate to create translations from the `languages/ffc.pot` file.

= Can I extend the plugin with custom functionality? =

Yes! The modular architecture makes it easy to extend. Each class has a single responsibility and can be extended or replaced independently.

== Screenshots ==

1. Form Builder with drag & drop interface
2. Certificate layout editor with live preview
3. Submissions management with PDF download
4. Security settings (whitelist, tickets, denylist)
5. Frontend certificate generation
6. Magic link email with one-click access
7. Certificate preview page with download button
8. Modular class architecture diagram

== Changelog ==

= 4.0.0 (2026-02-02) =
* **Namespace Migration:** Complete migration to PHP namespaces (FreeFormCertificate\\*)
* **Calendar System:** Appointment scheduling with email confirmations, reminders, and PDF receipts
* **User Dashboard:** Personal certificate and appointment management
* **REST API:** Full REST API for external integrations
* **QR Code Generator:** QR codes on certificates linking to verification page
* **Migration Framework:** Automated data migrations with progress tracking
* **Geofencing:** GPS/IP-based form access restrictions
* **Code Quality:** WordPress Plugin Check compliance, security hardening, and i18n improvements

2.9.1 (2025-12-29)
--------------------
FIXED:
  - Magic links fatal error (critical)
  - Duplicate require in loader

ADDED:
  - Rate Limiter (anti-spam protection)
  - Activity Log (audit system)
  - Form Cache (performance)
  - Utils: CPF validation
  - Utils: 20+ helper functions

CHANGED:
  - Submission Handler uses FFC_Utils::get_user_ip()
  - Loader loads new utility classes
  - Activator creates activity_log table

IMPROVED:
  - Code quality
  - Security
  - Performance
  - Maintainability

2.9.0 (2025-12-28) - QRCode to Magic Links =
--------------------

= 2.8.0 (2025-12-28) - Magic Links Feature =
* **Magic Links:** One-click certificate access via unique URLs
  - Added `magic_token` column to submissions table (VARCHAR(32) with index)
  - Automatic token generation for all new submissions
  - Backward migration for existing submissions (generates tokens on activation)
  - Tokens included in email notifications with styled button
* **Certificate Preview:** Modern preview interface before download
  - Displays certificate details (name, event, date, auth code)
  - Shows participant data in organized layout
  - Download button with loading states and animations
  - Responsive design for mobile devices
* **Email Enhancements:**
  - Redesigned email template with modern styling
  - Magic link button with gradient background
  - Authentication code displayed prominently
  - Certificate preview embedded in email
  - Fallback to manual verification URL
* **Security Improvements:**
  - Rate limiting: 10 verification attempts per minute per IP
  - Cryptographically secure 32-character tokens
  - No captcha required for magic links (token is authentication)
  - Transient-based rate limiting (no additional database tables)
* **New Classes:**
  - Enhanced `FFC_Verification_Handler` with `verify_by_magic_token()` method
  - Enhanced `FFC_Shortcodes` to detect `?token=` parameter
  - Enhanced `FFC_Email_Handler` to include magic links in emails
* **Frontend Improvements:**
  - Automatic magic link detection on page load
  - AJAX verification without page reload
  - Loading spinner with smooth animations
  - Download button with state management
  - Works on both magic link and manual verification
* **Database Changes:**
  - New column: `magic_token` VARCHAR(32) with index
  - Automatic migration on plugin activation
  - Fallback token generation for old submissions
* **User Experience:**
  - Zero-friction certificate access (one click from email)
  - Download button available after verification
  - Re-download option (unlimited downloads)
  - Mobile-optimized preview layout
* **Backward Compatibility:** Fully backward compatible with existing installations

= 2.7.0 (2025-12-28) - Modular Architecture =
* **Major Code Reorganization:** Complete plugin restructured into 15 specialized classes
* **New Classes Created:**
  - `FFC_Shortcodes`: Handles all shortcode rendering
  - `FFC_Form_Processor`: Processes form submissions with validation
  - `FFC_Verification_Handler`: Manages certificate verification
  - `FFC_Email_Handler`: Handles all email-related functionality
  - `FFC_CSV_Exporter`: Manages CSV export operations
* **Refactored Classes:**
  - `FFC_Frontend`: Now acts as orchestrator (600→150 lines)
  - `FFC_Submission_Handler`: Pure CRUD operations (400→150 lines)
* **Benefits:**
  - Single Responsibility Principle (SRP) applied throughout
  - Improved testability with isolated components
  - Better maintainability with clear separation of concerns
  - Easier to extend with new features
  - Reduced coupling between components
* **Dependency Injection:** Proper DI pattern implemented in `FFC_Loader`
* **No Breaking Changes:** All functionality remains intact

= 2.6.0 (2025-12-28) - Complete Reorganization =
* **Code Architecture:** Complete plugin reorganization with modular OOP structure
* **Classes Separation:** 
  - Simplified `class-ffc-cpt.php` (only CPT registration and duplication)
  - Consolidated all metaboxes in `class-ffc-form-editor.php`
  - Added missing methods: `update_submission()` and `delete_all_submissions()`
* **Internationalization (i18n):** Full implementation of translation support
  - All PHP strings wrapped in `__()` and `_e()` functions
  - JavaScript strings localized via `wp_localize_script`
  - Created `.pot` file for translators
* **CSS Consolidation:** Removed all inline styles
  - Moved to `ffc-admin.css` and `ffc-frontend.css`
  - Better organization and maintainability
* **Code Quality:**
  - Removed dead code
  - Eliminated redundancies
  - All comments in English
  - Cross-file dependency validation
* **Bug Fixes:**
  - Fixed missing method calls
  - Corrected duplicate metabox registration
  - Fixed SMTP settings toggle visibility

= 2.0.0 =
* **PDF Refactoring:** Migration from simple image to high-fidelity PDF (A4 Landscape) using jsPDF
* **Mobile Optimization:** Strategic delays and progress overlay for correct rendering on mobile devices
* **Security:** Implementation of dynamic Math Captcha with hash validation on backend
* **Reprint Logic:** New duplicate detection logic allowing certificate recovery
* **Admin Improvements:** PDF download buttons directly in submissions list
* **CORS Fix:** Added `crossorigin="anonymous"` attribute in image rendering

= 1.5.0 =
* Ticket system implementation (single-use codes)
* Form cloning functionality
* Global settings tab with automatic log cleanup

= 1.0.0 =
* Initial release with basic Form Builder and CSV export

== Magic Links Usage ==

= How Magic Links Work =

1. **User submits form** → Certificate is generated
2. **Email is sent** with magic link button
3. **User clicks button** → Taken to verification page
4. **Certificate preview loads** automatically (no captcha)
5. **User downloads PDF** with one click

= Magic Link Format =
```
https://yoursite.com/valid?token=a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
```

= Email Example =

The user receives an email with:
- Authentication code (for manual verification)
- Magic link button ("🔗 Access Certificate Online")
- Certificate preview embedded
- Manual verification URL as fallback

= Security Considerations =

**Tokens are:**
- 32 characters long (16^32 possible combinations)
- Cryptographically secure (generated with `random_bytes()`)
- Unique per submission
- Never expire (but can be invalidated by deleting submission)
- Protected by rate limiting

**Rate Limiting:**
- 10 attempts per minute per IP address
- Applies to both magic links and manual verification
- Uses WordPress transients (no additional tables)
- Automatically resets after 60 seconds

= Disabling Magic Links =

Magic links cannot be disabled globally, but you can:
1. Not include them in custom email templates
2. Delete submissions to invalidate their tokens
3. Use only manual verification workflow

== Layout & Placeholders ==

In the certificate layout editor, you can use the following dynamic tags:

= System Tags =
* `{{auth_code}}`: 12-digit authentication code
* `{{form_title}}`: Current form title
* `{{submission_date}}`: Issuance date (formatted according to WP settings)
* `{{submission_id}}`: Numeric ID of the submission in database
* `{{validation_url}}`: URL of the verification page

= Form Field Tags =
* `{{field_name}}`: Any field name defined in Form Builder
* Common examples: `{{name}}`, `{{email}}`, `{{cpf_rf}}`, `{{ticket}}`

= Formatting Notes =
* CPF/RF fields are automatically formatted with dots and dashes
* Auth codes are formatted as XXXX-XXXX-XXXX
* Date fields respect WordPress date format settings

== Shortcodes ==

= [ffc_form] =
Displays the certificate issuance form.

Parameters:
* `id` (required): Form ID

Example: `[ffc_form id="123"]`

= [ffc_verification] =
Displays the certificate verification interface.

Automatically detects magic links via `?token=` parameter.

No parameters required.

Example: `[ffc_verification]`

**Magic Link Access:**
When accessed with `?token=` parameter, displays certificate preview automatically without captcha.

**Manual Access:**
When accessed without token, displays traditional verification form with captcha.

== File Structure ==
```
wp-ffcertificate/
├── wp-ffcertificate.php (Main plugin file)
├── readme.txt
├── includes/ 
│   ├── settings-tabs/
│   	├── ff-tab-documentation.php
│   	├── ffc-tab-migrations.php
│   ├── class-ffc-activator.php (Installation logic + migration)
│   ├── class-ffc-activity-log.php
│   ├── class-ffc-admin-ajax.php
│   ├── class-ffc-admin.php (Admin interface)
│   ├── class-ffc-cpt.php (Custom Post Type)
│   ├── class-ffc-csv-exporter.php (CSV export)
│   ├── class-ffc-deactivator.php (Cleanup logic)
│   ├── class-ffc-email-handler.php (Email & SMTP + magic links)
│   ├── class-ffc-form-cache.php
│   ├── class-ffc-form-editor.php (Metaboxes)
│   ├── class-ffc-form-processor.php (Form processing)
│   ├── class-ffc-frontend.php (Frontend orchestrator)
│   ├── class-ffc-loader.php (Dependency injection)
│   ├── class-ffc-migration-manager.php
│   ├── class-ffc-pdf-generator.php
│   ├── class-ffc-qrcode-generator.php
│   ├── class-ffc-rate-limiter.php
│   ├── class-ffc-settings.php (Plugin settings)
│   ├── class-ffc-shortcodes.php (Shortcode rendering + magic link detection)
│   ├── class-ffc-submission-handler.php (Database CRUD + token generation)
│   ├── class-ffc-submissions-list-table.php (Admin table)
│   ├── class-ffc-utils.php (Shared utilities)
│   └── class-ffc-verification-handler.php (Verification + rate limiting)
├── assets/
│   ├── css/
│   │   ├── ffc-pdf-core.css (PDF rendering)
│   │   ├── ffc-admin.css (Admin styles)
│   │   ├── ffc-editor-placeholders.css
│   │   └── ffc-frontend.css (Public styles + magic link preview)
│   └── js/
│       ├── html2canvas.min.js (v1.4.1)
│       ├── jspdf.umd.min.js (v2.5.1)
│       ├── ffc-admin.js (Admin logic)
│       └── ffc-frontend.js (Public logic + magic link handler)
│       └── ffc-frontend-utils.js
├── html/ (Optional certificate templates)
└── languages/
    └── ffc.pot (Translation template)
```

== Architecture ==

The plugin follows a modular architecture with clear separation of concerns:

= Data Layer =
* **FFC_Submission_Handler**: CRUD operations for submissions + magic token generation
* **FFC_CSV_Exporter**: Export functionality

= Service Layer =
* **FFC_Email_Handler**: Email sending, SMTP configuration, and magic link inclusion
* **FFC_Form_Processor**: Form validation and processing
* **FFC_Verification_Handler**: Certificate verification logic + rate limiting

= Presentation Layer =
* **FFC_Frontend**: Orchestrates frontend functionality
* **FFC_Shortcodes**: Renders shortcodes and detects magic links
* **FFC_Admin**: Manages admin interface
* **FFC_CPT**: Custom Post Type registration
* **FFC_Form_Editor**: Form builder metaboxes

= Infrastructure =
* **FFC_Loader**: Dependency injection container
* **FFC_Utils**: Shared utility functions
* **FFC_Activator/Deactivator**: Installation/cleanup + token migration

== Database Schema ==

= wp_ffc_submissions Table =
```sql
CREATE TABLE wp_ffc_submissions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_id bigint(20) unsigned NOT NULL,
  submission_date datetime NOT NULL,
  data longtext NOT NULL,
  user_ip varchar(100) DEFAULT NULL,
  email varchar(255) DEFAULT NULL,
  status varchar(20) DEFAULT 'publish',
  magic_token varchar(32) DEFAULT NULL,  -- NEW in v2.8.0
  PRIMARY KEY (id),
  KEY form_id (form_id),
  KEY status (status),
  KEY email (email),
  KEY magic_token (magic_token)  -- NEW in v2.8.0
);
```

**New in v2.8.0:**
- `magic_token` column: Stores unique 32-character token for magic links
- Index on `magic_token` for fast lookups
- Automatically populated on new submissions
- Backfilled for existing submissions on plugin activation

== Submission Flow ==

1. **User submits form** → `FFC_Form_Processor::handle_submission_ajax()`
2. **Security validation** → Captcha + Honeypot
3. **Restriction check** → Whitelist/Denylist/Tickets
4. **Reprint detection** → Check for existing submission
5. **Save to database** → `FFC_Submission_Handler::process_submission()`
   - Generates `magic_token` (32 hex characters)
   - Saves to `magic_token` column
6. **Schedule email** → WP-Cron triggers `FFC_Email_Handler`
   - Includes magic link in email body
   - Passes `magic_token` to email template
7. **Generate PDF** → Client-side via html2canvas + jsPDF
8. **Download certificate** → Automatic browser download

== Magic Link Flow (NEW in v2.8.0) ==

1. **User clicks magic link** in email
2. **Page loads** → `FFC_Shortcodes::render_verification_page()`
   - Detects `?token=` parameter
   - Renders magic link container with loading state
3. **JavaScript activates** → `handleMagicLinkVerification()` in ffc-frontend.js
   - Reads `data-magic-token` attribute
   - AJAX POST to `ffc_verify_magic_token`
4. **Backend verifies** → `FFC_Verification_Handler::handle_magic_verification_ajax()`
   - Checks rate limiting (10/min per IP)
   - Validates token format (32 hex chars)
   - Searches database: `WHERE magic_token = ?`
   - Returns certificate data + PDF metadata
5. **Frontend displays** preview with download button
6. **User clicks download** → PDF generated and downloaded
7. **Re-download available** → Button changes to "Download Again"

== Security Features ==

= Magic Link Security (NEW in v2.8.0) =

**Token Generation:**
- Uses `random_bytes(16)` → converted to 32 hex characters
- Cryptographically secure random number generator
- 16^32 = 340,282,366,920,938,463,463,374,607,431,768,211,456 possibilities

**Rate Limiting:**
- 10 verification attempts per minute per IP
- Applies to both magic links and manual verification
- Uses WordPress transients (cache-based, no DB writes)
- Automatic cleanup after 60 seconds

**Validation:**
- Token must be exactly 32 characters
- Only hexadecimal characters allowed (0-9, a-f)
- SQL injection protected via `$wpdb->prepare()`
- No XSS vulnerabilities (all outputs escaped)

= Traditional Security =

**Whitelist Mode:**
Restrict certificate issuance to a specific list of IDs/CPFs.
Configure in: Form Editor > Restriction & Security > Allowlist

**Ticket System:**
Require a unique ticket code to generate certificate.
Tickets are automatically removed after use.
Configure in: Form Editor > Restriction & Security > Ticket Generator

**Denylist:**
Block specific IDs or tickets from generating certificates.
Configure in: Form Editor > Restriction & Security > Denylist

**Math Captcha:**
Built-in protection against bots on all forms.
Dynamically generated math questions with hash validation.

**Honeypot:**
Hidden field to trap spam bots.
Automatically included in all forms.

== Data Management ==

= CSV Export =
- Available in "Submissions" screen
- Optional filtering by form
- Includes all submission metadata
- Translatable headers
- Semicolon delimiter (Excel compatible)
- Managed by `FFC_CSV_Exporter` class

= Automatic Cleanup =
- Daily WP-Cron event (`ffc_daily_cleanup_hook`)
- Deletes submissions older than configured days
- Configure in: Settings > General > Auto-delete (days)
- Set to 0 to disable

= Danger Zone =
- Delete all submissions (with confirmation)
- Delete submissions from specific form
- Available in: Settings > General > Danger Zone

== Technical Notes ==

= WP-Cron Events =
- `ffc_process_submission_hook`: Asynchronous email sending (passes magic_token)
- `ffc_daily_cleanup_hook`: Old submissions cleanup
- `ffc_continue_token_migration`: Continuation of large token migrations

= PDF Generation =
- Client-side rendering (no server load)
- A4 Landscape (1123x794px)
- Background image support
- Custom HTML/CSS layouts
- Mobile optimized with strategic delays

= Class Dependencies (Updated for v2.8.0) =
```
FFC_Loader (Container)
├── FFC_Submission_Handler (+ magic_token generation)
├── FFC_Email_Handler (+ magic link in emails)
├── FFC_CSV_Exporter
├── FFC_CPT
├── FFC_Admin (requires: Submission_Handler, CSV_Exporter)
└── FFC_Frontend (requires: Submission_Handler, Email_Handler)
    └── Loads:
        ├── FFC_Shortcodes (+ magic link detection)
        ├── FFC_Form_Processor
        └── FFC_Verification_Handler (+ rate limiting)
```

== Developer Notes ==

= Extending the Plugin =

The modular architecture makes it easy to extend functionality:

**Add custom verification methods:**
Extend `FFC_Verification_Handler` with new verification logic.

**Add new export formats:**
Create a new class similar to `FFC_CSV_Exporter` and inject it where needed.

**Add email providers:**
Extend `FFC_Email_Handler` or create a new handler class.

**Custom validation:**
Extend `FFC_Form_Processor` and override validation methods.

**New shortcodes:**
Extend `FFC_Shortcodes` class with additional render methods.

= Hooks & Filters =

**Filters:**
- `ffc_allowed_html_tags`: Modify allowed HTML tags in certificates

**Actions:**
- `ffc_process_submission_hook`: Triggered after submission save (receives magic_token)
- `ffc_daily_cleanup_hook`: Triggered daily for cleanup
- `ffc_continue_token_migration`: Continuation of large migrations

**AJAX Actions (NEW in v2.8.0):**
- `ffc_verify_magic_token`: Magic link verification (no captcha)
- `ffc_verify_certificate`: Manual verification (with captcha)
- `ffc_submit_form`: Form submission

= Testing =

Each class can be unit tested independently thanks to dependency injection:
```php
// Example: Testing Magic Link Verification
$mock_handler = $this->createMock(FFC_Submission_Handler::class);
$mock_handler->method('get_submission_by_token')
             ->willReturn(['id' => 1, 'data' => '{}', 'magic_token' => 'abc123']);

$verifier = new FFC_Verification_Handler($mock_handler);
$result = $verifier->verify_by_magic_token('abc123');

$this->assertTrue($result['found']);
```

== Privacy & GDPR Compliance ==

= Data Collected =
- User submissions (name, email, custom fields)
- IP addresses (for rate limiting and audit trail)
- Submission timestamps

= Data Storage =
- All data stored in `wp_ffc_submissions` table
- Magic tokens stored in `magic_token` column
- Rate limiting data stored in WordPress transients (auto-expire)

= Data Retention =
- Configurable automatic cleanup (default: never expire)
- Manual deletion available in admin panel
- Deleting a submission invalidates its magic link

= User Rights =
- Right to access: Users receive certificate via email
- Right to deletion: Admins can delete submissions
- Right to portability: CSV export available

== Support ==

For bug reports and feature requests, please contact the plugin author.

For questions about Magic Links security, please refer to the Security Features section above.

== Upgrade Notice ==

= 2.8.0 =
Major new feature: Magic Links for one-click certificate access! Database migration will run automatically. Backup recommended. All existing functionality remains intact.

= 2.7.0 =
Major architectural improvement with modular classes. Backup recommended. No breaking changes - all functionality remains intact.

= 2.6.0 =
Major code reorganization. Backup your database before updating. All functionality remains intact.