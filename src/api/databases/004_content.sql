-- =============================================================================
-- FILE: 004_content.sql
-- MODULE: Content Core — Categories, Tags, Posts & Relations
-- =============================================================================

DROP TABLE IF EXISTS categories;
CREATE TABLE categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY     COMMENT 'PK: category id',
    parent_id   INT UNSIGNED NULL                           COMMENT 'FK -> categories.id (self-join). NULL = top-level',
    name        VARCHAR(100) NOT NULL                       COMMENT 'Display name',
    slug        VARCHAR(100) NOT NULL UNIQUE                COMMENT 'URL-safe slug',
    description TEXT NULL                                   COMMENT 'Optional short description',
    thumbnail_id BIGINT UNSIGNED NULL                       COMMENT 'FK -> media.id. Cover image',
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0        COMMENT 'Manual display order',
    is_active   BOOLEAN NOT NULL DEFAULT TRUE               COMMENT 'TRUE = visible, FALSE = hidden',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP         COMMENT 'Row creation timestamp',
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Row last update timestamp',

    INDEX idx_category_parent (parent_id),
    INDEX idx_category_slug   (slug),

    CONSTRAINT fk_category_parent    FOREIGN KEY (parent_id)    REFERENCES categories(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_category_thumbnail FOREIGN KEY (thumbnail_id) REFERENCES media(id)      ON DELETE SET NULL ON UPDATE CASCADE
) COMMENT = 'Hierarchical post categories' ENGINE = InnoDB;

DROP TABLE IF EXISTS tags;
CREATE TABLE tags (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY     COMMENT 'PK: tag id',
    name       VARCHAR(100) NOT NULL UNIQUE                COMMENT 'Display name',
    slug       VARCHAR(100) NOT NULL UNIQUE                COMMENT 'URL-safe tag slug',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP         COMMENT 'Row creation timestamp'
) COMMENT = 'Flat tag list for flexible post labeling' ENGINE = InnoDB;

DROP TABLE IF EXISTS posts;
CREATE TABLE posts (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY     COMMENT 'PK: post id',
    author_id      BIGINT UNSIGNED NULL                           COMMENT 'FK -> users.id',
    thumbnail_id   BIGINT UNSIGNED NULL                           COMMENT 'FK -> media.id. Featured image',
    title          VARCHAR(255) NOT NULL                          COMMENT 'Post headline',
    slug           VARCHAR(255) NOT NULL UNIQUE                   COMMENT 'URL-safe unique identifier',
    excerpt        TEXT NULL                                      COMMENT 'Short summary',
    body           LONGTEXT NOT NULL                              COMMENT 'Full post content',
    status         ENUM('draft','published','archived') NOT NULL DEFAULT 'draft' COMMENT 'Visibility status',
    is_featured    BOOLEAN NOT NULL DEFAULT FALSE                 COMMENT 'TRUE = pinned/featured',
    allow_comments BOOLEAN NOT NULL DEFAULT TRUE                  COMMENT 'TRUE = comments open',
    views          INT UNSIGNED NOT NULL DEFAULT 0                COMMENT 'Approximate view count',
    published_at   TIMESTAMP NULL                                 COMMENT 'Publish datetime',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP            COMMENT 'Row creation timestamp',
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Row last update timestamp',
    deleted_at     TIMESTAMP NULL                                 COMMENT 'Soft delete timestamp',

    INDEX idx_posts_author    (author_id),
    INDEX idx_posts_status    (status),
    INDEX idx_posts_featured  (is_featured),
    INDEX idx_posts_published (published_at),
    INDEX idx_posts_deleted   (deleted_at),
    FULLTEXT INDEX ft_posts_search (title, excerpt, body),

    CONSTRAINT fk_posts_author    FOREIGN KEY (author_id)    REFERENCES users(id)  ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_posts_thumbnail FOREIGN KEY (thumbnail_id) REFERENCES media(id)  ON DELETE SET NULL ON UPDATE CASCADE
) COMMENT = 'Core posts table' ENGINE = InnoDB;

DROP TABLE IF EXISTS post_categories;
CREATE TABLE post_categories (
    post_id     BIGINT UNSIGNED NOT NULL COMMENT 'FK -> posts.id',
    category_id INT UNSIGNED NOT NULL    COMMENT 'FK -> categories.id',
    PRIMARY KEY (post_id, category_id),

    CONSTRAINT fk_pc_post     FOREIGN KEY (post_id)     REFERENCES posts(id)      ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pc_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE ON UPDATE CASCADE
) COMMENT = 'Many-to-many between posts and categories' ENGINE = InnoDB;

DROP TABLE IF EXISTS post_tags;
CREATE TABLE post_tags (
    post_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> posts.id',
    tag_id  INT UNSIGNED NOT NULL    COMMENT 'FK -> tags.id',
    PRIMARY KEY (post_id, tag_id),

    CONSTRAINT fk_pt_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pt_tag  FOREIGN KEY (tag_id)  REFERENCES tags(id)  ON DELETE CASCADE ON UPDATE CASCADE
) COMMENT = 'Many-to-many between posts and tags' ENGINE = InnoDB;

DROP TABLE IF EXISTS post_meta;
CREATE TABLE post_meta (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'PK: meta row id',
    post_id    BIGINT UNSIGNED NOT NULL                   COMMENT 'FK -> posts.id',
    meta_key   VARCHAR(100) NOT NULL                      COMMENT 'Identifier e.g. seo_title',
    meta_value LONGTEXT NULL                              COMMENT 'Value for the key',

    UNIQUE KEY idx_postmeta_unique (post_id, meta_key),
    INDEX      idx_postmeta_key    (meta_key),

    CONSTRAINT fk_postmeta_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE ON UPDATE CASCADE
) COMMENT = 'EAV store for per-post metadata' ENGINE = InnoDB;
