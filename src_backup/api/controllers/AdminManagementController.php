<?php
namespace App\Controllers;

use App\Services\AdminManagementService;
use App\Services\AdminAuthService;
use App\Exceptions\ValidationException;

class AdminManagementController extends BaseController
{
    private $adminManagementService;
    private $authService;

    public function __construct()
    {
        parent::__construct();
        $this->authService = new AdminAuthService($this->conn);
        $this->adminManagementService = new AdminManagementService($this->conn);
    }







    public function listAdminUsers()
    {
        try {
            // Check master role
            $admin = $this->authService->checkAuthAndMasterRole();
            if (!$admin) {
                $this->sendError('Unauthorized - Master role required', 403);
                return;
            }

            // Get query parameters
            $params = $this->getQueryParams();
            $role = $params['role'] ?? null;
            $page = isset($params['page']) ? (int) $params['page'] : 0;
            $limit = isset($params['limit']) ? (int) $params['limit'] : 20;


            // Get admin users list
            $result = $this->adminManagementService->listAdminUsers($role, $page, $limit);

            if (!$result['success']) {
                return $this->sendError($result['message'], 500);
            }

            return $this->sendSuccess($result['message'], $result['data']);

        } catch (ValidationException $e) {
            return $this->sendValidationError($e->getMessage(), $e->getValidationErrors());
        }


    }



    public function createAdminUser()
    {
        try {
            // Check master role
            $admin = $this->authService->checkAuthAndMasterRole();
            if (!$admin) {
                $this->sendError('Unauthorized - Master role required', 403);
                return;
            }

            $adminId = $admin['admin_id'];


            // Validate and sanitize incoming request data
            $data = $this->validateRequest([
                'username' => 'required|min:2|max:50',
                'email' => 'required|email',
                'password' => 'required|min:3',
                'role' => 'required|max:20',
            ]);


            // Create admin user via service
            $result = $this->adminManagementService->createAdminUser($data, $adminId);

            if (!$result['success']) {
                $statusCode = getErrorStatusCode($result['message'], [
                    'already exists' => 422,
                    'Invalid role' => 422
                ]);
                return $this->sendError($result['message'], $statusCode);
            }

            return $this->sendSuccess($result['message'], $result['data']);

        } catch (ValidationException $e) {
            return $this->sendValidationError($e->getMessage(), $e->getValidationErrors());
        }
    }



    public function assignProjectToAdmin($hash_id)
    {

        try {
            // Check master role
            $admin = $this->authService->checkAuthAndMasterRole();
            if (!$admin) {
                $this->sendError('Unauthorized - Master role required', 403);
                return;
            }

            $adminId = $admin['admin_id'];

            $inputData = $this->getRequestData();

            // pp($inputData);

            $projectCodes = normalizeInput($inputData['project_codes']);

            // pp($projectCodes);

            // Assign projects
            $result = $this->adminManagementService->assignProjectsToAdmin($hash_id, $inputData['project_codes']);



            if (!$result['success']) {
                $statusCode = getErrorStatusCode($result['message'], [
                    'not found' => 404,
                    'No valid project' => 422,
                    'No projects were assigned' => 422,
                    'Cannot assign' => 403
                ]);
                return $this->sendError($result['message'], $statusCode);
            }

            return $this->sendSuccess($result['message'], $result['data']);

        } catch (ValidationException $e) {
            return $this->sendValidationError($e->getMessage(), $e->getValidationErrors());
        }




    }


    public function deleteAdminUser($hash_id)
    {
        try {
            // Check master role
            $admin = $this->authService->checkAuthAndMasterRole();
            if (!$admin) {
                $this->sendError('Unauthorized - Master role required', 403);
                return;
            }
            // Delete admin user
            $result = $this->adminManagementService->deleteAdminUser($hash_id);


            if (!$result['success']) {
                $statusCode = getErrorStatusCode($result['message'], [
                    'not found' => 404,
                    'Cannot delete' => 403
                ]);
                return $this->sendError($result['message'], $statusCode);
            }

            return $this->sendSuccess($result['message'], null);

        } catch (ValidationException $e) {
            return $this->sendValidationError($e->getMessage(), $e->getValidationErrors());
        }


    }






    public function updateAdminUser($hashId)
    {
        try {
            // Check authentication and master role
            $admin = $this->authService->checkAuthAndMasterRole();
            if (!$admin) {
                $this->sendError('Unauthorized - Master role required', 403);
                return;
            }

            // Get request data
            $data = $this->getRequestData();

            // Validate that at least one field is provided
            if (empty($data)) {
                return $this->sendValidationError('No data provided for update', ['data' => 'At least one field is required']);
            }

            // Update admin user
            $result = $this->adminManagementService->updateAdminUser($hashId, $data, $admin['admin_id']);

            if (!$result['success']) {
                $statusCode = getErrorStatusCode($result['message'], [
                    'not found' => 404,
                    'already exists' => 422,
                    'No valid fields' => 422,
                    'Invalid role' => 422,
                    'Cannot' => 403
                ]);
                return $this->sendError($result['message'], $statusCode);
            }

            return $this->sendSuccess($result['message'], $result['data']);

        } catch (ValidationException $e) {
            return $this->sendValidationError($e->getMessage(), $e->getValidationErrors());
        } catch (\Exception $e) {
            return $this->sendError('Server error: ' . $e->getMessage(), 500);
        }
    }




    public function listUnassignedProjectsForAdmin($hash_id)
    {
        try {
            // Check authentication and master role
            $admin = $this->authService->checkAuthAndMasterRole();
            if (!$admin) {
                $this->sendError('Unauthorized - Master role required', 403);
                return;
            }

            // Validate input (route parameter is guaranteed to be present, but check if empty)
            if (empty($hash_id)) {
                return $this->sendError('Admin unique hash is required', 422);
            }

            // Get unassigned projects for this admin
            $result = $this->adminManagementService->getUnassignedProjectsForAdmin($hash_id);

            if (!$result['success']) {
                $statusCode = getErrorStatusCode($result['message'], [
                    'not found' => 404
                ]);
                return $this->sendError($result['message'], $statusCode);
            }

            return $this->sendSuccess($result['message'], $result['data']);

        } catch (\Exception $e) {
            return $this->sendError('Server error: ' . $e->getMessage(), 500);
        }
    }



    public function getUnassignedAdmins($projectCode)
    {
        try {
            // Check authentication and master role
            $admin = $this->authService->checkAuthAndMasterRole();
            if (!$admin) {
                $this->sendError('Unauthorized - Master role required', 403);
                return;
            }

            // Validate input
            if (empty($projectCode)) {
                return $this->sendError('Project code is required', 422);
            }

            // Get unassigned admins for this project
            $result = $this->adminManagementService->getUnassignedAdminsForProject($projectCode);

            if (!$result['success']) {
                $statusCode = getErrorStatusCode($result['message'], [
                    'not found' => 404
                ]);
                return $this->sendError($result['message'], $statusCode);
            }

            return $this->sendSuccess($result['message'], $result['data']);

        } catch (\Exception $e) {
            return $this->sendError('Server error: ' . $e->getMessage(), 500);
        }
    }





}
