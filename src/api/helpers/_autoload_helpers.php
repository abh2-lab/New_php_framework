<?php
/**
 * Automatically loads all PHP helper files in the helpers directory
 * This file is loaded via composer.json autoload.files
 */

$helperFiles = glob(__DIR__ . '/*.php');

if ($helperFiles === false) {
    throw new RuntimeException("Failed to glob for helper files in " . __DIR__);
}

foreach ($helperFiles as $file) {
    // Skip the loader file itself to prevent recursion
    if (basename($file) === '_autoload_helpers.php') {
        continue;
    }
    require_once $file;
}

// Clean up variables from global scope
unset($helperFiles, $file);

