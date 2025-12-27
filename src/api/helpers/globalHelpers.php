<?php

/**
 * Global helper functions that should be available everywhere
 * These functions are auto-loaded via composer.json
 */

/**
 * Smart debug print function - only plain text output
 */
function pp($ke)
{
    if (!empty($_ENV['DEBUG_MODE']) && ($_ENV['DEBUG_MODE'] === 'true' || $_ENV['DEBUG_MODE'] === '1')) {

        $backtrace = debug_backtrace()[0];
        $file = $backtrace['file'];
        $line = $backtrace['line'];

        // Always output as plain text (no HTML)
        echo "\n\n--- pp ---\n";
        echo "File: " . $file . " | ";
        echo "Line: " . $line . "\n-------------------------------\n";
        print_r($ke);
        echo "\n------------------------------\n--- /pp ---\n\n";
    }
}

/**
 * Smart debug var_dump function - only plain text output
 */
function ppp($ke)
{
    if (!empty($_ENV['DEBUG_MODE']) && ($_ENV['DEBUG_MODE'] === 'true' || $_ENV['DEBUG_MODE'] === '1')) {

        $backtrace = debug_backtrace()[0];
        $file = $backtrace['file'];
        $line = $backtrace['line'];

        // Always output as plain text (no HTML)
        echo "\n\n--- ppp ---\n";
        echo "File: " . $file . " | ";
        echo "Line: " . $line . "\n-------------------------------\n";
        var_dump($ke);
        echo "\n------------------------------\n--- /ppp ---\n\n";
    }
}

/**
 * Send a standardized JSON response and exit.
 */
function sendJsonResponse(
    int $statusCode,
    string $status,
    string $message,
    $data = null,
    array $extra = []
): void {
    http_response_code($statusCode);

    $response = [
        'status' => $status,
        'message' => $message
    ];

    if (!is_null($data)) {
        $response['data'] = $data;
    }

    if (!is_null($extra) && is_array($extra)) {
        $response = array_merge($response, $extra);
    }

    // Set JSON content type BEFORE any debug output
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    // exit;
}
