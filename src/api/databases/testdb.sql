-- =====================================================
-- Migration: testdb
-- Purpose: All tables and seed data for testdb database
-- =====================================================

-- =====================================================
-- Table: users
-- =====================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL,
    `email` VARCHAR(100) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'user', 'guest') DEFAULT 'user',
    `status` ENUM('active', 'inactive', 'blocked') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`),
    UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Table: projects
-- =====================================================
CREATE TABLE IF NOT EXISTS `projects` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_code` VARCHAR(50) NOT NULL,
    `project_name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `status` ENUM('active', 'inactive', 'archived') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `project_code` (`project_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Seed Data: Insert dummy/test data
-- =====================================================

-- Insert test users
INSERT INTO `users` (`username`, `email`, `password`, `role`, `status`) VALUES
('admin', 'admin@example.com', '$2y$10$abcdefghijklmnopqrstuvwxyz', 'admin', 'active'),
('john_doe', 'john@example.com', '$2y$10$abcdefghijklmnopqrstuvwxyz', 'user', 'active'),
('jane_smith', 'jane@example.com', '$2y$10$abcdefghijklmnopqrstuvwxyz', 'user', 'active')
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

-- Insert test projects
INSERT INTO `projects` (`project_code`, `project_name`, `description`, `status`) VALUES
('PROJ001', 'Test Project 1', 'This is a test project for development', 'active'),
('PROJ002', 'Test Project 2', 'Another test project', 'active')
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;
