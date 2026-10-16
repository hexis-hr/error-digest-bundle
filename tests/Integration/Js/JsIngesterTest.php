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

    public function testARecurringResolvedFingerprintIsOpenAgainAndKeepsItsAssignee(): void
    {
        $this->ingester->ingest($this->event(new \DateTimeImmutable()), 'prod');
        $this->connection->executeStatement("UPDATE err_fingerprint SET status = 'resolved', resolved_at = '2026-10-01 08:00:00', assignee_id = 'ivan'");

        $this->ingester->ingest($this->event(new \DateTimeImmutable()), 'prod');

        $row = $this->connection->fetchAssociative('SELECT * FROM err_fingerprint');
        self::assertSame('open', $row['status']);
        self::assertNull($row['resolved_at']);
        self::assertSame('ivan', $row['assignee_id']);
    }

    public function testARecurringMutedFingerprintStaysMuted(): void
    {
        $this->ingester->ingest($this->event(new \DateTimeImmutable()), 'prod');
        $this->connection->executeStatement("UPDATE err_fingerprint SET status = 'muted', assignee_id = 'ivan'");

        $this->ingester->ingest($this->event(new \DateTimeImmutable()), 'prod');

        $row = $this->connection->fetchAssociative('SELECT * FROM err_fingerprint');
        self::assertSame('muted', $row['status']);
        self::assertSame('ivan', $row['assignee_id']);
    }

    public function testScrubsWhatTheBrowserSendsBeforeStorage(): void
    {
        $this->ingester->ingest(new JsEvent(
            message: 'Cannot save jane@example.com',
            exceptionType: 'Error',
            sourceUrl: 'https://example.com/build/app.js?signature=abc',
            line: 10,
            column: 5,
            stack: "Error: Cannot save jane@example.com\n    at save (https://example.com/build/app.js?signature=abc:10:5)",
            pageUrl: 'https://example.com/reset-password?token=abc#form',
            userAgent: 'Mozilla/5.0',
            release: null,
            userRef: 'jane@example.com',
            occurredAt: new \DateTimeImmutable(),
        ), 'prod');

        $fingerprint = $this->connection->fetchAssociative('SELECT * FROM err_fingerprint');
        self::assertSame('Cannot save [REDACTED]', $fingerprint['message']);
        self::assertSame('https://example.com/build/app.js', $fingerprint['file']);

        $occurrence = $this->connection->fetchAssociative('SELECT * FROM err_occurrence');
        self::assertSame("Error: Cannot save [REDACTED]\n    at save (https://example.com/build/app.js?[REDACTED]", $occurrence['trace_preview']);
        self::assertSame('https://example.com/reset-password', $occurrence['request_uri']);
        self::assertSame('[REDACTED]', $occurrence['user_ref']);
        self::assertSame('https://example.com/build/app.js', json_decode($occurrence['context_json'], true)['source_url']);
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
