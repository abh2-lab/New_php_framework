<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;

class AuthController extends BaseController
{
    public function __construct()
    {
        parent::__construct(); // This now handles environment loading
    }

    public function welcome()
    {
        $this->sendSuccess('Welcome to the connect.pingnetwork.in backend api 2');
    }

    public function test()
    {


        pp(testing());

        $a = ['hi', 1, 'abc' => 'xyz'];
        pp("Debug test from AuthController - this should show if DEBUG_MODE=true");
        pp($a);

        $testSql = RunQuery([
            'conn' => $this->conn,
            'query' => 'SELECT * FROM admin_user WHERE id = :id',
            'params' => [':id' => 1],
            'returnSql' => true
        ]);

        $this->sendSuccess('Test endpoint working correctly', [
            'test' => 'success',
            'timestamp' => time(),
            'php_version' => PHP_VERSION,
            'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB',
            'debug_mode' => $_ENV['DEBUG_MODE'] ?? 'not set',
            'log_errors' => $_ENV['LOG_ERRORS'] ?? 'not set',
            'sql_debug_test' => $testSql
        ]);
    }


}
