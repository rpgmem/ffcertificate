# Namespace Migration Guide (PSR-4)

## Overview

This document describes the phased migration to PSR-4 namespaces in the Free Form Certificate plugin.

**Goal:** Migrate from global class names (`FFC_*`) to namespaced classes (`FreeFormCertificate\*`) without breaking existing functionality.

## Migration Phases

### ✅ Phase 1: Setup Infrastructure (COMPLETED)

**Status:** ✅ DONE

**What was done:**
1. Created PSR-4 autoloader (`class-ffc-autoloader.php`)
2. Created backward compatibility aliases (`class-ffc-aliases.php`)
3. Integrated autoloader in main plugin file
4. Prepared namespace mapping structure

**Files created:**
- `includes/class-ffc-autoloader.php` - PSR-4 autoloader
- `includes/class-ffc-aliases.php` - Class alias registration
- Updated `wp-ffcertificate.php` - Integrated autoloader

**Namespace Structure:**
```
FreeFormCertificate\
├── Admin\           → includes/admin/
├── API\             → includes/api/
├── Core\            → includes/core/
├── Frontend\        → includes/frontend/
├── Generators\      → includes/generators/
├── Integrations\    → includes/integrations/
├── Migrations\      → includes/migrations/
│   └── Strategies\  → includes/migrations/strategies/
├── Repositories\    → includes/repositories/
├── Security\        → includes/security/
├── Settings\        → includes/settings/
│   ├── Tabs\        → includes/settings/tabs/
│   └── Views\       → includes/settings/views/
├── Shortcodes\      → includes/shortcodes/
├── Submissions\     → includes/submissions/
└── UserDashboard\   → includes/user-dashboard/
```

**Backward Compatibility:**
All old class names will continue to work via `class_alias()`:
- `FFC_Utils` → `FreeFormCertificate\Core\Utils`
- `FFC_Admin` → `FreeFormCertificate\Admin\Admin`
- etc.

**No Breaking Changes:** Everything continues to work exactly as before.

---

### ✅ Phase 2: Migrate Internal Code (COMPLETED)

**Status:** ✅ DONE

**Goal:** Add namespaces to existing classes, starting with isolated components.

**What was done:**
All internal classes migrated to namespaces in 15 commits:

1. **Repositories** (3 files) - ✅ DONE
2. **Core** (5 files) - ✅ DONE
3. **Submissions** (2 files) - ✅ DONE
4. **Frontend** (4 files) - ✅ DONE
5. **Migration Strategies** (6 files) - ✅ DONE
6. **API** (1 file) - ✅ DONE
7. **Shortcodes** (1 file) - ✅ DONE
8. **Integrations** (2 files) - ✅ DONE
9. **UserDashboard** (2 files) - ✅ DONE
10. **Generators** (3 files) - ✅ DONE
11. **Security** (3 files) - ✅ DONE
12. **Root** (3 files) - ✅ DONE
13. **Migrations** (5 files) - ✅ DONE
14. **Settings** (9 files) - ✅ DONE
15. **Admin** (15 files) - ✅ DONE

**Total:** ~60 classes migrated to namespaces PSR-4

