<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Integration\Storage;

use Doctrine\DBAL\Connection;
use Hexis\ErrorDigestBundle\Domain\DefaultPiiScrubber;
use Hexis\ErrorDigestBundle\Storage\BufferedRecord;
use Hexis\ErrorDigestBundle\Storage\DbalWriter;
use Hexis\ErrorDigestBundle\Tests\Integration\SchemaFactory;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class DbalWriterTest extends TestCase
{
    private Connection $connection;
    private DbalWriter $writer;

    protected function setUp(): void
    {
        $this->connection = SchemaFactory::create();
        $this->writer = new DbalWriter(
            connection: $this->connection,
            scrubber: new DefaultPiiScrubber(),
            fingerprintTable: SchemaFactory::FINGERPRINT_TABLE,
            occurrenceTable: SchemaFactory::OCCURRENCE_TABLE,
        );
    }

    public function testInsertsFingerprintAndOccurrenceRows(): void
    {
        $record = $this->record(Level::Error, new \RuntimeException('boom'));
        $buffered = new BufferedRecord($record);
        $buffered->addOccurrence($record, rateLimitSeconds: 0);

        $this->writer->flush(['abc' => $buffered], 'test');

        self::assertSame(1, $this->fingerprintCount());
        self::assertSame(1, $this->occurrenceCount());

        $row = $this->connection->fetchAssociative('SELECT * FROM err_fingerprint');
        self::assertSame('abc', $row['fingerprint']);
        self::assertSame('error', $row['level_name']);
        self::assertSame(1, (int) $row['occurrence_count']);
        self::assertSame('RuntimeException', $row['exception_class']);
    }

    public function testRepeatedFlushesUpsertAndIncrementCount(): void
    {
        $this->emit('abc', Level::Warning, 2);
        $this->emit('abc', Level::Warning, 5);

        $row = $this->connection->fetchAssociative('SELECT * FROM err_fingerprint');
        self::assertSame(7, (int) $row['occurrence_count']);
        self::assertSame(1, $this->fingerprintCount());
    }

    public function testHigherSeverityUpgradesLevelNameButKeepsFingerprint(): void
    {
        $this->emit('abc', Level::Warning, 3);
        $this->emit('abc', Level::Critical, 1);
        $this->emit('abc', Level::Error, 2);

        $row = $this->connection->fetchAssociative('SELECT * FROM err_fingerprint');
        self::assertSame((int) Level::Critical->value, (int) $row['level']);
        self::assertSame('critical', $row['level_name']);
        self::assertSame(6, (int) $row['occurrence_count']);
    }

    public function testDistinctFingerprintsGetDistinctRows(): void
    {
        $this->emit('abc', Level::Error, 1);
        $this->emit('def', Level::Error, 1);

        self::assertSame(2, $this->fingerprintCount());
    }

    public function testScrubberAppliedToOccurrenceContext(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'boom',
            context: [
                'exception' => new \RuntimeException('boom'),
                'password' => 'hunter2',
                'user_id' => 42,
            ],
        );
        $buffered = new BufferedRecord($record);
        $buffered->addOccurrence($record, rateLimitSeconds: 0);

        $this->writer->flush(['xyz' => $buffered], 'test');

        $stored = json_decode(
            $this->connection->fetchOne('SELECT context_json FROM err_occurrence'),
            true,
        );
        self::assertSame('[REDACTED]', $stored['password']);
        self::assertSame(42, $stored['user_id']);
        self::assertArrayNotHasKey('exception', $stored, 'exception object is stripped before storage');
    }

    public function testPruneDeletesOccurrencesOlderThanThreshold(): void
    {
        $this->emit('abc', Level::Error, 2);

        $this->connection->executeStatement(
            "UPDATE err_occurrence SET occurred_at = '2020-01-01 00:00:00' WHERE id = 1"
        );

        $deleted = $this->writer->prune(new \DateTimeImmutable('2025-01-01'));
        self::assertSame(1, $deleted);
        self::assertSame(1, $this->occurrenceCount());
    }

    private function emit(string $fingerprint, Level $level, int $occurrences): void
    {
        $record = $this->record($level, new \RuntimeException('boom'));
        $buffered = new BufferedRecord($record);
        for ($i = 0; $i < $occurrences; $i++) {
            $buffered->addOccurrence($record, rateLimitSeconds: 0);
        }

        $this->writer->flush([$fingerprint => $buffered], 'test');
    }

    private function record(Level $level, \Throwable $exception): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: $level,
            message: $exception->getMessage(),
            context: ['exception' => $exception],
        );
    }

    private function fingerprintCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM err_fingerprint');
    }

    private function occurrenceCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM err_occurrence');
    }
}
