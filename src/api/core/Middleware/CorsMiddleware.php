<?php
namespace App\Core\Middleware;

class CorsMiddleware extends BaseMiddleware {
    
    public function before() {
        // Handle preflight requests
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        return null;
    }
    
    public function after($response = null) {
        // CORS headers already set by SecurityUtility
        // You can add additional CORS logic here if needed
        return $response;
    }
}
