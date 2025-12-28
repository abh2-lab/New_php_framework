<?php
namespace App\Core\Middleware;

class LogMiddleware extends BaseMiddleware {
    private $startTime;
    
    public function before() {
        $this->startTime = microtime(true);
        
        error_log(sprintf(
            "[REQUEST] %s %s | IP: %s",
            $this->request['REQUEST_METHOD'] ?? 'UNKNOWN',
            $this->request['REQUEST_URI'] ?? '/',
            $this->request['REMOTE_ADDR'] ?? 'unknown'
        ));
        
        return null;
    }
    
    public function after($response = null) {
        $duration = round((microtime(true) - $this->startTime) * 1000, 2);
        
        error_log(sprintf(
            "[RESPONSE] %s %s | Duration: %sms",
            $this->request['REQUEST_METHOD'] ?? 'UNKNOWN',
            $this->request['REQUEST_URI'] ?? '/',
            $duration
        ));
        
        return $response;
    }
}
