-- =============================================================================
-- FILE: 003_media.sql
-- MODULE: Media Library
-- =============================================================================

DROP TABLE IF EXISTS media;
CREATE TABLE media (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY      COMMENT 'PK: media file id',
    uploaded_by   BIGINT UNSIGNED NULL                            COMMENT 'FK -> users.id',
    file_name     VARCHAR(255) NOT NULL                           COMMENT 'Original filename',
    stored_name   VARCHAR(255) NOT NULL                           COMMENT 'Actual filename on disk',
    file_path     VARCHAR(500) NOT NULL                           COMMENT 'Relative path from storage root',
    file_url      VARCHAR(500) NULL                               COMMENT 'Public URL if hosted on CDN',
    mime_type     VARCHAR(100) NOT NULL                           COMMENT 'MIME type e.g. image/jpeg',
    file_type     ENUM('image','video','audio','document','other') NOT NULL DEFAULT 'image' COMMENT 'Broad category',
    file_size     INT UNSIGNED NOT NULL                           COMMENT 'File size in bytes',
    width         SMALLINT UNSIGNED NULL                          COMMENT 'Image/video width in pixels',
    height        SMALLINT UNSIGNED NULL                          COMMENT 'Image/video height in pixels',
    alt_text      VARCHAR(255) NULL                               COMMENT 'Accessibility alt text',
    caption       TEXT NULL                                       COMMENT 'Optional caption',
    disk          VARCHAR(50) NOT NULL DEFAULT 'local'            COMMENT 'Storage driver: local, s3, r2 etc.',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP             COMMENT 'Row creation timestamp',
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Row last update timestamp',
    deleted_at    TIMESTAMP NULL                                  COMMENT 'Soft delete timestamp',

    INDEX idx_media_uploader  (uploaded_by),
    INDEX idx_media_type      (file_type),
    INDEX idx_media_deleted   (deleted_at),

    CONSTRAINT fk_media_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) COMMENT = 'Central media library for all uploaded files' ENGINE = InnoDB;
