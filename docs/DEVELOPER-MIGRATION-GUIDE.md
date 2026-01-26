# Developer Migration Guide: PSR-4 Namespaces

## 📌 Overview

Free Form Certificate v3.2.0 migrated to PSR-4 namespaces. **All old class names still work** via backward compatibility aliases, but new code should use namespaced classes.

---

## ✅ Quick Start

### Old Way (Still works, but deprecated)
```php
// ❌ Old style - will be removed in v4.0.0
$ip = FFC_Utils::get_user_ip();
$table = FFC_Utils::get_submissions_table();
```

### New Way (Recommended)
```php
// ✅ New style - PSR-4 namespaces
use FreeFormCertificate\Core\Utils;

$ip = Utils::get_user_ip();
$table = Utils::get_submissions_table();
```

---

## 🗺️ Class Name Mapping

### Core Classes

| Old Name | New Namespaced Name | Import Statement |
|----------|-------------------|------------------|
| `FFC_Utils` | `FreeFormCertificate\Core\Utils` | `use FreeFormCertificate\Core\Utils;` |
| `FFC_Encryption` | `FreeFormCertificate\Core\Encryption` | `use FreeFormCertificate\Core\Encryption;` |
| `FFC_Debug` | `FreeFormCertificate\Core\Debug` | `use FreeFormCertificate\Core\Debug;` |
| `FFC_Activity_Log` | `FreeFormCertificate\Core\ActivityLog` | `use FreeFormCertificate\Core\ActivityLog;` |

### Repositories

| Old Name | New Namespaced Name | Import Statement |
|----------|-------------------|------------------|
| `FFC_Form_Repository` | `FreeFormCertificate\Repositories\FormRepository` | `use FreeFormCertificate\Repositories\FormRepository;` |
| `FFC_Submission_Repository` | `FreeFormCertificate\Repositories\SubmissionRepository` | `use FreeFormCertificate\Repositories\SubmissionRepository;` |
| `FFC_Abstract_Repository` | `FreeFormCertificate\Repositories\AbstractRepository` | `use FreeFormCertificate\Repositories\AbstractRepository;` |

### Submissions

| Old Name | New Namespaced Name | Import Statement |
|----------|-------------------|------------------|
| `FFC_Submission_Handler` | `FreeFormCertificate\Submissions\SubmissionHandler` | `use FreeFormCertificate\Submissions\SubmissionHandler;` |
| `FFC_Form_Cache` | `FreeFormCertificate\Submissions\FormCache` | `use FreeFormCertificate\Submissions\FormCache;` |

### Frontend

| Old Name | New Namespaced Name | Import Statement |
|----------|-------------------|------------------|
| `FFC_Frontend` | `FreeFormCertificate\Frontend\Frontend` | `use FreeFormCertificate\Frontend\Frontend;` |
| `FFC_Form_Processor` | `FreeFormCertificate\Frontend\FormProcessor` | `use FreeFormCertificate\Frontend\FormProcessor;` |
| `FFC_Shortcodes` | `FreeFormCertificate\Frontend\Shortcodes` | `use FreeFormCertificate\Frontend\Shortcodes;` |
| `FFC_Verification_Handler` | `FreeFormCertificate\Frontend\VerificationHandler` | `use FreeFormCertificate\Frontend\VerificationHandler;` |

### Admin

| Old Name | New Namespaced Name | Import Statement |
|----------|-------------------|------------------|
| `FFC_Admin` | `FreeFormCertificate\Admin\Admin` | `use FreeFormCertificate\Admin\Admin;` |
| `FFC_Settings` | `FreeFormCertificate\Admin\Settings` | `use FreeFormCertificate\Admin\Settings;` |
| `FFC_CSV_Exporter` | `FreeFormCertificate\Admin\CSVExporter` | `use FreeFormCertificate\Admin\CSVExporter;` |
| `FFC_CPT` | `FreeFormCertificate\Admin\CPT` | `use FreeFormCertificate\Admin\CPT;` |

### Integrations

| Old Name | New Namespaced Name | Import Statement |
|----------|-------------------|------------------|
| `FFC_Email_Handler` | `FreeFormCertificate\Integrations\EmailHandler` | `use FreeFormCertificate\Integrations\EmailHandler;` |
| `FFC_IP_Geolocation` | `FreeFormCertificate\Integrations\IpGeolocation` | `use FreeFormCertificate\Integrations\IpGeolocation;` |

### Generators

