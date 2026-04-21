<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Error Digest Bundle initial schema: err_fingerprint + err_occurrence.
 *
 * Uses the Schema API so the generated DDL adapts to the active DBAL platform
 * (MySQL, MariaDB, PostgreSQL, SQLite).
 *
 * If you configure a non-default `error_digest.storage.table_prefix`, copy this
 * migration into your app's migrations and rename the two tables accordingly;
 * the bundle's TablePrefixListener handles runtime mapping, but DDL is static.
 */
final class Version20260420000001 extends AbstractMigration
{
    private const FINGERPRINT_TABLE = 'err_fingerprint';
    private const OCCURRENCE_TABLE = 'err_occurrence';

    public function getDescription(): string
    {
        return 'ErrorDigestBundle: create err_fingerprint and err_occurrence tables.';
    }

    public function up(Schema $schema): void
    {
        $fingerprint = $schema->createTable(self::FINGERPRINT_TABLE);
        $fingerprint->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
        $fingerprint->addColumn('fingerprint', 'string', ['length' => 64, 'notnull' => true]);
        $fingerprint->addColumn('level', 'smallint', ['notnull' => true]);
        $fingerprint->addColumn('level_name', 'string', ['length' => 16, 'notnull' => true]);
        $fingerprint->addColumn('message', 'text', ['notnull' => true]);
        $fingerprint->addColumn('exception_class', 'string', ['length' => 255, 'notnull' => false]);
        $fingerprint->addColumn('file', 'string', ['length' => 512, 'notnull' => false]);
        $fingerprint->addColumn('line', 'integer', ['notnull' => false]);
        $fingerprint->addColumn('channel', 'string', ['length' => 64, 'notnull' => true]);
        $fingerprint->addColumn('environment', 'string', ['length' => 32, 'notnull' => true]);
        $fingerprint->addColumn('first_seen_at', 'datetime_immutable', ['notnull' => true]);
        $fingerprint->addColumn('last_seen_at', 'datetime_immutable', ['notnull' => true]);
        $fingerprint->addColumn('occurrence_count', 'bigint', ['notnull' => true, 'default' => 0]);
        $fingerprint->addColumn('status', 'string', ['length' => 16, 'notnull' => true, 'default' => 'open']);
        $fingerprint->addColumn('resolved_at', 'datetime_immutable', ['notnull' => false]);
        $fingerprint->addColumn('assignee_id', 'string', ['length' => 64, 'notnull' => false]);
        $fingerprint->addColumn('notified_in_digest_at', 'datetime_immutable', ['notnull' => false]);
        $fingerprint->setPrimaryKey(['id']);
        $fingerprint->addUniqueIndex(['fingerprint'], 'uniq_err_fingerprint');
        $fingerprint->addIndex(['status', 'last_seen_at'], 'idx_err_status_last_seen');
        $fingerprint->addIndex(['level', 'last_seen_at'], 'idx_err_level_last_seen');
        $fingerprint->addIndex(['channel'], 'idx_err_channel');

        $occurrence = $schema->createTable(self::OCCURRENCE_TABLE);
        $occurrence->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
        $occurrence->addColumn('fingerprint_id', 'bigint', ['notnull' => true]);
        $occurrence->addColumn('occurred_at', 'datetime_immutable', ['notnull' => true]);
        $occurrence->addColumn('context_json', 'json', ['notnull' => true]);
        $occurrence->addColumn('request_uri', 'string', ['length' => 2048, 'notnull' => false]);
        $occurrence->addColumn('method', 'string', ['length' => 10, 'notnull' => false]);
        $occurrence->addColumn('user_ref', 'string', ['length' => 64, 'notnull' => false]);
        $occurrence->addColumn('trace_preview', 'text', ['notnull' => false]);
        $occurrence->setPrimaryKey(['id']);
        $occurrence->addIndex(['fingerprint_id', 'occurred_at'], 'idx_err_fingerprint_occurred');
        $occurrence->addIndex(['occurred_at'], 'idx_err_occurred_at');
        $occurrence->addForeignKeyConstraint(
            self::FINGERPRINT_TABLE,
            ['fingerprint_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_err_occurrence_fingerprint',
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(self::OCCURRENCE_TABLE);
        $schema->dropTable(self::FINGERPRINT_TABLE);
    }
}
