<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Integration\Storage;

use Doctrine\DBAL\Connection;
use Hexis\ErrorDigestBundle\Domain\DefaultPiiScrubber;
use Hexis\ErrorDigestBundle\Domain\PiiScrubber;
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
            connectionFactory: fn () => $this->connection,
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

    public function testARecurringResolvedFingerprintIsOpenAgainAndKeepsItsAssignee(): void
    {
        $this->emit('abc', Level::Error, 1);
        $this->connection->executeStatement("UPDATE err_fingerprint SET status = 'resolved', resolved_at = '2026-10-01 08:00:00', assignee_id = 'ivan'");

        $this->emit('abc', Level::Error, 1);

        $row = $this->connection->fetchAssociative('SELECT * FROM err_fingerprint');
        self::assertSame('open', $row['status']);
        self::assertNull($row['resolved_at']);
        self::assertSame('ivan', $row['assignee_id']);
        self::assertSame(2, (int) $row['occurrence_count']);
    }

    public function testARecurringMutedFingerprintStaysMuted(): void
    {
        $this->emit('abc', Level::Error, 1);
        $this->connection->executeStatement("UPDATE err_fingerprint SET status = 'muted', assignee_id = 'ivan'");

        $this->emit('abc', Level::Error, 1);

        $row = $this->connection->fetchAssociative('SELECT * FROM err_fingerprint');
        self::assertSame('muted', $row['status']);
        self::assertSame('ivan', $row['assignee_id']);
        self::assertSame(2, (int) $row['occurrence_count']);
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

    public function testScrubsTheMessageTraceAndRequestUriBeforeStorage(): void
    {
        $exception = new \RuntimeException('Partner jane@example.com rejected Bearer abc.def-123');
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'request',
            level: Level::Error,
            message: 'Uncaught exception',
            context: ['exception' => $exception],
            extra: [
                'url' => '/partners/jane@example.com/edit?token=abc#section',
                'http_method' => 'POST',
                'referrer' => 'https://example.com/login?next=%2Fpartners',
            ],
        );
        $buffered = new BufferedRecord($record);
        $buffered->addOccurrence($record, rateLimitSeconds: 0);

        $this->writer->flush(['abc' => $buffered], 'test');

        self::assertSame('Partner [REDACTED] rejected Bearer [REDACTED]', $this->connection->fetchOne('SELECT message FROM err_fingerprint'));
        $occurrence = $this->connection->fetchAssociative('SELECT * FROM err_occurrence');
        self::assertStringStartsWith('RuntimeException: Partner [REDACTED] rejected Bearer [REDACTED]', $occurrence['trace_preview']);
        self::assertSame('/partners/[REDACTED]/edit', $occurrence['request_uri']);
        self::assertSame('POST', $occurrence['method']);
        $extra = json_decode($occurrence['context_json'], true)['_extra'];
        self::assertSame('/partners/[REDACTED]/edit', $extra['url']);
        self::assertSame('https://example.com/login', $extra['referrer']);
        self::assertStringNotContainsString('jane@example.com', implode(' ', $occurrence));
    }

    public function testKeepsContextObjectsByClassNameOnlyWhateverTheScrubberReturns(): void
    {
        $writer = new DbalWriter(
            connectionFactory: fn () => $this->connection,
            scrubber: new class implements PiiScrubber {
                public function scrub(array $context): array
                {
                    return $context;
                }
            },
            fingerprintTable: SchemaFactory::FINGERPRINT_TABLE,
            occurrenceTable: SchemaFactory::OCCURRENCE_TABLE,
        );
        $partner = new class {
            public string $email = 'jane@example.com';
        };
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'boom',
            context: ['partner' => $partner, 'nested' => ['partners' => [$partner]]],
        );
        $buffered = new BufferedRecord($record);
        $buffered->addOccurrence($record, rateLimitSeconds: 0);

        $writer->flush(['abc' => $buffered], 'test');

        $stored = json_decode($this->connection->fetchOne('SELECT context_json FROM err_occurrence'), true);
        self::assertSame('[object ' . $partner::class . ']', $stored['partner']);
        self::assertSame(['partners' => ['[object ' . $partner::class . ']']], $stored['nested']);
    }

    public function testStoresRedactedWhenTheScrubberDropsAText(): void
    {
        $writer = new DbalWriter(
            connectionFactory: fn () => $this->connection,
            scrubber: new class implements PiiScrubber {
                public function scrub(array $context): array
                {
                    return [];
                }
            },
            fingerprintTable: SchemaFactory::FINGERPRINT_TABLE,
            occurrenceTable: SchemaFactory::OCCURRENCE_TABLE,
        );
        $record = $this->record(Level::Error, new \RuntimeException('Partner jane@example.com rejected'));
        $buffered = new BufferedRecord($record);
        $buffered->addOccurrence($record, rateLimitSeconds: 0);

        $writer->flush(['abc' => $buffered], 'test');

        self::assertSame('[REDACTED]', $this->connection->fetchOne('SELECT message FROM err_fingerprint'));
        self::assertSame('[REDACTED]', $this->connection->fetchOne('SELECT trace_preview FROM err_occurrence'));
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
