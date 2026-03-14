<?php

// src/api/services/ActivityService.php

namespace App\Services;

use App\Core\Database;
use App\Repositories\ActivityRepository;

/**
 * ActivityService
 * Handles business logic for fetching audit logs and analytics.
 */
class ActivityService
{
    private Database $db;
    private ActivityRepository $activityRepo;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->activityRepo = new ActivityRepository($db);
    }

    /**
     * Get a paginated list of admin audit logs.
     *
     * @param int $limit
     * @param int $page
     * @return array { items, page, limit }
     */
    public function getAuditLogs(int $limit = 50, int $page = 1): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $items = $this->activityRepo->getAdminLogs($limit, $offset);

        return [
            'items' => $items,
            'page' => $page,
            'limit' => $limit
        ];
    }

    /**
     * Get aggregate statistics for a specific user event type over the last X days.
     * Useful for building charts in the admin dashboard (e.g., 'post.viewed').
     *
     * @param string $eventType
     * @param int $days
     * @return array
     */
    public function getAnalyticsChartData(string $eventType, int $days = 30): array
    {
        return $this->activityRepo->getEventStatsLastDays($eventType, $days);
    }
}
