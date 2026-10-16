<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Integration\Storage;

use Doctrine\DBAL\Connection;
use Hexis\ErrorDigestBundle\Digest\FingerprintSummary;
use Hexis\ErrorDigestBundle\Storage\FingerprintReader;
use Hexis\ErrorDigestBundle\Tests\Integration\SchemaFactory;
use PHPUnit\Framework\TestCase;

final class FingerprintReaderTest extends TestCase
{
    private Connection $connection;
    private FingerprintReader $reader;
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->connection = SchemaFactory::create();
        $this->reader = new FingerprintReader(
            connection: $this->connection,
            fingerprintTable: SchemaFactory::FINGERPRINT_TABLE,
            occurrenceTable: SchemaFactory::OCCURRENCE_TABLE,
        );

        $this->seed('before', '2026-01-10 11:59:59', 'open');
        $this->seed('boundary', '2026-01-10 12:00:00', 'open');
        $this->seed('after-resolved', '2026-01-10 13:00:00', 'resolved');
        $this->seed('latest', '2026-01-11 08:00:00', 'open');
    }

    public function testSinceKeepsFingerprintsLastSeenAtOrAfterIt(): void
    {
        $result = $this->reader->list(['since' => new \DateTimeImmutable('2026-01-10 12:00:00')], 50, 0);

        self::assertSame(3, $result['total']);
        self::assertSame(['latest', 'after-resolved', 'boundary'], $this->fingerprints($result['rows']));
    }

    public function testSinceCombinesWithStatusAndTotalIgnoresLimitAndOffset(): void
    {
        $filters = ['status' => 'open', 'since' => new \DateTimeImmutable('2026-01-10 12:00:00')];

        $first = $this->reader->list($filters, 1, 0);
        $second = $this->reader->list($filters, 1, 1);

        self::assertSame(2, $first['total']);
        self::assertSame(2, $second['total']);
        self::assertSame(['latest'], $this->fingerprints($first['rows']));
        self::assertSame(['boundary'], $this->fingerprints($second['rows']));
    }

    public function testNullSinceDoesNotFilter(): void
    {
        $result = $this->reader->list(['since' => null], 50, 0);

        self::assertSame(4, $result['total']);
        self::assertSame(['latest', 'after-resolved', 'boundary', 'before'], $this->fingerprints($result['rows']));
    }

    public function testLatestOccurrencesTakesEachFingerprintsLatestAndBreaksTiesById(): void
    {
        $later = $this->seedOccurrence('before', '2026-01-10 11:59:59');
        $this->seedOccurrence('before', '2026-01-10 11:00:00');
        $this->seedOccurrence('boundary', '2026-01-10 12:00:00');
        $tieInsertedLast = $this->seedOccurrence('boundary', '2026-01-10 12:00:00');
        $this->seedOccurrence('latest', '2026-01-11 08:00:00');

        $latest = $this->reader->latestOccurrences([$this->ids['before'], $this->ids['boundary'], $this->ids['after-resolved']]);
        ksort($latest);

        self::assertSame(
            [$this->ids['before'] => $later, $this->ids['boundary'] => $tieInsertedLast],
            array_map(static fn (array $occurrence): int => $occurrence['id'], $latest),
        );
        self::assertSame([], $this->reader->latestOccurrences([]));
    }

    /**
     * @param list<FingerprintSummary> $rows
     * @return list<string>
     */
    private function fingerprints(array $rows): array
    {
        return array_map(static fn (FingerprintSummary $row): string => $row->fingerprint, $rows);
    }

    private function seed(string $fingerprint, string $lastSeenAt, string $status): void
    {
        $this->connection->executeStatement(
            'INSERT INTO err_fingerprint (fingerprint, level, level_name, message, channel, environment, first_seen_at, last_seen_at, occurrence_count, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$fingerprint, 400, 'error', 'seed message for ' . $fingerprint, 'app', 'test', '2026-01-01 00:00:00', $lastSeenAt, 1, $status],
        );
        $this->ids[$fingerprint] = (int) $this->connection->lastInsertId();
    }

    private function seedOccurrence(string $fingerprint, string $occurredAt): int
    {
        $this->connection->executeStatement(
            'INSERT INTO err_occurrence (fingerprint_id, occurred_at, context_json) VALUES (?, ?, ?)',
            [$this->ids[$fingerprint], $occurredAt, '{}'],
        );

        return (int) $this->connection->lastInsertId();
    }
}