| Old Name | New Namespaced Name | Import Statement |
|----------|-------------------|------------------|
| `FFC_PDF_Generator` | `FreeFormCertificate\Generators\PdfGenerator` | `use FreeFormCertificate\Generators\PdfGenerator;` |
| `FFC_QR_Code_Generator` | `FreeFormCertificate\Generators\QRCodeGenerator` | `use FreeFormCertificate\Generators\QRCodeGenerator;` |
| `FFC_Magic_Link_Helper` | `FreeFormCertificate\Generators\MagicLinkHelper` | `use FreeFormCertificate\Generators\MagicLinkHelper;` |

### Security

| Old Name | New Namespaced Name | Import Statement |
|----------|-------------------|------------------|
| `FFC_Rate_Limiter` | `FreeFormCertificate\Security\RateLimiter` | `use FreeFormCertificate\Security\RateLimiter;` |
| `FFC_Geofence` | `FreeFormCertificate\Security\Geofence` | `use FreeFormCertificate\Security\Geofence;` |

> **Complete mapping:** See `includes/class-ffc-aliases.php` for all 65 class aliases.

---

## 🎯 Migration Examples

### Example 1: Utility Functions

**Before (v3.1.x):**
```php
function my_custom_function() {
    $ip = FFC_Utils::get_user_ip();
    $table = FFC_Utils::get_submissions_table();
    return $table;
}
```

**After (v3.2.0+):**
```php
use FreeFormCertificate\Core\Utils;

function my_custom_function() {
    $ip = Utils::get_user_ip();
    $table = Utils::get_submissions_table();
    return $table;
}
```

### Example 2: Repositories

**Before:**
```php
function get_form_data($form_id) {
    $repo = new FFC_Form_Repository();
    return $repo->get_by_id($form_id);
}
```

**After:**
```php
use FreeFormCertificate\Repositories\FormRepository;

function get_form_data($form_id) {
    $repo = new FormRepository();
    return $repo->get_by_id($form_id);
}
```

### Example 3: Hooks and Filters

**Before:**
```php
add_action('ffc_after_submission_saved', function($submission_id, $form_id, $data) {
    $handler = new FFC_Email_Handler();
    $handler->send_notification($data['email']);
}, 10, 3);
```

**After:**
```php
use FreeFormCertificate\Integrations\EmailHandler;

add_action('ffc_after_submission_saved', function($submission_id, $form_id, $data) {
    $handler = new EmailHandler();
    $handler->send_notification($data['email']);
}, 10, 3);
```

### Example 4: Custom Plugin Integration

**Before:**
```php
class My_Custom_Integration {
    public function init() {
        if (class_exists('FFC_Utils')) {
            $ip = FFC_Utils::get_user_ip();
            // ... your code
        }
    }
}
```

**After (recommended):**
```php
use FreeFormCertificate\Core\Utils;

class My_Custom_Integration {
    public function init() {
        if (class_exists('\FreeFormCertificate\Core\Utils')) {
            $ip = Utils::get_user_ip();
            // ... your code
        }
    }
}
```

**Or (using alias - backward compatible):**
```php
class My_Custom_Integration {
    public function init() {
        // Old way still works via alias
        if (class_exists('FFC_Utils')) {
            $ip = FFC_Utils::get_user_ip();
            // ... your code
        }
    }
}
```

---

## 🔄 Backward Compatibility

### How Aliases Work

Free Form Certificate maintains 100% backward compatibility using `class_alias()`:

```php
// Defined in includes/class-ffc-aliases.php
class_alias('FreeFormCertificate\Core\Utils', 'FFC_Utils');

// Result: Both work identically
FFC_Utils::get_user_ip();                        // ✅ Old way (via alias)
FreeFormCertificate\Core\Utils::get_user_ip();   // ✅ New way
Utils::get_user_ip();                            // ✅ With use statement
```

### Compatibility Timeline

- **v3.2.0 - v3.9.x:** Both old and new class names work
- **v4.0.0:** Old class names removed (breaking change)

### Deprecation Warnings

Starting in v3.7.0, using old class names will trigger deprecation notices:

```php
// Will show: Deprecated: FFC_Utils is deprecated, use FreeFormCertificate\Core\Utils
$ip = FFC_Utils::get_user_ip();
```

---

## 📦 Using Namespaced Classes in Your Code

### In Theme functions.php

