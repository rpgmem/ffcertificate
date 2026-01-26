# Free Form Certificate - Tests

This directory contains automated tests for the Free Form Certificate plugin.

## Requirements

- PHP 7.4 or higher
- Composer
- PHPUnit 9.6+

## Installation

Install test dependencies using Composer:

```bash
composer install --dev
```

## Running Tests

### Run all tests

```bash
composer test
# or
./vendor/bin/phpunit
```

### Run specific test suite

```bash
# Unit tests only
./vendor/bin/phpunit --testsuite "Unit Tests"

# Integration tests only
./vendor/bin/phpunit --testsuite "Integration Tests"
```

### Run specific test file

```bash
./vendor/bin/phpunit tests/Unit/UtilsTest.php
```

### Run with code coverage

```bash
composer test:coverage
# or
./vendor/bin/phpunit --coverage-html coverage
```

After running, open `coverage/index.html` in your browser to view the coverage report.

## Test Structure

```
tests/
├── bootstrap.php           # Test environment initialization
├── Mocks/                  # WordPress function mocks
│   └── wordpress-functions.php
├── Unit/                   # Unit tests
│   ├── AutoloaderTest.php
│   └── UtilsTest.php
└── Integration/            # Integration tests (future)
```

## Writing Tests

### Unit Tests

Unit tests should test individual methods in isolation. Place them in `tests/Unit/`.

Example:

```php
<?php
namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Utils;

class UtilsTest extends TestCase {
    public function test_validate_cpf_with_valid_cpf() {
        $this->assertTrue(Utils::validate_cpf('12345678909'));
    }
}
```

### Integration Tests

Integration tests should test how multiple components work together. Place them in `tests/Integration/`.

## Test Coverage Goals

- **Core classes**: 80%+ coverage
- **Admin classes**: 70%+ coverage
- **Frontend classes**: 70%+ coverage
- **Overall**: 75%+ coverage

## Continuous Integration

Tests are automatically run on every push via GitHub Actions. See `.github/workflows/tests.yml`.

## Mocked WordPress Functions

Common WordPress functions are mocked in `tests/Mocks/wordpress-functions.php`:

- `__()`, `_e()`, `_x()` - Translation functions
- `esc_html()`, `esc_attr()`, `esc_url()` - Escaping functions
- `sanitize_text_field()`, `wp_kses_post()` - Sanitization functions
- `add_action()`, `add_filter()`, `do_action()`, `apply_filters()` - Hooks
- `get_option()`, `update_option()`, `delete_option()` - Options
- `is_admin()`, `current_user_can()`, `get_current_user_id()` - User functions
- `wp_verify_nonce()`, `wp_create_nonce()` - Security functions

## Troubleshooting

### Tests fail with "Class not found"

Make sure Composer autoloader is up to date:

```bash
composer dump-autoload
```

### Tests fail with "WordPress functions not defined"

WordPress functions are mocked in `tests/Mocks/wordpress-functions.php`. If you need additional functions, add them there.

### Coverage report not generating

Install Xdebug or PCOV:

```bash
# Ubuntu/Debian
sudo apt-get install php-xdebug

# macOS (via Homebrew)
pecl install xdebug
```

## Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [WordPress Plugin Unit Tests](https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/)
- [PSR-4 Autoloading](https://www.php-fig.org/psr/psr-4/)
