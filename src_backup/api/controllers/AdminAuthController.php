<?php
namespace App\Controllers;

use App\Services\AdminAuthService;

class AdminAuthController extends BaseController
{
    private $authService;

    public function __construct()
    {
        parent::__construct();

        $this->authService = new AdminAuthService($this->conn);
    }



    public function welcome()
    {
        $this->sendSuccess('Welcome to the connect.pingnetwork.in backend api 2');
    }






    public function login()
    {
        try {
            // Start secure session with auto-detection
            if (session_status() === PHP_SESSION_NONE) {
                $settings = $this->authService->getCookieSecuritySettings();

                session_set_cookie_params([
                    'lifetime' => 0,
                    'path' => '/',
                    'domain' => $settings['domain'],
                    'secure' => $settings['secure'],
                    'httponly' => true,
                    'samesite' => $settings['samesite']
                ]);

                session_start();
            }

            // Already logged in check
            if (!empty($_SESSION['admin_user_id'])) {
                $this->sendSuccess('Already logged in', [
                    'id' => $_SESSION['admin_user_id'],
                    'username' => $_SESSION['admin_username'],
                    'hash_id' => $_SESSION['admin_hash_id'],
                    'role' => $_SESSION['admin_role']
                ]);
            }

            // Validate input
            $data = $this->getRequestData();

            if (empty($data['username']) || empty($data['password'])) {
                return $this->sendError('Username and password are required', 422);
            }

            $username = trim($data['username']);
            $password = trim($data['password']);
            $rememberMe = !empty($data['remember_me']);

            // Authenticate user
            $userResult = $this->authService->getUserByUsername($username);

            if (!$userResult['success']) {
                return $this->sendError('Invalid username or password', 422);
            }

            $user = $userResult['data'];

            if (!$this->authService->verifyPassword($password, $user['password'])) {
                return $this->sendError('Invalid username or password', 422);
            }

            // Regenerate session ID for security
            session_regenerate_id(true);

            // Create session
            $_SESSION['admin_user_id'] = $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_role'] = $user['role'];
            $_SESSION['admin_hash_id'] = $user['unique_hash'];
            $_SESSION['last_activity'] = time();

            // Handle Remember Me
            if ($rememberMe) {
                $tokenResult = $this->authService->createRememberMeToken($user['id'], 30);

                if ($tokenResult['success']) {
                    $settings = $this->authService->getCookieSecuritySettings();

                    setcookie('remember_me_token', $tokenResult['data']['token'], [
                        'expires' => strtotime($tokenResult['data']['expires_at']),
                        'path' => '/',
                        'domain' => $settings['domain'],
                        'secure' => $settings['secure'],
                        'httponly' => true,
                        'samesite' => $settings['samesite']
                    ]);
                }
            }

            // new change
            $this->sendSuccess('Login successful', [
                'id' => $user['id'],
                'username' => $user['username'],
                'hash_id' => $user['unique_hash'],
                'role' => $user['role']
            ]);

        } catch (\Exception $e) {
            $this->sendError('Server error: ' . $e->getMessage(), 500);
        }
    }





    public function logout()
    {
        try {
            // -------------------------
            // 1️⃣ Start session if not active
            // -------------------------
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            // -------------------------
            // 2️⃣ Check if user is logged in
            // -------------------------
            if (empty($_SESSION['admin_user_id'])) {
                return $this->sendError('No active session found', 422);
            }

            // -------------------------
            // 3️⃣ Call logout service
            // -------------------------
            $result = $this->authService->logout();

            if (!$result['success']) {
                $this->sendError($result['message'], 500);
            }

            // -------------------------
            // 4️⃣ Return success response
            // -------------------------
            $this->sendSuccess('Logout successful', null);

        } catch (\Exception $e) {
            $this->sendError('Server error: ' . $e->getMessage(), 500);
        }
    }



}
