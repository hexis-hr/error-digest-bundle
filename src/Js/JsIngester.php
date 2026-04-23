<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Js;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Hexis\ErrorDigestBundle\Domain\PiiScrubber;
use Hexis\ErrorDigestBundle\Entity\ErrorFingerprint;

/**
 * Writes a browser-reported error into the same err_fingerprint / err_occurrence
 * tables as server-side captures. Channel is always "js" so the existing UI
 * filter lets admins view server vs browser errors separately.
 */
final class JsIngester
{
    private const CHANNEL = 'js';
    private const LEVEL = 400;               // Monolog::ERROR
    private const LEVEL_NAME = 'error';

    public function __construct(
        private readonly Connection $connection,
        private readonly JsFingerprinter $fingerprinter,
        private readonly PiiScrubber $scrubber,
        private readonly string $fingerprintTable,
        private readonly string $occurrenceTable,
    ) {
    }

    public function ingest(JsEvent $event, string $environment): void
    {
        $fingerprint = $this->fingerprinter->fingerprint($event);

        $this->connection->beginTransaction();
        try {
            $id = $this->upsertFingerprint($fingerprint, $event, $environment);
            $this->insertOccurrence($id, $event);
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    private function upsertFingerprint(string $fingerprint, JsEvent $event, string $environment): int
    {
        $platform = $this->connection->getDatabasePlatform();
        $occurredAt = $event->occurredAt->format('Y-m-d H:i:s');

        if ($platform instanceof AbstractMySQLPlatform) {
            $sql = sprintf(
                'INSERT INTO %s (fingerprint, level, level_name, message, exception_class, file, line, channel, environment, first_seen_at, last_seen_at, occurrence_count, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    last_seen_at = GREATEST(last_seen_at, VALUES(last_seen_at)),
                    occurrence_count = occurrence_count + 1',
                $this->fingerprintTable,
            );
        } elseif ($platform instanceof PostgreSQLPlatform || $platform instanceof SqlitePlatform) {
            $sql = sprintf(
                'INSERT INTO %s (fingerprint, level, level_name, message, exception_class, file, line, channel, environment, first_seen_at, last_seen_at, occurrence_count, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (fingerprint) DO UPDATE SET
                    last_seen_at = GREATEST(%1$s.last_seen_at, EXCLUDED.last_seen_at),
                    occurrence_count = %1$s.occurrence_count + 1',
                $this->fingerprintTable,
            );
        } else {
            throw new \RuntimeException('Unsupported database platform: ' . $platform::class);
        }

        $this->connection->executeStatement($sql, [
            $fingerprint,
            self::LEVEL,
            self::LEVEL_NAME,
            $event->message,
            $event->exceptionType,
            $event->sourceUrl,
            $event->line,
            self::CHANNEL,
            $environment,
            $occurredAt,
            $occurredAt,
            1,
            ErrorFingerprint::STATUS_OPEN,
        ], [
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::STRING,
        ]);

        $id = $this->connection->fetchOne(
            sprintf('SELECT id FROM %s WHERE fingerprint = ?', $this->fingerprintTable),
            [$fingerprint],
            [ParameterType::STRING],
        );

        if ($id === false) {
            throw new \RuntimeException('Fingerprint row disappeared immediately after upsert: ' . $fingerprint);
        }

        return (int) $id;
    }

    private function insertOccurrence(int $fingerprintId, JsEvent $event): void
    {
        $context = $this->scrubber->scrub(array_filter([
            'source_url' => $event->sourceUrl,
            'line' => $event->line,
            'column' => $event->column,
            'user_agent' => $event->userAgent,
            'release' => $event->release,
            'exception_type' => $event->exceptionType,
        ], static fn ($v) => $v !== null));

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (fingerprint_id, occurred_at, context_json, request_uri, method, user_ref, trace_preview)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                $this->occurrenceTable,
            ),
            [
                $fingerprintId,
                $event->occurredAt->format('Y-m-d H:i:s'),
                json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                $event->pageUrl,
                'GET',
                $event->userRef,
                $event->stack,
            ],
            [
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );
    }
}
