#!/usr/bin/env php
<?php
/**
 * Test ALL classes can be autoloaded
 *
 * This script validates that every class in the codebase can be properly
 * autoloaded without errors.
 *
 * Run: php test-all-classes.php
 */

// Define required constants
define('ABSPATH', __DIR__ . '/');
define('FFC_PLUGIN_DIR', __DIR__ . '/');
define('FFC_VERSION', '4.0.0');
define('FFC_DEBUG', false);

// Load autoloader
require_once __DIR__ . '/includes/class-ffc-autoloader.php';

// Create and register autoloader
$autoloader = new FFC_Autoloader(__DIR__ . '/includes');
$autoloader->register();

echo "=================================================================\n";
echo "Free Form Certificate - Class Autoload Test (v4.0.0)\n";
echo "=================================================================\n\n";

// Get all classes from Composer classmap
$composerClasses = require __DIR__ . '/vendor/composer/autoload_classmap.php';

$totalClasses = 0;
$successClasses = 0;
$failedClasses = [];
$skippedClasses = [];

foreach ($composerClasses as $className => $filePath) {
    // Only test FreeFormCertificate namespace classes
    if (strpos($className, 'FreeFormCertificate') !== 0) {
        continue;
    }

    $totalClasses++;

    // Try to load the class
    try {
        if (class_exists($className) || interface_exists($className)) {
            $successClasses++;
            echo "✓ {$className}\n";
        } else {
            $failedClasses[] = [
                'class' => $className,
                'file' => $filePath,
                'error' => 'Class/Interface not found after autoload'
            ];
            echo "✗ {$className} - NOT FOUND\n";
        }
    } catch (\Throwable $e) {
        $failedClasses[] = [
            'class' => $className,
            'file' => $filePath,
            'error' => $e->getMessage()
        ];
        echo "✗ {$className} - ERROR: {$e->getMessage()}\n";
    }
}

echo "\n=================================================================\n";
echo "Test Results\n";
echo "=================================================================\n\n";

echo "Total Classes Tested: {$totalClasses}\n";
echo "Successfully Loaded:  {$successClasses}\n";
echo "Failed:               " . count($failedClasses) . "\n";

if (count($failedClasses) > 0) {
    echo "\n❌ FAILED CLASSES:\n\n";
    foreach ($failedClasses as $failed) {
        echo "Class:  {$failed['class']}\n";
        echo "File:   {$failed['file']}\n";
        echo "Error:  {$failed['error']}\n";
        echo "---\n";
    }
    echo "\n";
    exit(1);
} else {
    echo "\n✅ ALL CLASSES LOADED SUCCESSFULLY!\n\n";
    exit(0);
}
