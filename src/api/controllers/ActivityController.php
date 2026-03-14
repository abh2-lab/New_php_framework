<?php

// src/api/controllers/ActivityController.php

namespace App\Controllers;

use App\Core\BaseController;
use App\Services\ActivityService;
use Exception;

/**
 * ActivityController
 * Handles HTTP requests for the admin audit trail and basic analytics.
 * ALL endpoints here require AdminMiddleware.
 */
class ActivityController extends BaseController
{
    private ActivityService $activityService;

    public function __construct()
    {
        parent::__construct();
        $this->activityService = new ActivityService($this->db);
    }

    /**
     * GET /admin/activity-logs
     * Fetch a paginated list of all admin actions (who banned who, who edited what, etc.)
     */
    public function getAuditLogs(): void
    {
        try {
            $params = $this->getQueryParams();
            
            $page  = isset($params['page']) ? (int)$params['page'] : 1;
            $limit = isset($params['limit']) ? (int)$params['limit'] : 50;

            $data = $this->activityService->getAuditLogs($limit, $page);

            $this->sendSuccess('Audit logs retrieved successfully.', $data);

        } catch (Exception $e) {
            error_log($e->getMessage());
            $this->sendServerError('An unexpected error occurred while fetching audit logs.');
        }
    }

    /**
     * GET /admin/analytics
     * Fetch aggregated statistics for a specific event to render a chart.
     * Example: ?event_type=post.viewed&days=30
     */
    public function getAnalytics(): void
    {
        try {
            $params = $this->getQueryParams();

            if (empty($params['event_type'])) {
                $this->sendValidationError('event_type parameter is required.', ['event_type']);
                return;
            }

            $days = isset($params['days']) ? (int)$params['days'] : 30;

            $chartData = $this->activityService->getAnalyticsChartData($params['event_type'], $days);

            $this->sendSuccess('Analytics data retrieved successfully.', ['chart_data' => $chartData]);

        } catch (Exception $e) {
            error_log($e->getMessage());
            $this->sendServerError('An unexpected error occurred while fetching analytics.');
        }
    }
}
