<?php




/**
 * Determine HTTP status code based on error message patterns
 * 
 * Maps common error message patterns to appropriate HTTP status codes.
 * Used for consistent error handling across controllers.
 * 
 * @param string $message Error message to analyze
 * @param array $customPatterns Optional custom pattern-to-status-code mappings
 * @return int HTTP status code (default: 500)
 * 
 * @example
 * $statusCode = getErrorStatusCode('Admin user not found');
 * // Returns: 404
 * 
 * @example
 * $statusCode = getErrorStatusCode('Username already exists');
 * // Returns: 422
 * 
 * @example
 * $statusCode = getErrorStatusCode('Cannot delete system admin');
 * // Returns: 403
 */
function getErrorStatusCode($message, $customPatterns = [])
{
    // Default pattern mappings
    $defaultPatterns = [
        'not found' => 404,
        'does not exist' => 404,
        'invalid' => 404,

        'already exists' => 422,
        'No valid fields' => 422,
        'No valid project' => 422,
        'No projects were assigned' => 422,
        'Invalid role' => 422,
        'Invalid format' => 422,
        'required' => 422,
        'must be provided' => 422,
        'validation failed' => 422,

        'Cannot delete' => 403,
        'Cannot assign' => 403,
        'Cannot update' => 403,
        'Cannot create' => 403,
        'Cannot' => 403,
        'forbidden' => 403,
        'insufficient permissions' => 403,
        'unauthorized' => 401,
    ];

    // Merge custom patterns (custom patterns override defaults)
    $patterns = array_merge($defaultPatterns, $customPatterns);

    // Check message against patterns (case-insensitive)
    foreach ($patterns as $pattern => $statusCode) {
        if (stripos($message, $pattern) !== false) {
            return $statusCode;
        }
    }

    // Default to 500 Internal Server Error
    return 500;
}








/**
 * Get project data from JSON string by project ID
 *
 * @param string $jsonString JSON data string
 * @param int|string $projectId Project ID to extract
 * @return array|null Returns the matched data as an array, or null if not found
 */
function getProjectDataById(string $jsonString, $projectId): ?array
{
    $decoded = json_decode($jsonString, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return null; // Invalid JSON
    }

    // Ensure project ID is a string key (JSON keys are always strings)
    $projectId = (string) $projectId;

    // Return data if found
    return $decoded[$projectId] ?? null;
}







