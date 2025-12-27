<?php
namespace App\Core\Middleware;

abstract class BaseMiddleware implements MiddlewareInterface {
    protected $request;
    protected $session;
    
    public function __construct() {
        $this->request = $_SERVER;
        $this->session = $_SESSION ?? [];
    }
    
    /**
     * Override this to run logic before controller
     */
    public function before() {
        return null; // Continue by default
    }
    
    /**
     * Override this to run logic after controller
     */
    public function after($response = null) {
        return $response; // Pass through by default
    }
    
    /**
     * Helper to halt execution with JSON response
     */
    protected function halt(string $message, int $statusCode = 403, $data = null) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }
}
