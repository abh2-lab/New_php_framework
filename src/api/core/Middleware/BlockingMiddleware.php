<?php
namespace App\Core\Middleware;

class BlockingMiddleware extends BaseMiddleware {
    
    public function before() {
        error_log("🛑 BlockingMiddleware::before() - BLOCKING REQUEST");
        
        // This should halt execution
        $this->halt('Access denied by BlockingMiddleware', 403);
        
        return null; // This won't be reached
    }
    
    public function after($response = null) {
        error_log("❌ This should NOT execute if blocked");
        return $response;
    }
}
