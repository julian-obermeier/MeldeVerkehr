<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_015_create_communication_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_reply_addresses (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                dispatch_id CHAR(36) NULL,
                local_part VARCHAR(190) NOT NULL UNIQUE,
                full_address VARCHAR(320) NOT NULL UNIQUE,
                token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
                created_at DATETIME NOT NULL,
                disabled_at DATETIME NULL,
                INDEX idx_reply_case_status (case_id, status),
                CONSTRAINT fk_reply_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_reply_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatches(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS authority_messages (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                dispatch_id CHAR(36) NULL,
                reply_address_id CHAR(36) NULL,
                direction VARCHAR(10) NOT NULL,
                channel VARCHAR(20) NOT NULL DEFAULT "EMAIL",
                external_message_id VARCHAR(500) NULL,
                message_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
                in_reply_to VARCHAR(500) NULL,
                sender_encrypted TEXT NOT NULL,
                recipient_encrypted TEXT NOT NULL,
                subject_encrypted TEXT NULL,
                body_text_encrypted LONGTEXT NOT NULL,
                body_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                classification VARCHAR(40) NOT NULL DEFAULT "OTHER",
                classification_source VARCHAR(30) NOT NULL DEFAULT "DETERMINISTIC",
                classification_confidence DECIMAL(5,4) NULL,
                received_at DATETIME NULL,
                sent_at DATETIME NULL,
                last_error VARCHAR(2000) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_authority_message_case (case_id, created_at),
                INDEX idx_authority_message_class (case_id, classification, created_at),
                CONSTRAINT fk_authority_message_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_authority_message_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatches(id) ON DELETE SET NULL,
                CONSTRAINT fk_authority_message_reply FOREIGN KEY (reply_address_id) REFERENCES case_reply_addresses(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS authority_message_attachments (
                id CHAR(36) NOT NULL PRIMARY KEY,
                message_id CHAR(36) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                storage_path VARCHAR(700) NOT NULL,
                mime_type VARCHAR(190) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL,
                sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_message_attachment_message (message_id),
                CONSTRAINT fk_message_attachment_message FOREIGN KEY (message_id) REFERENCES authority_messages(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_tasks (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                source_message_id CHAR(36) NULL,
                task_type VARCHAR(50) NOT NULL,
                title VARCHAR(255) NOT NULL,
                description_encrypted TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "OPEN",
                due_at DATETIME NULL,
                source VARCHAR(30) NOT NULL DEFAULT "SYSTEM",
                created_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                INDEX idx_case_task_open (case_id, status, due_at),
                CONSTRAINT fk_case_task_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_task_message FOREIGN KEY (source_message_id) REFERENCES authority_messages(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_deadlines (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                source_message_id CHAR(36) NULL,
                deadline_type VARCHAR(50) NOT NULL,
                due_at DATETIME NOT NULL,
                source_text_encrypted TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "OPEN",
                created_at DATETIME NOT NULL,
                resolved_at DATETIME NULL,
                INDEX idx_case_deadline_open (case_id, status, due_at),
                CONSTRAINT fk_case_deadline_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_deadline_message FOREIGN KEY (source_message_id) REFERENCES authority_messages(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inbound_mail_quarantine (
                id CHAR(36) NOT NULL PRIMARY KEY,
                source_id VARCHAR(255) NULL,
                message_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
                sender_encrypted TEXT NOT NULL,
                recipients_encrypted TEXT NOT NULL,
                subject_encrypted TEXT NULL,
                body_text_encrypted LONGTEXT NOT NULL,
                body_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                reason VARCHAR(255) NOT NULL,
                received_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                resolved_at DATETIME NULL,
                INDEX idx_mail_quarantine_open (resolved_at, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS authority_reply_drafts (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                inbound_message_id CHAR(36) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                body_encrypted LONGTEXT NOT NULL,
                body_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                created_by_user_id CHAR(36) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "DRAFT",
                queue_job_uuid CHAR(36) NULL,
                created_at DATETIME NOT NULL,
                confirmed_at DATETIME NULL,
                sent_at DATETIME NULL,
                UNIQUE KEY uq_reply_draft_version (inbound_message_id, version_no),
                INDEX idx_reply_draft_case (case_id, created_at),
                INDEX idx_reply_draft_queue (status, queue_job_uuid),
                CONSTRAINT fk_reply_draft_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_reply_draft_message FOREIGN KEY (inbound_message_id) REFERENCES authority_messages(id) ON DELETE CASCADE,
                CONSTRAINT fk_reply_draft_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'ALTER TABLE dispatches
             ADD COLUMN reply_address_id CHAR(36) NULL AFTER endpoint_id,
             ADD COLUMN outbound_message_id VARCHAR(500) NULL AFTER queue_job_uuid,
             ADD INDEX idx_dispatch_reply_address (reply_address_id),
             ADD CONSTRAINT fk_dispatch_reply_address FOREIGN KEY (reply_address_id) REFERENCES case_reply_addresses(id) ON DELETE SET NULL'
        );

        $pdo->exec(
            'ALTER TABLE dispatch_dry_run_outbox
             ADD COLUMN reply_to VARCHAR(320) NULL AFTER recipient,
             ADD COLUMN message_id VARCHAR(500) NULL AFTER subject,
             ADD COLUMN in_reply_to VARCHAR(500) NULL AFTER message_id'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE dispatches DROP FOREIGN KEY fk_dispatch_reply_address');
        $pdo->exec('ALTER TABLE dispatches DROP INDEX idx_dispatch_reply_address');
        $pdo->exec('ALTER TABLE dispatches DROP COLUMN outbound_message_id, DROP COLUMN reply_address_id');
        $pdo->exec('ALTER TABLE dispatch_dry_run_outbox DROP COLUMN in_reply_to, DROP COLUMN message_id, DROP COLUMN reply_to');
        $pdo->exec('DROP TABLE IF EXISTS authority_reply_drafts');
        $pdo->exec('DROP TABLE IF EXISTS inbound_mail_quarantine');
        $pdo->exec('DROP TABLE IF EXISTS case_deadlines');
        $pdo->exec('DROP TABLE IF EXISTS case_tasks');
        $pdo->exec('DROP TABLE IF EXISTS authority_message_attachments');
        $pdo->exec('DROP TABLE IF EXISTS authority_messages');
        $pdo->exec('DROP TABLE IF EXISTS case_reply_addresses');
    }
};
