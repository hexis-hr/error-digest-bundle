<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Js;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Hexis\ErrorDigestBundle\Domain\PiiScrubber;
use Hexis\ErrorDigestBundle\Domain\StorageScrubber;
use Hexis\ErrorDigestBundle\Entity\ErrorFingerprint;

/**
 * Writes a browser-reported error into the same err_fingerprint / err_occurrence
 * tables as server-side captures. Channel is always "js" so the existing UI
 * filter lets admins view server vs browser errors separately.
 *
 * Every text the browser sends passes the PiiScrubber before it is stored; the
 * page and source URLs lose their query string and fragment first.
 */
final class JsIngester
{
    private const CHANNEL = 'js';
    private const LEVEL = 400;               // Monolog::ERROR
    private const LEVEL_NAME = 'error';

    private readonly StorageScrubber $scrubber;

    public function __construct(
        private readonly Connection $connection,
        private readonly JsFingerprinter $fingerprinter,
        PiiScrubber $scrubber,
        private readonly string $fingerprintTable,
        private readonly string $occurrenceTable,
    ) {
        $this->scrubber = new StorageScrubber($scrubber);
    }

    public function ingest(JsEvent $event, string $environment): void
    {
        $fingerprint = $this->fingerprinter->fingerprint($event);
        $event = $this->scrubbed($event);

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

        // A new occurrence reopens a resolved fingerprint; a muted one stays muted and the
        // assignee stays. resolved_at comes before status: MySQL assigns in order, and the
        // CASE has to read the old status.
        if ($platform instanceof AbstractMySQLPlatform) {
            $sql = sprintf(
                'INSERT INTO %1$s (fingerprint, level, level_name, message, exception_class, file, line, channel, environment, first_seen_at, last_seen_at, occurrence_count, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    resolved_at = CASE WHEN status = \'%2$s\' THEN NULL ELSE resolved_at END,
                    status = CASE WHEN status = \'%2$s\' THEN \'%3$s\' ELSE status END,
                    last_seen_at = GREATEST(last_seen_at, VALUES(last_seen_at)),
                    occurrence_count = occurrence_count + 1',
                $this->fingerprintTable,
                ErrorFingerprint::STATUS_RESOLVED,
                ErrorFingerprint::STATUS_OPEN,
            );
        } elseif ($platform instanceof PostgreSQLPlatform || $platform instanceof SqlitePlatform) {
            // CASE rather than GREATEST(), which SQLite does not have.
            $sql = sprintf(
                'INSERT INTO %1$s (fingerprint, level, level_name, message, exception_class, file, line, channel, environment, first_seen_at, last_seen_at, occurrence_count, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (fingerprint) DO UPDATE SET
                    resolved_at = CASE WHEN %1$s.status = \'%2$s\' THEN NULL ELSE %1$s.resolved_at END,
                    status = CASE WHEN %1$s.status = \'%2$s\' THEN \'%3$s\' ELSE %1$s.status END,
                    last_seen_at = CASE WHEN EXCLUDED.last_seen_at > %1$s.last_seen_at THEN EXCLUDED.last_seen_at ELSE %1$s.last_seen_at END,
                    occurrence_count = %1$s.occurrence_count + 1',
                $this->fingerprintTable,
                ErrorFingerprint::STATUS_RESOLVED,
                ErrorFingerprint::STATUS_OPEN,
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

    private function scrubbed(JsEvent $event): JsEvent
    {
        $text = $this->scrubber->texts([
            'message' => $event->message,
            'exception_type' => $event->exceptionType,
            'source_url' => StorageScrubber::withoutQuery($event->sourceUrl),
            'stack' => $event->stack,
            'page_url' => StorageScrubber::withoutQuery($event->pageUrl),
            'user_ref' => $event->userRef,
        ]);

        return new JsEvent(
            message: (string) $text['message'],
            exceptionType: $text['exception_type'],
            sourceUrl: $text['source_url'],
            line: $event->line,
            column: $event->column,
            stack: $text['stack'],
            pageUrl: $text['page_url'],
            userAgent: $event->userAgent,
            release: $event->release,
            userRef: $text['user_ref'],
            occurredAt: $event->occurredAt,
        );
    }

    private function insertOccurrence(int $fingerprintId, JsEvent $event): void
    {
        $context = $this->scrubber->context(array_filter([
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
