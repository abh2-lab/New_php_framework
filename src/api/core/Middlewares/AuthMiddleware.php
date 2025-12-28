<?php
namespace App\Core\Middleware;

class AuthMiddleware extends BaseMiddleware {
    
    public function before() {
        // Check for authorization token
        $token = $this->request['HTTP_AUTHORIZATION'] ?? '';
        
        if (empty($token)) {
            $this->halt('Authentication required', 401);
        }
        
        // Add your token validation logic here
        // For example: verify JWT, check session, etc.
        
        return null; // Continue if authenticated
    }
    
    public function after($response = null) {
        // Add authentication headers to response
        if (!headers_sent()) {
            header('X-Authenticated: true');
        }
        return $response;
    }
}
