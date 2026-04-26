-- Kindness Coin – one-time database setup
-- Run once against the 'kindness' database:
--   mysql -u root kindness < forms/setup.sql

CREATE DATABASE IF NOT EXISTS `kindness`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `kindness`;

CREATE TABLE IF NOT EXISTS `stories` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `approved`      TINYINT(1)      NOT NULL DEFAULT 0,
    `approved_at`   DATETIME        NULL DEFAULT NULL,
    `submitted_at`  DATETIME        NOT NULL,
    `full_name`     VARCHAR(255)    NOT NULL,
    `email`         VARCHAR(255)    NOT NULL,
    `club_name`     VARCHAR(255)    NOT NULL,
    `club_location` VARCHAR(255)    NOT NULL DEFAULT '',
    `country`              VARCHAR(100)    NOT NULL DEFAULT '',
    `constitutional_area`  VARCHAR(100)    NOT NULL DEFAULT '',
    `state_county`         VARCHAR(100)    NOT NULL DEFAULT '',
    `story`         TEXT            NOT NULL,
    `category`      VARCHAR(100)    NOT NULL DEFAULT '',
    `image_path`    VARCHAR(500)    NOT NULL DEFAULT '',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration for existing installations (run once if table already exists):
-- ALTER TABLE `stories`
--     ADD COLUMN `constitutional_area` VARCHAR(100) NOT NULL DEFAULT '' AFTER `country`;
