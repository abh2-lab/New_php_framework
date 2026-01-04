<?php
namespace App\Controllers;

use App\Core\BaseController;

class TestController extends BaseController {


    public function welcome() {
        $this->sendSuccess('praise the LORD');
    }
    
    public function testRoute() {
        error_log("🎯 TestController::testRoute() executed");
        
        $this->sendSuccess('Test route executed successfully', [
            'message' => 'If you see this, the middleware did NOT block the request',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function groupedRoute() {
        error_log("🎯 TestController::groupedRoute() executed");
        
        $this->sendSuccess('Grouped route executed', [
            'message' => 'This route is inside a group with middleware',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function nestedRoute() {
        error_log("🎯 TestController::nestedRoute() executed");
        
        $this->sendSuccess('Nested group route executed', [
            'message' => 'This route is in a nested group - should have multiple middleware',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function blockedRoute() {
        error_log("❌ This should NEVER execute if BlockingMiddleware works");
        
        $this->sendSuccess('This should NOT be visible', [
            'error' => 'Middleware failed to block this request!'
        ]);
    }
}
