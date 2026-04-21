<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Storage;

use Monolog\LogRecord;

/**
 * In-memory accumulator for a single fingerprint within one request / console run.
 * Collects multiple occurrences to be flushed as one UPSERT + N (or fewer) inserts.
 */
final class BufferedRecord
{
    public int $count = 0;
    public \DateTimeImmutable $firstSeenAt;
    public \DateTimeImmutable $lastSeenAt;
    public ?\DateTimeImmutable $lastOccurrenceWrittenAt = null;

    /** @var list<LogRecord> Records selected for occurrence-row insertion (post-rate-limit). */
    public array $occurrences = [];

    public LogRecord $templateRecord;

    public function __construct(LogRecord $record)
    {
        $this->templateRecord = $record;
        $this->firstSeenAt = \DateTimeImmutable::createFromInterface($record->datetime);
        $this->lastSeenAt = $this->firstSeenAt;
    }

    public function addOccurrence(LogRecord $record, int $rateLimitSeconds): void
    {
        $this->count++;
        $recordTime = \DateTimeImmutable::createFromInterface($record->datetime);

        if ($recordTime > $this->lastSeenAt) {
            $this->lastSeenAt = $recordTime;
        }

        $shouldWriteOccurrence = $this->lastOccurrenceWrittenAt === null
            || $rateLimitSeconds === 0
            || ($recordTime->getTimestamp() - $this->lastOccurrenceWrittenAt->getTimestamp()) >= $rateLimitSeconds;

        if ($shouldWriteOccurrence) {
            $this->occurrences[] = $record;
            $this->lastOccurrenceWrittenAt = $recordTime;
        }
    }
}
