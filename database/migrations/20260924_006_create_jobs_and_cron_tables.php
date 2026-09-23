<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_006_create_jobs_and_cron_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS jobs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                uuid CHAR(36) NOT NULL UNIQUE,
                type VARCHAR(120) NOT NULL,
                payload_json LONGTEXT NOT NULL,
                priority INT NOT NULL DEFAULT 0,
                status VARCHAR(32) NOT NULL DEFAULT "PENDING",
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
                available_at DATETIME NOT NULL,
                locked_at DATETIME NULL,
                locked_by VARCHAR(120) NULL,
                last_error TEXT NULL,
                created_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                INDEX idx_jobs_queue (status, available_at, priority, id),
                INDEX idx_jobs_locked (locked_at),
                INDEX idx_jobs_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cron_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                run_uuid CHAR(36) NOT NULL UNIQUE,
                task VARCHAR(120) NOT NULL,
                status VARCHAR(32) NOT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                processed_count INT UNSIGNED NOT NULL DEFAULT 0,
                error_count INT UNSIGNED NOT NULL DEFAULT 0,
                message TEXT NULL,
                INDEX idx_cron_task_started (task, started_at),
                INDEX idx_cron_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS cron_runs');
        $pdo->exec('DROP TABLE IF EXISTS jobs');
    }
};