```php
<?php
// At the top of functions.php
use FreeFormCertificate\Core\Utils;
use FreeFormCertificate\Repositories\FormRepository;

// Now use the classes
add_action('init', function() {
    $ip = Utils::get_user_ip();
    $repo = new FormRepository();
});
```

### In Custom Plugins

```php
<?php
/**
 * Plugin Name: My FFC Extension
 */

// Import the classes you need
use FreeFormCertificate\Core\Utils;
use FreeFormCertificate\Integrations\EmailHandler;

class My_FFC_Extension {
    public function __construct() {
        add_filter('ffc_email_content', [$this, 'customize_email']);
    }

    public function customize_email($content) {
        $ip = Utils::get_user_ip();
        return $content . "\n\nSent from IP: " . $ip;
    }
}

new My_FFC_Extension();
```

### In Shortcodes

```php
<?php
use FreeFormCertificate\Repositories\SubmissionRepository;
use FreeFormCertificate\Core\Utils;

add_shortcode('my_certificates', function($atts) {
    $repo = new SubmissionRepository();
    $user_id = get_current_user_id();

    if (!$user_id) {
        return 'Please log in to view your certificates.';
    }

    $submissions = $repo->get_by_user_id($user_id);
    $table = Utils::get_submissions_table();

    // Build output...
    return $output;
});
```

---

## 🧪 Testing Your Migration

### Checklist

- [ ] Replace `FFC_*` class names with namespaced versions
- [ ] Add `use` statements at top of files
- [ ] Test that your code still works
- [ ] Check for PHP errors in debug.log
- [ ] Verify all functionality works as expected

### Common Errors

#### Error: "Class 'Utils' not found"

**Problem:** Missing `use` statement

**Solution:**
```php
// Add this at the top of your file
use FreeFormCertificate\Core\Utils;
```

#### Error: "Class 'FreeFormCertificate\Core\Utils' not found"

**Problem:** Form for Certificates plugin not activated or old version

**Solution:**
- Ensure Form for Certificates v3.2.0+ is activated
- Check that autoloader is registered in `wp-ffcertificate.php`

#### Error: Using old class name in v4.0.0+

**Problem:** Old class names removed in v4.0.0

**Solution:**
- Update to namespaced class names
- Add proper `use` statements
- See migration mapping table above

---

## 🚀 Best Practices

### 1. Always Use `use` Statements

**Good:**
```php
use FreeFormCertificate\Core\Utils;

$ip = Utils::get_user_ip();
```

**Avoid:**
```php
// Fully qualified names are verbose
$ip = \FreeFormCertificate\Core\Utils::get_user_ip();
```

### 2. Check Class Existence

```php
if (class_exists('\FreeFormCertificate\Core\Utils')) {
    // Safe to use
}
```

### 3. Type Hints

```php
use FreeFormCertificate\Repositories\FormRepository;

function process_form(FormRepository $repo, int $form_id) {
    return $repo->get_by_id($form_id);
}
```

### 4. IDE Autocomplete

With namespaces, IDEs provide better:
- Autocomplete suggestions
- Go-to-definition
- Refactoring tools
- PHPDoc integration

---

## 📚 Additional Resources

- [PSR-4 Autoloading Standard](https://www.php-fig.org/psr/psr-4/)
- [PHP Namespaces Documentation](https://www.php.net/manual/en/language.namespaces.php)
- [NAMESPACE-MIGRATION.md](./NAMESPACE-MIGRATION.md) - Full migration plan
- [PHASE-2-COMPLETE.md](./PHASE-2-COMPLETE.md) - Complete migration report

---

## ❓ FAQ

### Q: Do I need to update my code immediately?

**A:** No. Old class names will work until v4.0.0. However, it's recommended to migrate gradually.

### Q: Will this break my custom plugins?

**A:** No. All old class names are aliased to new names. Your code will work without changes.

### Q: When should I migrate?

**A:** Migrate when:
- Creating new code
- Performing major refactoring
- Preparing for v4.0.0

### Q: How do I find all FFC_ references in my code?

**A:** Use grep or IDE search:
```bash
grep -r "FFC_" /path/to/your/code
```

### Q: Can I mix old and new class names?

**A:** Yes, but not recommended. Choose one style for consistency.

---

## 💬 Support

For questions or issues:
1. Review this guide
2. Check [NAMESPACE-MIGRATION.md](./NAMESPACE-MIGRATION.md)
3. Search existing GitHub issues
4. Create a new issue with details

---

**Last Updated:** 2026-01-26 (v3.2.0)

**Migration Status:** Phase 2 Complete ✅
