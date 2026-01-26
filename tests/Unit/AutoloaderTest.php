<?php
/**
 * Tests for Autoloader class
 *
 * @package FreeFormCertificate
 * @subpackage Tests
 */

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @covers \FFC_Autoloader
 */
class AutoloaderTest extends TestCase {

    private $autoloader;
    private $base_dir;

    public function setUp(): void {
        $this->base_dir = FFC_PLUGIN_DIR . 'includes';
        $this->autoloader = new \FFC_Autoloader($this->base_dir);
    }

    /**
     * Test autoloader can be instantiated
     */
    public function test_autoloader_instantiation() {
        $this->assertInstanceOf(\FFC_Autoloader::class, $this->autoloader);
    }

    /**
     * Test autoloader can be registered
     */
    public function test_autoloader_registration() {
        $this->autoloader->register();

        // Check if autoloader is registered
        $autoloaders = spl_autoload_functions();
        $this->assertIsArray($autoloaders);

        $found = false;
        foreach ($autoloaders as $loader) {
            if (is_array($loader) && $loader[0] instanceof \FFC_Autoloader) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Autoloader should be registered');
    }

    /**
     * Test get_namespaces returns array
     */
    public function test_get_namespaces() {
        $namespaces = $this->autoloader->get_namespaces();

        $this->assertIsArray($namespaces);
        $this->assertNotEmpty($namespaces);
        $this->assertContains('Core', $namespaces);
        $this->assertContains('Admin', $namespaces);
        $this->assertContains('Frontend', $namespaces);
    }

    /**
     * Test autoloader loads Core\Utils class
     */
    public function test_autoloader_loads_utils_class() {
        $this->autoloader->register();

        $this->assertTrue(
            class_exists('FreeFormCertificate\Core\Utils'),
            'Utils class should be autoloaded'
        );
    }

    /**
     * Test autoloader loads Admin classes
     */
    public function test_autoloader_loads_admin_classes() {
        $this->autoloader->register();

        $this->assertTrue(
            class_exists('FreeFormCertificate\Admin\Admin'),
            'Admin class should be autoloaded'
        );
    }

    /**
     * Test debug_class_mapping for existing class
     */
    public function test_debug_class_mapping_for_existing_class() {
        $debug = $this->autoloader->debug_class_mapping('FreeFormCertificate\Core\Utils');

        $this->assertIsArray($debug);
        $this->assertArrayHasKey('class', $debug);
        $this->assertArrayHasKey('file', $debug);
        $this->assertArrayHasKey('exists', $debug);
        $this->assertEquals('FreeFormCertificate\Core\Utils', $debug['class']);
        $this->assertTrue($debug['exists']);
    }

    /**
     * Test debug_class_mapping for non-existent class
     */
    public function test_debug_class_mapping_for_non_existent_class() {
        $debug = $this->autoloader->debug_class_mapping('FreeFormCertificate\NonExistent\Class');

        $this->assertIsArray($debug);
        $this->assertArrayHasKey('exists', $debug);
        $this->assertFalse($debug['exists']);
    }

    /**
     * Test debug_class_mapping for class from different namespace
     */
    public function test_debug_class_mapping_for_wrong_namespace() {
        $debug = $this->autoloader->debug_class_mapping('SomeOther\Namespace\Class');

        $this->assertIsArray($debug);
        $this->assertArrayHasKey('error', $debug);
        $this->assertStringContainsString('does not use FreeFormCertificate namespace', $debug['error']);
    }
}
