<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Integration\Js;

use Doctrine\DBAL\Connection;
use Hexis\ErrorDigestBundle\Domain\DefaultPiiScrubber;
use Hexis\ErrorDigestBundle\Js\JsEvent;
use Hexis\ErrorDigestBundle\Js\JsFingerprinter;
use Hexis\ErrorDigestBundle\Js\JsIngester;
use Hexis\ErrorDigestBundle\Tests\Integration\SchemaFactory;
use PHPUnit\Framework\TestCase;

final class JsIngesterTest extends TestCase
{
    private Connection $connection;
    private JsIngester $ingester;

    protected function setUp(): void
    {
        $this->connection = SchemaFactory::create();
        $this->ingester = new JsIngester(
            connection: $this->connection,
            fingerprinter: new JsFingerprinter(),
            scrubber: new DefaultPiiScrubber(),
            fingerprintTable: SchemaFactory::FINGERPRINT_TABLE,
            occurrenceTable: SchemaFactory::OCCURRENCE_TABLE,
        );
    }

    public function testRepeatedReportsShareOneFingerprintRow(): void
    {
        $this->ingester->ingest($this->event(new \DateTimeImmutable('2026-10-15 10:00:00')), 'prod');
        $this->ingester->ingest($this->event(new \DateTimeImmutable('2026-10-15 11:00:00')), 'prod');
        $this->ingester->ingest($this->event(new \DateTimeImmutable('2026-10-15 09:00:00')), 'prod');

        $row = $this->connection->fetchAssociative('SELECT * FROM err_fingerprint');
        self::assertSame('js', $row['channel']);
        self::assertSame(3, (int) $row['occurrence_count']);
        self::assertSame('2026-10-15 10:00:00', $row['first_seen_at']);
        self::assertSame('2026-10-15 11:00:00', $row['last_seen_at'], 'An older report does not move last_seen_at back.');
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM err_occurrence'));
    }

    private function event(\DateTimeImmutable $occurredAt): JsEvent
    {
        return new JsEvent(
            message: 'TypeError: x is undefined',
            exceptionType: 'TypeError',
            sourceUrl: 'https://example.com/build/app.js',
            line: 10,
            column: 5,
            stack: "TypeError: x is undefined\n    at render (https://example.com/build/app.js:10:5)",
            pageUrl: 'https://example.com/partners',
            userAgent: 'Mozilla/5.0',
            release: 'abc123',
            userRef: '42',
            occurredAt: $occurredAt,
        );
    }
}