**Key Changes:**
- All `require_once` statements removed (autoloader handles loading)
- All classes now have namespace declarations
- Updated references to use global namespace prefix `\` for aliases
- All PHP syntax validated
- 100% backward compatibility maintained via class_alias()

**Commits:** 15 granular commits (one per group) on branch `claude/fix-migration-cleanup-xlJ4P`

See `docs/PHASE-2-COMPLETE.md` for detailed migration report.

**Migration Steps for Each Class:**

1. Add namespace declaration at top of file:
   ```php
   namespace FreeFormCertificate\Admin;
   ```

2. Add `use` statements for dependencies:
   ```php
   use FreeFormCertificate\Core\Utils;
   use FreeFormCertificate\Repositories\SubmissionRepository;
   ```

3. Update class name (remove `FFC_` prefix):
   ```php
   class Admin {  // was: class FFC_Admin
   ```

4. Update internal references to use new namespaced classes

5. Test that old class name still works via alias

**Testing Checklist per Class:**
- [ ] Class loads via autoloader
- [ ] Old class name works via alias
- [ ] No fatal errors on plugin activation
- [ ] Admin interface loads correctly
- [ ] Frontend forms work
- [ ] Submissions save correctly

---

### 🔄 Phase 3: Update Documentation (IN PROGRESS)

**Status:** 🔄 IN PROGRESS

**Goal:** Update all documentation to use new namespaced class names.

**Files to update:**
- `readme.txt` - Update architecture diagrams
- `docs/` - All documentation files
- Inline code comments
- PHPDoc blocks

**What to document:**
- New namespace structure
- How to use new class names
- Import statements (`use`)
- Backward compatibility notes

---

### 🗑️ Phase 4: Remove Aliases (TODO - v4.0.0)

**Status:** 🔜 PENDING (Major version only)

**Goal:** Remove backward compatibility aliases in next major version.

**When:** v4.0.0 (breaking change)

**What to do:**
1. Add deprecation notices to aliases in v3.x releases
2. Update all documentation to discourage old class names
3. Provide migration guide for external developers
4. Remove `class-ffc-aliases.php` in v4.0.0
5. Remove alias registration from `wp-ffcertificate.php`

**Breaking Change Notice:**
```
BREAKING CHANGE (v4.0.0):
Old class names (FFC_*) removed. Use namespaced classes:
- FFC_Utils → FreeFormCertificate\Core\Utils
- FFC_Admin → FreeFormCertificate\Admin\Admin
... (provide complete mapping)
```

---

## Autoloader Details

### How it Works

The PSR-4 autoloader maps namespaces to directory structure:

```php
// When you use:
use FreeFormCertificate\Core\Utils;

// The autoloader looks for:
includes/core/class-ffc-utils.php
```

### File Naming Conventions

The autoloader supports multiple naming conventions:

1. **WordPress style:** `class-ffc-utils.php` (current)
2. **Alternative:** `ffc-utils.php`
3. **PSR-4 style:** `Utils.php` (future)
4. **Interfaces:** `interface-ffc-migration-strategy.php`
5. **Abstract:** `abstract-ffc-repository.php`

### Class Name Conversion

The autoloader converts PascalCase to kebab-case:

- `Utils` → `utils`
- `ActivityLog` → `activity-log`
- `FormEditorSaveHandler` → `form-editor-save-handler`

Then tries: `class-ffc-{name}.php`, `ffc-{name}.php`, `{Name}.php`

---

## Backward Compatibility

### How Aliases Work

```php
// In class-ffc-aliases.php:
class_alias('FreeFormCertificate\Core\Utils', 'FFC_Utils');

// Now both work:
FFC_Utils::get_user_ip();                        // Old way (via alias)
FreeFormCertificate\Core\Utils::get_user_ip();   // New way
```

### Import Statements

In new code, use `use` statements:

```php
<?php
namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\Utils;
use FreeFormCertificate\Submissions\SubmissionHandler;

class Admin {
    public function example() {
        $ip = Utils::get_user_ip();  // No need for full namespace
    }
}
```

---

## Testing Strategy

### Unit Tests (Future)

With namespaces, the code becomes more testable:

```php
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Utils;

class UtilsTest extends TestCase {
    public function test_cpf_validation() {
        $this->assertTrue(Utils::validate_cpf('123.456.789-10'));
    }
}
```

### Integration Testing Checklist

- [ ] Plugin activates without errors
- [ ] Admin dashboard loads
- [ ] Forms render correctly
- [ ] Form submissions work
- [ ] Certificate generation works
- [ ] Email sending works
- [ ] Migrations run successfully
- [ ] Settings save correctly
- [ ] CSV export works
- [ ] Verification page works

---

## Benefits of Namespaces

1. **No Name Collisions:** Other plugins can use same class names
2. **Better Organization:** Clear hierarchy (Admin, Core, Frontend)
3. **Autoloading:** No more manual `require_once` statements
4. **Modern PHP:** Follows PSR-4 standard
5. **IDE Support:** Better autocomplete and refactoring
6. **Testability:** Easier to mock dependencies

---

## Common Issues & Solutions

### Issue: Class not found

**Symptom:** `Fatal error: Class 'FreeFormCertificate\Core\Utils' not found`

**Solutions:**
1. Check file naming: must be `class-ffc-utils.php` or `ffc-utils.php`
2. Check namespace in file: `namespace FreeFormCertificate\Core;`
3. Check autoloader registration in main plugin file

### Issue: Alias not working

**Symptom:** `Fatal error: Class 'FFC_Utils' not found`

**Solutions:**
1. Ensure `ffc_register_class_aliases()` is called
2. Check that new namespaced class exists first
3. Verify alias mapping in `class-ffc-aliases.php`

### Issue: Circular dependency

**Symptom:** Classes can't be loaded due to circular references

**Solutions:**
1. Use dependency injection instead of direct instantiation
2. Consider using interfaces to break cycles
3. Refactor to remove tight coupling

---

## Developer Guidelines

### When Adding New Classes

1. **Always use namespaces:**
   ```php
   <?php
   namespace FreeFormCertificate\Admin;

   class NewClass {
       // ...
   }
   ```

2. **Add to aliases (Phase 1-3 only):**
   ```php
   'FFC_New_Class' => 'FreeFormCertificate\Admin\NewClass',
   ```

3. **Use proper imports:**
   ```php
   use FreeFormCertificate\Core\Utils;
   ```

4. **Follow PSR-12 coding standards**

### When Modifying Existing Classes

1. **Phase 1-2:** Can use both old and new names
2. **Phase 3:** Prefer new namespaced names in documentation
3. **Phase 4:** Only namespaced names allowed

---

## Rollback Plan

If namespace migration causes issues:

1. **Emergency rollback:**
   - Revert `wp-ffcertificate.php` to previous version
   - Remove autoloader and alias files
   - Classes will load via old `require_once` statements

2. **Selective rollback:**
   - Keep autoloader active
   - Revert specific class to old name
   - Update alias mapping

---

## Timeline

- **v3.2.0:** Phase 1 complete ✅ (Autoloader + Aliases)
- **v3.2.0:** Phase 2 complete ✅ (All classes migrated - 15 commits)
- **v3.2.0:** Phase 3 🔄 IN PROGRESS (Documentation update)
- **v3.7.0+:** Deprecation notices (future)
- **v4.0.0:** Phase 4 - Remove aliases (breaking change)

---

## Appendix: Complete Alias Mapping

See `includes/class-ffc-aliases.php` for the complete list of 60+ class aliases.

---

## Questions?

For questions about the namespace migration:
1. Review this document
2. Check `class-ffc-autoloader.php` for technical details
3. Review `class-ffc-aliases.php` for alias mappings
4. Test in a staging environment first
