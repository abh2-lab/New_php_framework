-- =============================================================================
-- FILE: 002_auth.sql
-- MODULE: Authentication — Sessions & Password Resets
-- =============================================================================

DROP TABLE IF EXISTS sessions;
CREATE TABLE sessions (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY     COMMENT 'PK: session record id',
    user_id       BIGINT UNSIGNED NOT NULL                       COMMENT 'FK -> users.id',
    token         VARCHAR(255) NOT NULL UNIQUE                   COMMENT 'Secure random token stored in user cookie',
    token_type    ENUM('session','remember_me','api') NOT NULL DEFAULT 'session' COMMENT 'Token type',
    ip_address    VARCHAR(45) NULL                               COMMENT 'IPv4 or IPv6 address',
    user_agent    TEXT NULL                                      COMMENT 'Browser/device user-agent',
    last_used_at  TIMESTAMP NULL                                 COMMENT 'Updated on each authenticated request',
    expires_at    TIMESTAMP NOT NULL                             COMMENT 'Hard expiry datetime',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP            COMMENT 'Row creation timestamp',

    INDEX idx_sessions_user    (user_id),
    INDEX idx_sessions_token   (token),
    INDEX idx_sessions_expires (expires_at),

    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) COMMENT = 'Login sessions and remember-me tokens' ENGINE = InnoDB;

DROP TABLE IF EXISTS password_resets;
CREATE TABLE password_resets (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY     COMMENT 'PK: reset request id',
    user_id    BIGINT UNSIGNED NOT NULL                       COMMENT 'FK -> users.id',
    token      VARCHAR(255) NOT NULL UNIQUE                   COMMENT 'Secure hashed reset token',
    is_used    BOOLEAN NOT NULL DEFAULT FALSE                 COMMENT 'FALSE = unused, TRUE = consumed',
    ip_address VARCHAR(45) NULL                               COMMENT 'IP address of request',
    expires_at TIMESTAMP NOT NULL                             COMMENT 'Token expiry',
    used_at    TIMESTAMP NULL                                 COMMENT 'Timestamp when used',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP            COMMENT 'Row creation timestamp',

    INDEX idx_pwreset_user    (user_id),
    INDEX idx_pwreset_token   (token),
    INDEX idx_pwreset_expires (expires_at),

    CONSTRAINT fk_pwreset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) COMMENT = 'Password reset token requests' ENGINE = InnoDB;
