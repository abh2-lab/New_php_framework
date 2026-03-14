-- =============================================================================
-- FILE: 006_activity_logs.sql
-- MODULE: Activity Tracking — Admin Logs & User Activity
-- =============================================================================

DROP TABLE IF EXISTS activity_logs;
CREATE TABLE activity_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY     COMMENT 'PK: log entry id',
    user_id     BIGINT UNSIGNED NULL                           COMMENT 'FK -> users.id',
    action      VARCHAR(100) NOT NULL                          COMMENT 'Machine-readable action name',
    target_type VARCHAR(50) NULL                               COMMENT 'Entity type affected',
    target_id   BIGINT UNSIGNED NULL                           COMMENT 'ID of the affected entity',
    old_values  JSON NULL                                      COMMENT 'JSON snapshot BEFORE change',
    new_values  JSON NULL                                      COMMENT 'JSON snapshot AFTER change',
    ip_address  VARCHAR(45) NULL                               COMMENT 'IP address of the actor',
    user_agent  TEXT NULL                                      COMMENT 'Browser/device user-agent',
    notes       TEXT NULL                                      COMMENT 'Human-readable description',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP            COMMENT 'Timestamp of action',

    INDEX idx_actlog_user   (user_id),
    INDEX idx_actlog_action (action),
    INDEX idx_actlog_target (target_type, target_id),
    INDEX idx_actlog_time   (created_at),

    CONSTRAINT fk_actlog_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) COMMENT = 'Admin audit trail for accountability' ENGINE = InnoDB;

DROP TABLE IF EXISTS user_activity;
CREATE TABLE user_activity (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY     COMMENT 'PK: activity record id',
    user_id      BIGINT UNSIGNED NULL                           COMMENT 'FK -> users.id',
    session_id   BIGINT UNSIGNED NULL                           COMMENT 'FK -> sessions.id',
    event_type   VARCHAR(100) NOT NULL                          COMMENT 'Type of event',
    target_type  VARCHAR(50) NULL                               COMMENT 'Entity type related to event',
    target_id    BIGINT UNSIGNED NULL                           COMMENT 'ID of related entity',
    meta         JSON NULL                                      COMMENT 'Flexible JSON payload',
    ip_address   VARCHAR(45) NULL                               COMMENT 'IP address of visitor',
    user_agent   TEXT NULL                                      COMMENT 'User-agent string',
    referrer     VARCHAR(500) NULL                              COMMENT 'HTTP Referer header',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP            COMMENT 'Event timestamp',

    INDEX idx_useract_user    (user_id),
    INDEX idx_useract_session (session_id),
    INDEX idx_useract_event   (event_type),
    INDEX idx_useract_target  (target_type, target_id),
    INDEX idx_useract_time    (created_at),

    CONSTRAINT fk_useract_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_useract_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL ON UPDATE CASCADE
) COMMENT = 'End-user behaviour log for analytics' ENGINE = InnoDB;
