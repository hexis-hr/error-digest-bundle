<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Unit\Storage;

use Hexis\ErrorDigestBundle\Storage\BufferedRecord;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class BufferedRecordTest extends TestCase
{
    public function testFirstOccurrenceIsAlwaysWritten(): void
    {
        $buffered = new BufferedRecord($this->recordAt('2026-04-21 10:00:00'));
        $buffered->addOccurrence($this->recordAt('2026-04-21 10:00:00'), rateLimitSeconds: 60);

        self::assertSame(1, $buffered->count);
        self::assertCount(1, $buffered->occurrences);
    }

    public function testOccurrencesWithinRateLimitWindowAreCollapsed(): void
    {
        $buffered = new BufferedRecord($this->recordAt('2026-04-21 10:00:00'));
        $buffered->addOccurrence($this->recordAt('2026-04-21 10:00:00'), rateLimitSeconds: 60);
        $buffered->addOccurrence($this->recordAt('2026-04-21 10:00:30'), rateLimitSeconds: 60);
        $buffered->addOccurrence($this->recordAt('2026-04-21 10:00:45'), rateLimitSeconds: 60);

        self::assertSame(3, $buffered->count);
        self::assertCount(1, $buffered->occurrences, 'all three should collapse into one occurrence row');
    }

    public function testOccurrenceIsWrittenOnceWindowExpires(): void
    {
        $buffered = new BufferedRecord($this->recordAt('2026-04-21 10:00:00'));
        $buffered->addOccurrence($this->recordAt('2026-04-21 10:00:00'), rateLimitSeconds: 60);
        $buffered->addOccurrence($this->recordAt('2026-04-21 10:01:05'), rateLimitSeconds: 60);

        self::assertSame(2, $buffered->count);
        self::assertCount(2, $buffered->occurrences);
    }

    public function testRateLimitZeroWritesEveryOccurrence(): void
    {
        $buffered = new BufferedRecord($this->recordAt('2026-04-21 10:00:00'));
        $buffered->addOccurrence($this->recordAt('2026-04-21 10:00:00'), rateLimitSeconds: 0);
        $buffered->addOccurrence($this->recordAt('2026-04-21 10:00:00'), rateLimitSeconds: 0);
        $buffered->addOccurrence($this->recordAt('2026-04-21 10:00:00'), rateLimitSeconds: 0);

        self::assertSame(3, $buffered->count);
        self::assertCount(3, $buffered->occurrences);
    }

    public function testLastSeenTracksLatestTimestamp(): void
    {
        $buffered = new BufferedRecord($this->recordAt('2026-04-21 10:00:00'));
        $buffered->addOccurrence($this->recordAt('2026-04-21 10:00:00'), rateLimitSeconds: 60);
        $buffered->addOccurrence($this->recordAt('2026-04-21 10:01:05'), rateLimitSeconds: 60);

        self::assertSame('2026-04-21 10:01:05', $buffered->lastSeenAt->format('Y-m-d H:i:s'));
    }

    private function recordAt(string $datetime): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable($datetime),
            channel: 'app',
            level: Level::Error,
            message: 'test',
        );
    }
}
