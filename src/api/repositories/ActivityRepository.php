<?php

// src/api/repositories/ActivityRepository.php

namespace App\Repositories;

use App\Core\Database;

/**
 * ActivityRepository
 * Handles data access for admin audit trails and end-user behavior tracking.
 */
class ActivityRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ----------------------------------------------------------------
    // ADMIN AUDIT LOGS
    // ----------------------------------------------------------------

    /**
     * Get a paginated list of admin actions for the audit trail.
     */
    public function getAdminLogs(int $limit = 50, int $offset = 0): array
    {
        return $this->db->select(
            "SELECT a.*, u.full_name AS actor_name, u.email AS actor_email 
             FROM activity_logs a
             LEFT JOIN users u ON a.user_id = u.id
             ORDER BY a.created_at DESC
             LIMIT :limit OFFSET :offset",
            ['limit' => $limit, 'offset' => $offset]
        );
    }

    /**
     * Log a new admin action.
     * Note: JSON columns must be json_encoded before passing here.
     */
    public function logAdminAction(array $data): int
    {
        return $this->db->insert(
            "INSERT INTO activity_logs (user_id, action, target_type, target_id, old_values, new_values, ip_address, user_agent, notes)
             VALUES (:user_id, :action, :target_type, :target_id, :old_values, :new_values, :ip_address, :user_agent, :notes)",
            [
                'user_id'     => $data['user_id'] ?? null,
                'action'      => $data['action'],
                'target_type' => $data['target_type'] ?? null,
                'target_id'   => $data['target_id'] ?? null,
                'old_values'  => $data['old_values'] ?? null,
                'new_values'  => $data['new_values'] ?? null,
                'ip_address'  => $data['ip_address'] ?? null,
                'user_agent'  => $data['user_agent'] ?? null,
                'notes'       => $data['notes'] ?? null,
            ]
        );
    }

    // ----------------------------------------------------------------
    // USER ACTIVITY (Analytics)
    // ----------------------------------------------------------------

    /**
     * Track a user event (e.g., 'post.viewed', 'search.performed').
     */
    public function logUserEvent(array $data): int
    {
        return $this->db->insert(
            "INSERT INTO user_activity (user_id, session_id, event_type, target_type, target_id, meta, ip_address, user_agent, referrer)
             VALUES (:user_id, :session_id, :event_type, :target_type, :target_id, :meta, :ip_address, :user_agent, :referrer)",
            [
                'user_id'     => $data['user_id'] ?? null,
                'session_id'  => $data['session_id'] ?? null,
                'event_type'  => $data['event_type'],
                'target_type' => $data['target_type'] ?? null,
                'target_id'   => $data['target_id'] ?? null,
                'meta'        => $data['meta'] ?? null,
                'ip_address'  => $data['ip_address'] ?? null,
                'user_agent'  => $data['user_agent'] ?? null,
                'referrer'    => $data['referrer'] ?? null,
            ]
        );
    }

    /**
     * Aggregate events by type over the last X days (useful for admin charts).
     */
    public function getEventStatsLastDays(string $eventType, int $days = 30): array
    {
        return $this->db->select(
            "SELECT DATE(created_at) AS event_date, COUNT(*) AS event_count
             FROM user_activity
             WHERE event_type = :event_type 
               AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY DATE(created_at)
             ORDER BY event_date ASC",
            ['event_type' => $eventType, 'days' => $days]
        );
    }
}
