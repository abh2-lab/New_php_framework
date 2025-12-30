<?php

namespace App\Services;

/**
 * AdminAuthService
 * 
 * Handles admin user authentication operations including login, session management,
 * and remember me token functionality.
 */
class AdminAuthService
{
    private $conn;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    /**
     * Get cookie security settings based on environment
     * FIXED: Ensures secure=true whenever samesite=None
     * 
     * @return array ['secure' => bool, 'samesite' => string]
     */
    public function getCookieSecuritySettings()
    {
        $isProduction = ($_ENV['APP_ENV'] ?? 'development') === 'production';
        $forceSecure = filter_var($_ENV['FORCE_SECURE_COOKIES'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Detect if request is HTTPS
        $isHttps = $this->isHttpsRequest();

        // Detect if this is a cross-origin request
        $isCrossOrigin = $this->isCrossOriginRequest();

        // Decision logic:
        // 1. If cross-origin + HTTPS → SameSite=None, secure=true
        // 2. If same-origin (localhost) → SameSite=Lax, secure=false
        // 3. If production → SameSite=None, secure=true (assuming HTTPS)

        $useSecureCookies = $isProduction || $forceSecure || ($isCrossOrigin && $isHttps);
        $sameSite = ($isCrossOrigin || $isProduction) ? 'None' : 'Lax';

        // Override: If trying to use SameSite=None without HTTPS, fall back to Lax
        if ($sameSite === 'None' && !$isHttps && !$forceSecure) {
            $sameSite = 'Lax';
            $useSecureCookies = false;
        }

        // Cookie domain
        $domain = $_ENV['SET_COOKIE_DOMAIN'] ?? '';

        // For localhost, always use empty domain
        if ($this->isLocalhost()) {
            $domain = '';
        }

        return [
            'secure' => $useSecureCookies,
            'samesite' => $sameSite,
            'domain' => $domain
        ];
    }

    /**
     * Fetch admin user by username
     * 
     * @param string $username Admin username
     * @return array Response with user data or error
     */
    public function getUserByUsername($username)
    {
        try {
            $query = "SELECT id, username, unique_hash, role, password 
                      FROM admin_user 
                      WHERE username = :username 
                      LIMIT 1";

            $result = RunQuery([
                'conn' => $this->conn,
                'query' => $query,
                'params' => [':username' => $username]
            ]);

            if (empty($result)) {
                return [
                    'success' => false,
                    'message' => 'User not found',
                    'data' => null
                ];
            }

            return [
                'success' => true,
                'message' => 'User found',
                'data' => $result[0]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Create or update remember me token for admin user
     * Deletes any existing tokens for the user before creating a new one (single token per user)
     * 
     * @param int $adminId Admin user ID
     * @param int $expiryDays Token expiry in days (default: 30)
     * @return array Response with token or error
     */
    public function createRememberMeToken($adminId, $expiryDays = 30)
    {
        try {
            // 1. Delete any existing tokens for this user (keep only one active token)
            $deleteQuery = "DELETE FROM admin_user_remember_tokens 
                            WHERE admin_id = :admin_id";

            RunQuery([
                'conn' => $this->conn,
                'query' => $deleteQuery,
                'params' => [':admin_id' => $adminId]
            ]);

            // 2. Generate new secure random token
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));

            // 3. Insert new token
            $insertQuery = "INSERT INTO admin_user_remember_tokens 
                            (admin_id, token_hash, expires_at, created_at, ip_address, user_agent)
                            VALUES 
                            (:admin_id, :token_hash, :expires_at, NOW(), :ip, :ua)";

            RunQuery([
                'conn' => $this->conn,
                'query' => $insertQuery,
                'params' => [
                    ':admin_id' => $adminId,
                    ':token_hash' => $tokenHash,
                    ':expires_at' => $expiresAt,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                ],
                'withSuccess' => true
            ]);

            return [
                'success' => true,
                'message' => 'Token created successfully',
                'data' => [
                    'token' => $token,
                    'expires_at' => $expiresAt
                ]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to create token: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Verify password against hashed password
     * 
     * @param string $password Plain text password
     * @param string $hashedPassword Hashed password from database
     * @return bool True if password matches, false otherwise
     */
    public function verifyPassword($password, $hashedPassword)
    {
        return password_verify($password, $hashedPassword);
    }

    /**
     * Validate remember me token and return admin user data
     * 
     * @param string $token Plain remember me token from cookie
     * @return array Response with user data or error
     */
    public function validateRememberMeToken($token)
    {
        try {
            $tokenHash = hash('sha256', $token);

            $query = "SELECT rmt.id, rmt.admin_id, rmt.expires_at, 
                             au.username, au.unique_hash, au.role
                      FROM admin_user_remember_tokens rmt
                      INNER JOIN admin_user au ON rmt.admin_id = au.id
                      WHERE rmt.token_hash = :token_hash 
                      AND rmt.expires_at > NOW()
                      LIMIT 1";

            $result = RunQuery([
                'conn' => $this->conn,
                'query' => $query,
                'params' => [':token_hash' => $tokenHash]
            ]);

            if (empty($result)) {
                return [
                    'success' => false,
                    'message' => 'Invalid or expired token',
                    'data' => null
                ];
            }

            // Update last_used_at timestamp
            $updateQuery = "UPDATE admin_user_remember_tokens 
                            SET last_used_at = NOW() 
                            WHERE id = :id";

            RunQuery([
                'conn' => $this->conn,
                'query' => $updateQuery,
                'params' => [':id' => $result[0]['id']]
            ]);

            return [
                'success' => true,
                'message' => 'Token valid',
                'data' => [
                    'id' => $result[0]['admin_id'],
                    'username' => $result[0]['username'],
                    'unique_hash' => $result[0]['unique_hash'],
                    'role' => $result[0]['role']
                ]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Token validation error: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Delete remember me token for a specific admin user
     * Used during logout
     * 
     * @param int $adminId Admin user ID
     * @return array Response with success or error
     */
    public function deleteRememberMeToken($adminId)
    {
        try {
            $query = "DELETE FROM admin_user_remember_tokens 
                      WHERE admin_id = :admin_id";

            RunQuery([
                'conn' => $this->conn,
                'query' => $query,
                'params' => [':admin_id' => $adminId]
            ]);

            return [
                'success' => true,
                'message' => 'Token deleted successfully',
                'data' => null
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to delete token: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Clean up expired remember me tokens
     * Should be called periodically (e.g., via cron job)
     * 
     * @return array Response with cleanup stats
     */
    public function cleanupExpiredTokens()
    {
        try {
            $query = "DELETE FROM admin_user_remember_tokens 
                      WHERE expires_at < NOW()";

            RunQuery([
                'conn' => $this->conn,
                'query' => $query,
                'params' => []
            ]);

            return [
                'success' => true,
                'message' => 'Expired tokens cleaned up successfully',
                'data' => null
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Cleanup failed: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Check if admin is authenticated via session or remember me cookie
     * Handles session validation, remember me token validation, and session refresh
     * 
     * @return array Response with authentication status and admin data
     */
    public function checkAuthentication()
    {
        try {
            // Check if auth check is disabled (for development)
            if (isset($_ENV['ADMIN_AUTH_CHECK']) && $_ENV['ADMIN_AUTH_CHECK'] === 'false') {
                return [
                    'success' => true,
                    'message' => 'Development mode - auth bypassed',
                    'data' => [
                        'admin_id' => 1,
                        'username' => 'admin',
                        'role' => 'master',
                        'auth_method' => 'development'
                    ]
                ];
            }

            // Start session if not already started
            $this->startSecureSession();

            // 1. Check active session first
            $sessionResult = $this->checkActiveSession();
            if ($sessionResult['success']) {
                return $sessionResult;
            }

            // 2. Check remember me cookie if no active session
            $cookieResult = $this->checkRememberMeCookie();
            if ($cookieResult['success']) {
                return $cookieResult;
            }

            // 3. Not authenticated
            return [
                'success' => false,
                'message' => 'Please login first',
                'data' => null
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Authentication check error: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Start secure session if not already started
     * FIXED: Uses proper secure/samesite settings
     * 
     * @return void
     */
    private function startSecureSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            $settings = $this->getCookieSecuritySettings();

            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => $_ENV['SET_COOKIE_DOMAIN'] ?? '',
                'secure' => $settings['secure'],
                'httponly' => true,
                'samesite' => $settings['samesite']
            ]);

            session_start();
        }
    }

    /**
     * Check if there's an active valid session
     * Handles session timeout (6 hours inactivity)
     * 
     * @return array Response with session validation result
     */
    private function checkActiveSession()
    {
        if (empty($_SESSION['admin_user_id'])) {
            return [
                'success' => false,
                'message' => 'No active session',
                'data' => null
            ];
        }

        // Check session timeout (6 hours = 21600 seconds)
        if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 21600)) {
            session_unset();
            session_destroy();
            return [
                'success' => false,
                'message' => 'Session expired. Please login again.',
                'data' => null
            ];
        }

        // Update last activity timestamp
        $_SESSION['last_activity'] = time();

        return [
            'success' => true,
            'message' => 'Active session found',
            'data' => [
                'admin_id' => $_SESSION['admin_user_id'],
                'username' => $_SESSION['admin_username'],
                'role' => $_SESSION['admin_role'],
                'unique_hash' => $_SESSION['admin_hash_id'] ?? null,
                'auth_method' => 'session'
            ]
        ];
    }

    /**
     * Check and validate remember me cookie
     * Automatically recreates session if valid token found
     * FIXED: Uses proper secure/samesite settings
     * 
     * @return array Response with cookie validation result
     */
    private function checkRememberMeCookie()
    {
        if (empty($_COOKIE['remember_me_token'])) {
            return [
                'success' => false,
                'message' => 'No remember me cookie',
                'data' => null
            ];
        }

        $cookieToken = $_COOKIE['remember_me_token'];
        $tokenHash = hash('sha256', $cookieToken);

        // Query to get token and user details
        $query = "SELECT t.id AS token_id, t.admin_id, t.expires_at,
                     u.username, u.unique_hash, u.role
              FROM admin_user_remember_tokens t
              JOIN admin_user u ON t.admin_id = u.id
              WHERE t.token_hash = :token_hash
              AND t.expires_at > NOW()
              LIMIT 1";

        $result = RunQuery([
            'conn' => $this->conn,
            'query' => $query,
            'params' => [':token_hash' => $tokenHash]
        ]);

        if (empty($result)) {
            // Invalid/expired token - remove cookie
            $settings = $this->getCookieSecuritySettings();
            setcookie('remember_me_token', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => $_ENV['SET_COOKIE_DOMAIN'] ?? '',
                'secure' => $settings['secure'],
                'httponly' => true,
                'samesite' => $settings['samesite']
            ]);
            return [
                'success' => false,
                'message' => 'Invalid or expired remember me token',
                'data' => null
            ];
        }

        $user = $result[0];

        // Recreate session
        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = $user['admin_id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_hash_id'] = $user['unique_hash'];
        $_SESSION['admin_role'] = $user['role'];
        $_SESSION['last_activity'] = time();

        // Refresh token expiry and update usage info
        $newExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));
        $updateQuery = "UPDATE admin_user_remember_tokens 
                    SET last_used_at = NOW(), 
                        expires_at = :new_expiry,
                        ip_address = :ip, 
                        user_agent = :ua
                    WHERE id = :token_id";

        RunQuery([
            'conn' => $this->conn,
            'query' => $updateQuery,
            'params' => [
                ':new_expiry' => $newExpiry,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':token_id' => $user['token_id']
            ]
        ]);

        // FIXED: Refresh cookie expiry with proper settings
        $settings = $this->getCookieSecuritySettings();
        setcookie('remember_me_token', $cookieToken, [
            'expires' => strtotime($newExpiry),
            'path' => '/',
            'domain' => $_ENV['SET_COOKIE_DOMAIN'] ?? '',
            'secure' => $settings['secure'],
            'httponly' => true,
            'samesite' => $settings['samesite']
        ]);

        return [
            'success' => true,
            'message' => 'Authenticated via remember me token',
            'data' => [
                'admin_id' => $user['admin_id'],
                'username' => $user['username'],
                'role' => $user['role'],
                'unique_hash' => $user['unique_hash'],
                'auth_method' => 'remember_me'
            ]
        ];
    }

    /**
     * Check admin authentication
     * Returns admin data if authenticated, false otherwise
     * 
     * @return array|false Admin data array or false
     */
    public function checkAdminAuth()
    {
        $authResult = $this->checkAuthentication();

        if ($authResult['success']) {
            return $authResult['data']; // Returns: ['admin_id', 'username', 'role', 'auth_method']
        }

        return false;
    }

    /**
     * Check admin authentication and verify master role
     * Returns admin data if authenticated and is master, false otherwise
     * 
     * @return array|false Admin data array or false
     */
    public function checkAuthAndMasterRole()
    {
        $authResult = $this->checkAuthentication();

        if (!$authResult['success']) {
            return false;
        }

        $adminData = $authResult['data'];

        // Check if user is master role OR is system master (id=1)
        if ($adminData['role'] !== 'master' && $adminData['admin_id'] != 1) {
            return false;
        }

        return $adminData;
    }

    public function getAdminByHashId($hash_id)
    {
        try {
            $res = RunQuery([
                'conn' => $this->conn,
                'query' => 'select * from admin_user where unique_hash = ?',
                'params' => [$hash_id]
            ]);

            return [
                'success' => true,
                'message' => 'admin row fetch successfully',
                'data' => $res
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed : ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Logout admin user
     * Destroys session, removes remember me tokens, and clears cookies
     * FIXED: Uses proper secure/samesite settings
     * 
     * @param int|null $adminId Optional admin ID. If null, uses current session admin ID
     * @return array Response with success or error
     */
    public function logout($adminId = null)
    {
        try {
            // Start session if not already started
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $settings = $this->getCookieSecuritySettings();

            // Get admin ID from parameter or session
            $targetAdminId = $adminId ?? ($_SESSION['admin_user_id'] ?? null);

            // Delete remember me token from database
            if ($targetAdminId) {
                $this->deleteRememberMeToken($targetAdminId);
            }

            // Clear remember me cookie
            if (isset($_COOKIE['remember_me_token'])) {
                setcookie('remember_me_token', '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'domain' => $_ENV['SET_COOKIE_DOMAIN'] ?? '',
                    'secure' => $settings['secure'],
                    'httponly' => true,
                    'samesite' => $settings['samesite']
                ]);
                unset($_COOKIE['remember_me_token']);
            }

            // Destroy session
            session_unset();
            session_destroy();

            // Clear session cookie
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 3600,
                    'path' => $params['path'],
                    'domain' => $_ENV['SET_COOKIE_DOMAIN'] ?? '',
                    'secure' => $settings['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $settings['samesite']
                ]);
            }

            return [
                'success' => true,
                'message' => 'Logged out successfully',
                'data' => null
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Logout failed: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }



    /**
     * Check admin authentication and verify at least manager role
     * Returns admin data if authenticated and is manager or master, false otherwise
     * 
     * @return array|false Admin data array or false
     */
    public function checkAuthAndManagerRole()
    {
        $authResult = $this->checkAuthentication();

        if (!$authResult['success']) {
            return false;
        }

        $adminData = $authResult['data'];

        // Check if user is manager or master (or system master id=1)
        if (!in_array($adminData['role'], ['manager', 'master']) && $adminData['admin_id'] != 1) {
            return false;
        }

        return $adminData;
    }


    /**
     * Check if current request is HTTPS
     */
    private function isHttpsRequest()
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 80) == 443
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    /**
     * Check if current request is cross-origin
     */
    private function isCrossOriginRequest()
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (empty($origin)) {
            return false;
        }

        // Get current host
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        $originHost = parse_url($origin, PHP_URL_HOST);

        // If hosts differ, it's cross-origin
        return $originHost !== $currentHost;
    }


    /**
     * Check if running on localhost
     */
    private function isLocalhost()
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return in_array($host, ['localhost', '127.0.0.1', '::1'])
            || strpos($host, 'localhost:') === 0;
    }













    // testings ------------------------
    
    













}