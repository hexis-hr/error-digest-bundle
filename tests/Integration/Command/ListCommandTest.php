<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Integration\Command;

use Doctrine\DBAL\Connection;
use Hexis\ErrorDigestBundle\Command\ListCommand;
use Hexis\ErrorDigestBundle\Domain\DefaultPiiScrubber;
use Hexis\ErrorDigestBundle\Storage\FingerprintReader;
use Hexis\ErrorDigestBundle\Tests\Integration\SchemaFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ListCommandTest extends TestCase
{
    private Connection $connection;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->connection = SchemaFactory::create();
        $reader = new FingerprintReader(
            connection: $this->connection,
            fingerprintTable: SchemaFactory::FINGERPRINT_TABLE,
            occurrenceTable: SchemaFactory::OCCURRENCE_TABLE,
        );
        $this->tester = new CommandTester(new ListCommand($reader, new DefaultPiiScrubber()));
    }

    public function testListsOpenFingerprintsByDefaultMostRecentFirst(): void
    {
        $this->seedFingerprint('older', lastSeenAt: '-2 hours');
        $this->seedFingerprint('newer', lastSeenAt: '-5 minutes', occurrenceCount: 7);
        $this->seedFingerprint('done', lastSeenAt: '-1 minute', status: 'resolved');
        $this->seedFingerprint('quiet', lastSeenAt: '-1 minute', status: 'muted');

        $json = $this->listJson([]);

        self::assertSame(['status' => 'open', 'since' => null, 'limit' => 50], $json['filters']);
        self::assertSame(2, $json['total']);
        self::assertSame(['newer', 'older'], array_column($json['items'], 'fingerprint'));

        $item = $json['items'][0];
        self::assertSame('open', $item['status']);
        self::assertSame('error', $item['level']);
        self::assertSame('app', $item['channel']);
        self::assertSame('RuntimeException', $item['exception_class']);
        self::assertSame('seed message for newer', $item['message']);
        self::assertSame(7, $item['occurrence_count']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $item['first_seen_at']));
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $item['last_seen_at']));
        self::assertNull($item['latest_occurrence']);
    }

    public function testFiltersByStatusSinceAndLimit(): void
    {
        $this->seedFingerprint('open-old', lastSeenAt: '-3 days');
        $this->seedFingerprint('open-recent', lastSeenAt: '-1 hour');
        $this->seedFingerprint('resolved-recent', lastSeenAt: '-30 minutes', status: 'resolved');

        self::assertSame(['resolved-recent'], array_column($this->listJson(['--status' => 'resolved'])['items'], 'fingerprint'));
        self::assertSame(['open-recent'], array_column($this->listJson(['--since' => '1d'])['items'], 'fingerprint'));
        self::assertSame(
            ['resolved-recent', 'open-recent'],
            array_column($this->listJson(['--status' => 'all', '--since' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s')])['items'], 'fingerprint'),
        );

        $limited = $this->listJson(['--status' => 'all', '--limit' => '1']);
        self::assertSame(3, $limited['total']);
        self::assertSame(['resolved-recent'], array_column($limited['items'], 'fingerprint'));
    }

    public function testIncludesLatestOccurrenceWithoutPersonalData(): void
    {
        $id = $this->seedFingerprint('abc', lastSeenAt: '-1 minute', message: 'Upstream rejected Bearer abc.def-123');
        $this->seedOccurrence($id, '-1 hour', uri: '/old', trace: 'old trace');
        $this->seedOccurrence(
            $id,
            '-1 minute',
            uri: '/partners/42?email=jane@example.com&token=s3cret#frag',
            trace: "RuntimeException: boom\n  at /app/src/Foo.php:12",
            context: ['release' => '1.4.2', 'email' => 'jane@example.com'],
            userRef: 'jane@example.com',
        );

        $item = $this->listJson([])['items'][0];
        $latest = $item['latest_occurrence'];
        unset($latest['occurred_at']);

        self::assertSame('Upstream rejected Bearer [REDACTED]', $item['message']);
        self::assertSame([
            'method' => 'GET',
            'request_uri' => '/partners/42',
            'release' => '1.4.2',
            'trace_preview' => "RuntimeException: boom\n  at /app/src/Foo.php:12",
        ], $latest);
        self::assertStringNotContainsString('jane@example.com', $this->tester->getDisplay());
        self::assertStringNotContainsString('s3cret', $this->tester->getDisplay());
    }

    public function testRejectsInvalidOptions(): void
    {
        foreach ([['--status' => 'closed'], ['--limit' => '0'], ['--since' => 'last tuesday']] as $options) {
            self::assertSame(Command::INVALID, $this->tester->execute($options, ['capture_stderr_separately' => true]));
            self::assertSame('', $this->tester->getDisplay());
            self::assertStringContainsString(array_key_first($options), $this->tester->getErrorOutput());
        }
    }

    /**
     * @param array<string, string> $options
     * @return array<string, mixed>
     */
    private function listJson(array $options): array
    {
        self::assertSame(Command::SUCCESS, $this->tester->execute($options, ['capture_stderr_separately' => true]));

        return json_decode($this->tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function seedFingerprint(
        string $fingerprint,
        string $lastSeenAt,
        string $status = 'open',
        int $occurrenceCount = 1,
        ?string $message = null,
    ): int {
        $this->connection->executeStatement(
            'INSERT INTO err_fingerprint (fingerprint, level, level_name, message, exception_class, file, line, channel, environment, first_seen_at, last_seen_at, occurrence_count, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fingerprint,
                400,
                'error',
                $message ?? 'seed message for ' . $fingerprint,
                'RuntimeException',
                '/app/src/Foo.php',
                12,
                'app',
                'test',
                (new \DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s'),
                (new \DateTimeImmutable($lastSeenAt))->format('Y-m-d H:i:s'),
                $occurrenceCount,
                $status,
            ],
        );

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function seedOccurrence(int $fingerprintId, string $occurredAt, string $uri, string $trace, array $context = [], ?string $userRef = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO err_occurrence (fingerprint_id, occurred_at, context_json, request_uri, method, user_ref, trace_preview) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$fingerprintId, (new \DateTimeImmutable($occurredAt))->format('Y-m-d H:i:s'), json_encode($context), $uri, 'GET', $userRef, $trace],
        );
    }
}
