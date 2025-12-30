<?php
namespace App\Core\Middleware;

class AdminMiddleware extends BaseMiddleware {
    
    public function before() {
        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if user is logged in
        if (empty($_SESSION['admin_id'])) {
            $this->halt('Admin authentication required', 401);
        }
        
        // Check if user has admin role
        if (($_SESSION['role'] ?? '') !== 'admin') {
            $this->halt('Admin access required', 403);
        }
        
        return null;
    }
    
    // ADD = null here
    public function after($response = null) {
        // Log admin actions
        error_log("Admin action: {$_SERVER['REQUEST_METHOD']} {$_SERVER['REQUEST_URI']} by user {$_SESSION['admin_id']}");
        return $response;
    }
}
