<?php
namespace App\Core\Middlewares;

interface MiddlewareInterface {
    /**
     * Execute before controller
     * @return mixed Return null to continue, or response array to halt
     */
    public function before();
    
    /**
     * Execute after controller
     * @param mixed $response Controller response
     * @return mixed Modified response
     */
    public function after($response = null);
}
