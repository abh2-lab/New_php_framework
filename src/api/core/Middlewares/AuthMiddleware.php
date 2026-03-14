<?php

namespace App\Core\Middlewares;

use App\Core\Middlewares\BaseMiddleware;
use App\Services\JWTService;

class AuthMiddleware extends BaseMiddleware
{
    private JWTService $jwtService;
    private bool $bypassAuth = true; // true = Development Mode

    public function __construct()
    {
        parent::__construct();
        $this->jwtService = new JWTService();
    }

    public function before()
    {
        // 1. BYPASS LOGIC (DEV MODE)
        if ($this->bypassAuth) {
            $_REQUEST['user'] = [
                'sub'      => 1, // 'sub' typically represents user_id in JWTs
                'role'     => 'user',
                'role_id'  => 1,
                'name'     => 'Dev User'
            ];
            return null; 
        }

        // 2. GET TOKEN (Cookie First, Fallback to Header)
        $token = $_COOKIE['access_token'] ?? null;

        // If no cookie exists, check the Authorization header (useful for Postman)
        if (!$token) {
            $headers = $this->getAuthorizationHeader();

            if (!$headers) {
                $this->halt('Authentication required. Cookie or Authorization header missing.', 401);
            }

            if (!preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                $this->halt('Invalid Authorization header format. Expected Bearer token.', 401);
            }

            $token = $matches[1];
        }
        
        // 3. VALIDATE TOKEN
        $payload = $this->jwtService->validate($token);
        if (!$payload) {
            // Important: If cookie expired, frontend should intercept 401 and call /auth/refresh
            $this->halt('Invalid or expired token.', 401);
        }

        // 4. INJECT USER DATA (No role check needed, just being logged in is enough)
        $_REQUEST['user'] = (array) $payload;
        
        return null;
    }

    private function getAuthorizationHeader(): ?string
    {
        if (isset($_SERVER['Authorization'])) return trim($_SERVER['Authorization']);
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) return trim($_SERVER['HTTP_AUTHORIZATION']);
        
        if (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            if (isset($requestHeaders['Authorization'])) return trim($requestHeaders['Authorization']);
        }
        
        return null;
    }
}
