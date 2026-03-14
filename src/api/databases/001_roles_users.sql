-- =============================================================================
-- FILE: 001_roles_users.sql
-- MODULE: Foundation — Roles & Users
-- =============================================================================



DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
    id         TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY   COMMENT 'PK: role id',
    name       VARCHAR(50) NOT NULL UNIQUE                   COMMENT 'Unique machine-readable name e.g. admin, editor, user',
    label      VARCHAR(100) NOT NULL                         COMMENT 'Human-readable display label e.g. "Super Admin"',
    description TEXT NULL                                    COMMENT 'Optional explanation of what this role can do',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP           COMMENT 'Row creation timestamp'
) COMMENT = 'Application roles (admin, editor, user, etc.)' ENGINE = InnoDB;

INSERT INTO roles (name, label, description) VALUES
    ('admin',  'Administrator', 'Full access to all features'),
    ('editor', 'Editor',        'Can create, edit and publish posts'),
    ('user',   'Registered User','Standard registered user');



DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY      COMMENT 'PK: internal user id',
    role_id           TINYINT UNSIGNED NOT NULL DEFAULT 3             COMMENT 'FK -> roles.id. Defaults to 3 (user)',
    username          VARCHAR(100) NOT NULL UNIQUE                    COMMENT 'Unique URL-safe username',
    email             VARCHAR(150) NOT NULL UNIQUE                    COMMENT 'Unique email address',
    password          VARCHAR(255) NOT NULL                           COMMENT 'bcrypt-hashed password',
    full_name         VARCHAR(150) NULL                               COMMENT 'Display name',
    avatar            VARCHAR(255) NULL                               COMMENT 'Relative path to profile picture',
    bio               TEXT NULL                                       COMMENT 'Short user biography',
    is_active         BOOLEAN NOT NULL DEFAULT TRUE                   COMMENT 'TRUE = active, FALSE = suspended/banned',
    email_verified_at TIMESTAMP NULL                                  COMMENT 'Set when user verifies their email',
    last_login_at     TIMESTAMP NULL                                  COMMENT 'Timestamp of the most recent successful login',
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP             COMMENT 'Row creation timestamp',
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Row last update timestamp',
    deleted_at        TIMESTAMP NULL                                  COMMENT 'Soft delete timestamp',

    INDEX idx_users_role     (role_id),
    INDEX idx_users_active   (is_active),
    INDEX idx_users_deleted  (deleted_at),

    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT ON UPDATE CASCADE
) COMMENT = 'All application users — admins, editors and public users' ENGINE = InnoDB;

INSERT INTO users (
    role_id,
    username,
    email,
    password,
    full_name,
    is_active,
    email_verified_at
) VALUES (
    1, -- admin role (since admin was inserted first in roles table)
    'abhinandan',
    'abhinandan@boomlive.in',
    '$2y$10$n4aLNkSbTvKXalbHo5PLD.PGU8uj2WliYQ0ywbyHACAFOACHFNdfq', -- bcrypt hash for 'abcd'
    'Abhinandan',
    TRUE,
    NOW()
);