<?php
declare(strict_types=1);

/**
 * PSR-4 Autoloader for Free Form Certificate
 *
 * Maps the namespace FreeFormCertificate\* to includes/* directory structure
 * while maintaining backward compatibility with old class names.
 *
 * @since 3.2.0
 * @package FreeFormCertificate
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFC_Autoloader {

    /**
     * Base namespace
     *
     * @var string
     */
    private const NAMESPACE_PREFIX = 'FreeFormCertificate\\';

    /**
     * Base directory for the namespace
     *
     * @var string
     */
    private string $base_dir;

    /**
     * Namespace to directory mappings
     *
     * @var array<string, string>
     */
    private array $namespace_map;

    /**
     * Constructor
     *
     * @param string $base_dir Base directory for includes
     */
    public function __construct(string $base_dir) {
        $this->base_dir = rtrim($base_dir, '/') . '/';
        $this->namespace_map = $this->get_namespace_map();
    }

    /**
     * Register the autoloader
     *
     * @return void
     */
    public function register(): void {
        spl_autoload_register([$this, 'autoload']);
    }

    /**
     * Autoload a class
     *
     * @param string $class Full class name with namespace
     * @return void
     */
    public function autoload(string $class): void {
        // Check if class uses our namespace
        if (strpos($class, self::NAMESPACE_PREFIX) !== 0) {
            return;
        }

        // Remove namespace prefix
        $relative_class = substr($class, strlen(self::NAMESPACE_PREFIX));

        // Try to find the file using namespace mappings
        $file = $this->find_class_file($relative_class);

        if ($file && file_exists($file)) {
            require_once $file;
        }
    }

    /**
     * Find the file for a given class
     *
     * @param string $relative_class Class name relative to base namespace
     * @return string|null File path or null if not found
     */
    private function find_class_file(string $relative_class): ?string {
        // Split namespace parts
        $parts = explode('\\', $relative_class);
        $class_name = array_pop($parts);
        $namespace_path = implode('\\', $parts);

        // Check if we have a specific mapping for this namespace
        foreach ($this->namespace_map as $namespace => $dir) {
            if ($namespace_path === $namespace || strpos($namespace_path, $namespace . '\\') === 0) {
                // Calculate the subdirectory
                $sub_namespace = substr($namespace_path, strlen($namespace));
                $sub_dir = str_replace('\\', '/', ltrim($sub_namespace, '\\'));

                // Build the file path
                $file_path = $this->base_dir . $dir . ($sub_dir ? '/' . strtolower($sub_dir) : '');

                // Try multiple file naming conventions
                $possible_files = $this->get_possible_filenames($class_name, $namespace_path);

                foreach ($possible_files as $filename) {
                    $full_path = $file_path . '/' . $filename;
                    if (file_exists($full_path)) {
                        return $full_path;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get possible filenames for a class
     *
     * Supports WordPress conventions: class-ffc-name.php and class-name.php
     *
     * @param string $class_name Class name
     * @param string $namespace_path Namespace path for context-specific naming
     * @return array<string> Possible filenames
     */
    private function get_possible_filenames(string $class_name, string $namespace_path = ''): array {
        $filenames = [];

        // Convert camelCase or PascalCase to kebab-case
        $kebab = $this->to_kebab_case($class_name);

        // WordPress style: class-ffc-name.php
        $filenames[] = "class-ffc-{$kebab}.php";

        // For SelfScheduling namespace, also try with self-scheduling- prefix
        // This handles files like class-ffc-self-scheduling-appointment-handler.php
        // for classes like AppointmentHandler in the SelfScheduling namespace
        if ($namespace_path === 'SelfScheduling') {
            $filenames[] = "class-ffc-self-scheduling-{$kebab}.php";
        }

        // Alternative: class-name.php (for repositories and some files)
        $filenames[] = "ffc-{$kebab}.php";

        // PSR-4 style: ClassName.php (for future)
        $filenames[] = "{$class_name}.php";

        // Interface style: interface-ffc-name.php
        if (strpos($class_name, 'Interface') !== false || strpos($class_name, 'Strategy') !== false) {
            $filenames[] = "interface-ffc-{$kebab}.php";
        }

        // Abstract class style
        if (strpos($class_name, 'Abstract') !== false) {
            $filenames[] = "abstract-ffc-{$kebab}.php";
        }

        return $filenames;
    }

    /**
     * Convert string to kebab-case
     *
     * @param string $string Input string
     * @return string Kebab-case string
     */
    private function to_kebab_case(string $string): string {
        // Handle known acronyms first - replace them with hyphen + lowercase version
        // This ensures proper word boundary separation
        $acronyms = ['CPT', 'CSV', 'API', 'PDF', 'HTML', 'REST', 'AJAX', 'URL', 'ID'];

        foreach ($acronyms as $acronym) {
            // Replace acronym with hyphen-separated lowercase version
            // The leading hyphen ensures word boundary, preg_replace will clean up doubles
            $string = str_replace($acronym, '-' . strtolower($acronym), $string);
        }

        // Insert hyphens before capital letters and convert to lowercase
        $kebab = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $string);
        $kebab = strtolower($kebab ?? '');

        // Clean up multiple hyphens and leading/trailing hyphens
        $kebab = preg_replace('/-+/', '-', $kebab) ?? $kebab;

        return trim($kebab, '-');
    }

    /**
     * Get namespace to directory mappings
     *
     * Maps each sub-namespace to its corresponding directory in includes/
     *
     * @return array<string, string>
     */
    private function get_namespace_map(): array {
        return [
            // Root level classes
            '' => '',

            // Admin namespace
            'Admin' => 'admin',

            // API namespace
            'API' => 'api',

            // Core namespace
            'Core' => 'core',

            // Frontend namespace
            'Frontend' => 'frontend',

            // Generators namespace
            'Generators' => 'generators',

            // Integrations namespace
            'Integrations' => 'integrations',

            // Migrations namespace
            'Migrations' => 'migrations',
            'Migrations\\Strategies' => 'migrations/strategies',

            // Repositories namespace
            'Repositories' => 'repositories',

            // Security namespace
            'Security' => 'security',

            // Settings namespace
            'Settings' => 'settings',
            'Settings\\Tabs' => 'settings/tabs',
            'Settings\\Views' => 'settings/views',

            // Shortcodes namespace
            'Shortcodes' => 'shortcodes',

            // Submissions namespace
            'Submissions' => 'submissions',

            // User Dashboard namespace
            'UserDashboard' => 'user-dashboard',

            // Scheduling namespace (v4.6.0) - Shared scheduling services
            'Scheduling' => 'scheduling',

            // Self-Scheduling namespace (v4.5.0) - User self-booking system
            'SelfScheduling' => 'self-scheduling',

            // Audience namespace (v4.5.0) - New audience booking system
            'Audience' => 'audience',

            // Privacy namespace (v4.9.5) - LGPD/GDPR Privacy Tools integration
            'Privacy' => 'privacy',

            // Services namespace (v4.9.7) - Centralized service classes
            'Services' => 'services',

            // Reregistration namespace (v4.11.0) - Custom fields & reregistration
            'Reregistration' => 'reregistration',

            // URL Shortener namespace (v5.1.0) - Short URLs + QR Codes
            'UrlShortener' => 'url-shortener',
        ];
    }

    /**
     * Get all registered namespaces
     *
     * @return array<string>
     */
    public function get_namespaces(): array {
        return array_keys($this->namespace_map);
    }

    /**
     * Debug: Get mapping for a class
     *
     * @param string $class Class name with namespace
     * @return array<string, mixed> Debug information
     */
    public function debug_class_mapping(string $class): array {
        if (strpos($class, self::NAMESPACE_PREFIX) !== 0) {
            return [
                'error' => 'Class does not use FreeFormCertificate namespace',
                'class' => $class,
            ];
        }

        $relative_class = substr($class, strlen(self::NAMESPACE_PREFIX));
        $file = $this->find_class_file($relative_class);

        return [
            'class' => $class,
            'relative_class' => $relative_class,
            'file' => $file,
            'exists' => $file ? file_exists($file) : false,
        ];
    }
}
