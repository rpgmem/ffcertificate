# Free Form Certificate

![Tests](https://github.com/rpgmem/wp-ffcertificate/workflows/Tests/badge.svg)
![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue)
![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)
![Version](https://img.shields.io/badge/version-4.0.0-orange)

WordPress plugin for creating dynamic forms, managing submissions, generating PDF certificates, and exporting data to CSV.

## Features

- ✅ **Dynamic Form Builder** - Create custom forms with various field types
- ✅ **Submission Management** - Complete admin interface for managing form submissions
- ✅ **PDF Certificate Generation** - Automatically generate beautiful PDF certificates
- ✅ **QR Code Integration** - Add QR codes to certificates for verification
- ✅ **CSV Export** - Export submissions with all database columns
- ✅ **Email Notifications** - Send automated emails with certificates
- ✅ **User Dashboard** - Frontend dashboard for users to view their submissions
- ✅ **Magic Links** - Secure, time-limited access links for viewing submissions
- ✅ **Document Validation** - CPF and RF validation for Brazilian documents
- ✅ **Migration System** - Automated database migrations for updates
- ✅ **PSR-4 Namespaces** - Modern, clean codebase following PHP standards
- ✅ **Multilingual** - Full i18n support (Portuguese included)

## Requirements

- **PHP**: 7.4 or higher
- **WordPress**: 5.0 or higher
- **MySQL**: 5.6 or higher

## Installation

### Via WordPress Admin

1. Download the plugin ZIP file
2. Go to **Plugins > Add New > Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Activate the plugin

### Via FTP

1. Extract the plugin ZIP file
2. Upload the `wp-ffcertificate` folder to `/wp-content/plugins/`
3. Activate the plugin through the WordPress admin panel

### Via Composer

```bash
composer require rpgmem/wp-ffcertificate
```

## Quick Start

1. **Create a Form**
   - Go to **Forms > Add New**
   - Add fields, configure settings, and design your certificate template

2. **Configure Settings**
   - Navigate to **Settings > Free Form Certificate**
   - Set up email templates, QR code options, and other preferences

3. **Collect Submissions**
   - Share your form URL with users
   - Submissions appear in **Forms > Submissions**

4. **Generate Certificates**
   - Certificates are automatically generated upon form submission
   - Download or email certificates to users

## Development

### Setup Development Environment

```bash
# Clone the repository
git clone https://github.com/rpgmem/wp-ffcertificate.git
cd wp-ffcertificate

# Install dependencies
composer install

# Install dev dependencies for testing
composer install --dev
```

### Running Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test:coverage

# Run specific test suite
./vendor/bin/phpunit --testsuite "Unit Tests"
```

See [tests/README.md](tests/README.md) for detailed testing documentation.

### Code Quality

This project follows:
- **PSR-4** autoloading
- **PSR-12** coding style
- Strict type declarations
- Comprehensive PHPUnit tests

## Architecture

### PSR-4 Namespace Structure

```
FreeFormCertificate\
├── Admin\              → Admin interface classes
├── API\                → REST API endpoints
├── Core\               → Core utilities and helpers
├── Frontend\           → Frontend form rendering
├── Generators\         → PDF and QR code generators
├── Integrations\       → Email and third-party integrations
├── Migrations\         → Database migration system
├── Repositories\       → Data access layer
├── Security\           → Security and rate limiting
├── Settings\           → Settings management
├── Shortcodes\         → WordPress shortcodes
├── Submissions\        → Submission handling
└── UserDashboard\      → User dashboard functionality
```

### Key Classes

- `Utils` - Utility functions (validation, formatting, sanitization)
- `SubmissionHandler` - Handles form submissions
- `PDFGenerator` - Generates PDF certificates
- `MigrationManager` - Manages database migrations
- `Loader` - Main plugin loader

## Database Schema

### `wp_ffc_submissions` Table

Stores all form submissions with metadata:

- `id` - Primary key
- `form_id` - Associated form ID
- `submission_data` - JSON encoded submission fields
- `auth_code` - Unique verification code
- `certificate_path` - Path to generated PDF
- `created_at` - Submission timestamp
- `edited_at` - Last edit timestamp
- `edited_by` - User who edited the submission
- Additional metadata columns

### `wp_ffc_activity_log` Table

Audit log for tracking changes:

- `id` - Primary key
- `submission_id` - Reference to submission
- `user_id` - User who performed action
- `action` - Action type (created, updated, deleted)
- `details` - JSON encoded action details
- `timestamp` - When action occurred

## Hooks & Filters

The plugin provides numerous hooks for customization:

### Actions

```php
// Before submission is saved
do_action('ffc_before_submission_save', $submission_data);

// After submission is saved
do_action('ffc_after_submission_save', $submission_id, $submission_data);

// Before PDF generation
do_action('ffc_before_pdf_generate', $submission_id);

// After PDF generation
do_action('ffc_after_pdf_generate', $submission_id, $pdf_path);
```

### Filters

```php
// Modify submission data before save
$data = apply_filters('ffc_submission_data', $data, $form_id);

// Customize PDF template
$template = apply_filters('ffc_pdf_template', $template, $submission_id);

// Modify email content
$email_content = apply_filters('ffc_email_content', $content, $submission_id);
```

See [docs/HOOKS-DOCUMENTATION.md](docs/HOOKS-DOCUMENTATION.md) for complete hook reference.

## Changelog

### v4.0.0 (2026-01-26) - Major Release

**Breaking Changes:**
- ✅ Complete PSR-4 namespace migration
- ⚠️ Removed all backward compatibility aliases
- ⚠️ Old class names (`FFC_*`) no longer available

**Improvements:**
- ✅ 21 critical hotfixes applied
- ✅ Enhanced CSV export with all DB columns
- ✅ Fixed PHP 8+ compatibility issues
- ✅ Improved code quality and organization
- ✅ Added automated testing infrastructure

See [CHANGELOG.md](CHANGELOG.md) for complete version history.

## Contributing

We welcome contributions! Please follow these guidelines:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Write tests for your changes
4. Ensure all tests pass (`composer test`)
5. Commit your changes (`git commit -m 'Add amazing feature'`)
6. Push to the branch (`git push origin feature/amazing-feature`)
7. Open a Pull Request

## Support

- **Issues**: [GitHub Issues](https://github.com/rpgmem/wp-ffcertificate/issues)
- **Discussions**: [GitHub Discussions](https://github.com/rpgmem/wp-ffcertificate/discussions)
- **Documentation**: [docs/](docs/)

## License

This plugin is licensed under the [GPL-2.0-or-later](LICENSE) license.

## Credits

Developed and maintained by [Alex Meusburger](https://github.com/rpgmem)

### Third-Party Libraries

- [html2canvas](https://html2canvas.hertzen.com/) - HTML to Canvas conversion
- [jsPDF](https://github.com/parallax/jsPDF) - PDF generation
- [PHPQRCode](http://phpqrcode.sourceforge.net/) - QR code generation

## Roadmap

- [ ] **v4.1** - Advanced form field types (file upload, date picker)
- [ ] **v4.2** - Multi-step forms
- [ ] **v4.3** - Payment integration (WooCommerce, Stripe)
- [ ] **v4.4** - Advanced analytics dashboard
- [ ] **v5.0** - API-first architecture with REST and GraphQL

---

**Made with ❤️ for the WordPress community**
