<?php

namespace App\Core\Middlewares;

use App\Core\Middlewares\BaseMiddleware;
use App\Services\JWTService;

class AdminMiddleware extends BaseMiddleware
{
    private JWTService $jwtService;
    private bool $bypassAuth = false; // true = Development Mode

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
                'sub' => 1,
                'role' => 'admin', // Injected as admin
                'name' => 'Dev Admin'
            ];
            return null;
        }

        // 2. GET HEADER
        $headers = $this->getAuthorizationHeader();
        if (!$headers) {
            $this->halt('Authorization header not found', 401);
        }

        if (!preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
            $this->halt('Token not found in request', 401);
        }

        $token = $matches[1];

        // 3. VALIDATE TOKEN
        $payload = $this->jwtService->validate($token);
        if (!$payload) {
            $this->halt('Invalid or expired token', 401);
        }

        $user = (array) $payload;

        // 4. STRICT ADMIN CHECK (Reject normal users)
        // Check if role is 1 (Admin) or role_name is 'admin' based on your JWT payload structure
        $isAdmin = false;

        if (isset($user['role_id']) && (int) $user['role_id'] === 1) {
            $isAdmin = true;
        } elseif (isset($user['role_name']) && strtolower($user['role_name']) === 'admin') {
            $isAdmin = true;
        } elseif (isset($user['role']) && strtolower($user['role']) === 'admin') {
            $isAdmin = true;
        }

        if (!$isAdmin) {
            $this->halt('Forbidden: Administrative privileges required.', 403);
        }

        // 5. INJECT USER DATA
        $_REQUEST['user'] = $user;

        return null;
    }

    private function getAuthorizationHeader(): ?string
    {
        if (isset($_SERVER['Authorization']))
            return trim($_SERVER['Authorization']);
        if (isset($_SERVER['HTTP_AUTHORIZATION']))
            return trim($_SERVER['HTTP_AUTHORIZATION']);

        if (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            if (isset($requestHeaders['Authorization']))
                return trim($requestHeaders['Authorization']);
        }

        return null;
    }
}
