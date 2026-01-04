<?php
namespace App\Core\Middlewares;

class TestMiddleware extends BaseMiddleware {
    
    public function before() {
        // Log to verify before execution
        error_log("✅ TestMiddleware::before() executed");
        
        // Add a custom header to verify it ran
        if (!headers_sent()) {
            header('X-Test-Before: executed');
        }
        
        return null; // Continue to controller
    }
    
    public function after($response = null) {
        // Log to verify after execution
        error_log("✅ TestMiddleware::after() executed");
        
        // Don't try to add headers here - they're already sent
        // Just log or modify the response if needed
        
        return $response;
    }
}
