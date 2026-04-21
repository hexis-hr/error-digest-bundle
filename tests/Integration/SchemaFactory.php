<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Builds an in-memory SQLite connection with the bundle's schema materialized.
 * Integration tests use this instead of booting a full Symfony kernel so they
 * stay portable and fast.
 */
final class SchemaFactory
{
    public const FINGERPRINT_TABLE = 'err_fingerprint';
    public const OCCURRENCE_TABLE = 'err_occurrence';

    public static function create(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        self::install($connection);

        return $connection;
    }

    public static function install(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE ' . self::FINGERPRINT_TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                fingerprint VARCHAR(64) NOT NULL UNIQUE,
                level SMALLINT NOT NULL,
                level_name VARCHAR(16) NOT NULL,
                message TEXT NOT NULL,
                exception_class VARCHAR(255),
                file VARCHAR(512),
                line INTEGER,
                channel VARCHAR(64) NOT NULL,
                environment VARCHAR(32) NOT NULL,
                first_seen_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                occurrence_count BIGINT NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT \'open\',
                resolved_at DATETIME,
                assignee_id VARCHAR(64),
                notified_in_digest_at DATETIME
            )
        ');

        $connection->executeStatement('
            CREATE TABLE ' . self::OCCURRENCE_TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                fingerprint_id BIGINT NOT NULL REFERENCES err_fingerprint(id) ON DELETE CASCADE,
                occurred_at DATETIME NOT NULL,
                context_json TEXT NOT NULL,
                request_uri VARCHAR(2048),
                method VARCHAR(10),
                user_ref VARCHAR(64),
                trace_preview TEXT
            )
        ');
    }
}
