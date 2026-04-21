<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Hexis\ErrorDigestBundle\Domain\PiiScrubber;
use Hexis\ErrorDigestBundle\Entity\ErrorFingerprint;

/**
 * Direct DBAL writer. Uses its own connection so it doesn't entangle with the
 * host's EntityManager unit-of-work or transaction boundaries.
 */
final class DbalWriter implements Writer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PiiScrubber $scrubber,
        private readonly string $fingerprintTable,
        private readonly string $occurrenceTable,
    ) {
    }

    /**
     * @param array<string, BufferedRecord> $buffer Keyed by fingerprint.
     */
    public function flush(array $buffer, string $environment): void
    {
        if ($buffer === []) {
            return;
        }

        $this->connection->beginTransaction();
        try {
            foreach ($buffer as $fingerprint => $buffered) {
                $fingerprintId = $this->upsertFingerprint($fingerprint, $buffered, $environment);
                foreach ($buffered->occurrences as $record) {
                    $this->insertOccurrence($fingerprintId, $record);
                }
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    private function upsertFingerprint(string $fingerprint, BufferedRecord $buffered, string $environment): int
    {
        $record = $buffered->templateRecord;
        $exception = $record->context['exception'] ?? null;

        $level = $record->level->value;
        $levelName = strtolower($record->level->getName());
        $message = $exception instanceof \Throwable ? $exception->getMessage() : $record->message;
        $exceptionClass = $exception instanceof \Throwable ? $exception::class : null;
        $file = $exception instanceof \Throwable ? $exception->getFile() : null;
        $line = $exception instanceof \Throwable ? $exception->getLine() : null;

        $platform = $this->connection->getDatabasePlatform();
        $firstSeenSql = $buffered->firstSeenAt->format('Y-m-d H:i:s');
        $lastSeenSql = $buffered->lastSeenAt->format('Y-m-d H:i:s');

        if ($platform instanceof AbstractMySQLPlatform) {
            $sql = sprintf(
                'INSERT INTO %s (fingerprint, level, level_name, message, exception_class, file, line, channel, environment, first_seen_at, last_seen_at, occurrence_count, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    last_seen_at = CASE WHEN VALUES(last_seen_at) > last_seen_at THEN VALUES(last_seen_at) ELSE last_seen_at END,
                    occurrence_count = occurrence_count + VALUES(occurrence_count),
                    level_name = CASE WHEN VALUES(level) > level THEN VALUES(level_name) ELSE level_name END,
                    level = CASE WHEN VALUES(level) > level THEN VALUES(level) ELSE level END',
                $this->fingerprintTable,
            );
        } elseif ($platform instanceof PostgreSQLPlatform || $platform instanceof SqlitePlatform) {
            $sql = sprintf(
                'INSERT INTO %1$s (fingerprint, level, level_name, message, exception_class, file, line, channel, environment, first_seen_at, last_seen_at, occurrence_count, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (fingerprint) DO UPDATE SET
                    last_seen_at = CASE WHEN EXCLUDED.last_seen_at > %1$s.last_seen_at THEN EXCLUDED.last_seen_at ELSE %1$s.last_seen_at END,
                    occurrence_count = %1$s.occurrence_count + EXCLUDED.occurrence_count,
                    level_name = CASE WHEN EXCLUDED.level > %1$s.level THEN EXCLUDED.level_name ELSE %1$s.level_name END,
                    level = CASE WHEN EXCLUDED.level > %1$s.level THEN EXCLUDED.level ELSE %1$s.level END',
                $this->fingerprintTable,
            );
        } else {
            throw new \RuntimeException('Unsupported database platform: ' . $platform::class);
        }

        $this->connection->executeStatement($sql, [
            $fingerprint,
            $level,
            $levelName,
            $message,
            $exceptionClass,
            $file,
            $line,
            $record->channel,
            $environment,
            $firstSeenSql,
            $lastSeenSql,
            $buffered->count,
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

    private function insertOccurrence(int $fingerprintId, \Monolog\LogRecord $record): void
    {
        $context = $record->context;
        $exception = $context['exception'] ?? null;
        unset($context['exception']);
        $context = $this->scrubber->scrub($context);

        $extra = $this->scrubber->scrub($record->extra);
        if ($extra !== []) {
            $context['_extra'] = $extra;
        }

        $tracePreview = null;
        if ($exception instanceof \Throwable) {
            $tracePreview = $this->formatTracePreview($exception);
        }

        $requestUri = \is_string($record->extra['url'] ?? null) ? $record->extra['url'] : null;
        $method = \is_string($record->extra['http_method'] ?? null) ? $record->extra['http_method'] : null;

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (fingerprint_id, occurred_at, context_json, request_uri, method, user_ref, trace_preview)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                $this->occurrenceTable,
            ),
            [
                $fingerprintId,
                \DateTimeImmutable::createFromInterface($record->datetime)->format('Y-m-d H:i:s'),
                json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                $requestUri,
                $method,
                null,
                $tracePreview,
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

    private function formatTracePreview(\Throwable $exception): string
    {
        $lines = [
            sprintf('%s: %s', $exception::class, $exception->getMessage()),
            sprintf('  at %s:%d', $exception->getFile(), $exception->getLine()),
        ];

        $trace = $exception->getTrace();
        $max = min(20, \count($trace));
        for ($i = 0; $i < $max; $i++) {
            $frame = $trace[$i];
            $lines[] = sprintf(
                '  #%d %s%s%s at %s:%d',
                $i,
                $frame['class'] ?? '',
                isset($frame['type']) ? $frame['type'] : '',
                $frame['function'] ?? '',
                $frame['file'] ?? '[internal]',
                $frame['line'] ?? 0,
            );
        }

        return implode("\n", $lines);
    }

    public function prune(\DateTimeImmutable $threshold): int
    {
        return (int) $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE occurred_at < ?', $this->occurrenceTable),
            [$threshold->format('Y-m-d H:i:s')],
            [ParameterType::STRING],
        );
    }
}
