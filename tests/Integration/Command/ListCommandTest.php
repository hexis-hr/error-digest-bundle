<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Integration\Command;

use Doctrine\DBAL\Connection;
use Hexis\ErrorDigestBundle\Command\ListCommand;
use Hexis\ErrorDigestBundle\Domain\DefaultPiiScrubber;
use Hexis\ErrorDigestBundle\Storage\DbalWriter;
use Hexis\ErrorDigestBundle\Storage\FingerprintReader;
use Hexis\ErrorDigestBundle\Tests\Integration\SchemaFactory;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('invalidOptions')]
    public function testRejectsInvalidOptions(array $options): void
    {
        $this->seedFingerprint('abc', lastSeenAt: '-1 minute');

        self::assertSame(Command::INVALID, $this->tester->execute($options, ['capture_stderr_separately' => true]));
        self::assertSame('', $this->tester->getDisplay());
        self::assertStringContainsString(array_key_first($options), $this->tester->getErrorOutput());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'unknown status' => [['--status' => 'closed']];
        yield 'empty status' => [['--status' => '']];
        yield 'zero limit' => [['--limit' => '0']];
        yield 'negative limit' => [['--limit' => '-5']];
        yield 'non-numeric limit' => [['--limit' => 'abc']];
        yield 'fractional limit' => [['--limit' => '2.5']];
        yield 'free-text since' => [['--since' => 'last tuesday']];
        yield 'unknown since unit' => [['--since' => '2w']];
        yield 'impossible since date' => [['--since' => '2026-02-30']];
        yield 'impossible since time' => [['--since' => '2026-01-10 25:00:00']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('statusFilters')]
    public function testFiltersByEachStatus(string $status, string $normalized, array $expected): void
    {
        $this->seedFingerprint('open', lastSeenAt: '-3 minutes');
        $this->seedFingerprint('resolved', lastSeenAt: '-2 minutes', status: 'resolved');
        $this->seedFingerprint('muted', lastSeenAt: '-1 minute', status: 'muted');

        $json = $this->listJson(['--status' => $status]);

        self::assertSame($normalized, $json['filters']['status']);
        self::assertSame(\count($expected), $json['total']);
        self::assertSame($expected, array_column($json['items'], 'fingerprint'));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function statusFilters(): iterable
    {
        yield 'open' => ['open', 'open', ['open']];
        yield 'resolved' => ['resolved', 'resolved', ['resolved']];
        yield 'muted' => ['muted', 'muted', ['muted']];
        yield 'all, most recently seen first' => ['all', 'all', ['muted', 'resolved', 'open']];
        yield 'upper case' => ['RESOLVED', 'resolved', ['resolved']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('relativeSince')]
    public function testSinceTakesARelativeDurationOnLastSeen(string $since, string $modifier, array $expected): void
    {
        $this->seedFingerprint('eight-days', lastSeenAt: '-8 days');
        $this->seedFingerprint('six-days', lastSeenAt: '-6 days');
        $this->seedFingerprint('thirteen-hours', lastSeenAt: '-13 hours');
        $this->seedFingerprint('eleven-hours', lastSeenAt: '-11 hours');
        $this->seedFingerprint('two-hours', lastSeenAt: '-2 hours');
        $this->seedFingerprint('one-hour', lastSeenAt: '-1 hour');

        $json = $this->listJson(['--since' => $since]);

        self::assertSame($expected, array_column($json['items'], 'fingerprint'));
        self::assertEqualsWithDelta(
            (new \DateTimeImmutable($modifier))->getTimestamp(),
            (new \DateTimeImmutable($json['filters']['since']))->getTimestamp(),
            60,
        );
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function relativeSince(): iterable
    {
        yield 'days' => ['7d', '-7 days', ['one-hour', 'two-hours', 'eleven-hours', 'thirteen-hours', 'six-days']];
        yield 'hours' => ['12h', '-12 hours', ['one-hour', 'two-hours', 'eleven-hours']];
        yield 'minutes' => ['90m', '-90 minutes', ['one-hour']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('absoluteSince')]
    public function testSinceTakesAnAbsoluteDateOrTimeInclusively(string $since, string $expectedSince, array $expected): void
    {
        $this->seedFingerprint('before', lastSeenAt: '2026-01-09 23:59:59');
        $this->seedFingerprint('midnight', lastSeenAt: '2026-01-10 00:00:00');
        $this->seedFingerprint('noon', lastSeenAt: '2026-01-10 12:00:00');
        $this->seedFingerprint('next-day', lastSeenAt: '2026-01-11 08:00:00');

        $json = $this->listJson(['--since' => $since]);

        self::assertSame((new \DateTimeImmutable($expectedSince))->format(\DateTimeInterface::ATOM), $json['filters']['since']);
        self::assertSame($expected, array_column($json['items'], 'fingerprint'));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function absoluteSince(): iterable
    {
        yield 'date means midnight' => ['2026-01-10', '2026-01-10 00:00:00', ['next-day', 'noon', 'midnight']];
        yield 'date and time, boundary included' => ['2026-01-10 12:00:00', '2026-01-10 12:00:00', ['next-day', 'noon']];
        yield 'date and time, one second later' => ['2026-01-10 12:00:01', '2026-01-10 12:00:01', ['next-day']];
    }

    public function testScrubsMessageTraceAndUriAndCutsTheFragment(): void
    {
        $id = $this->seedFingerprint('abc', lastSeenAt: '-1 minute', message: 'Card 4111 1111 1111 1111 declined');
        $this->seedOccurrence(
            $id,
            '-1 minute',
            uri: '/reset/eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI0MiJ9.c2lnbmF0dXJl#access_token=s3cret',
            trace: "RuntimeException: Upstream rejected Bearer abc.def-123\n  at /app/src/Foo.php:12",
        );

        $item = $this->listJson([])['items'][0];

        self::assertSame('Card [REDACTED] declined', $item['message']);
        self::assertSame('/reset/[REDACTED]', $item['latest_occurrence']['request_uri']);
        self::assertSame("RuntimeException: Upstream rejected Bearer [REDACTED]\n  at /app/src/Foo.php:12", $item['latest_occurrence']['trace_preview']);
        foreach (['4111', 'eyJ', 's3cret', 'abc.def-123'] as $secret) {
            self::assertStringNotContainsString($secret, $this->tester->getDisplay());
        }
    }

    public function testLatestOccurrenceIsNullOncePrunedWhileTheFingerprintStays(): void
    {
        $id = $this->seedFingerprint('abc', lastSeenAt: '-1 minute', occurrenceCount: 4);
        $this->seedOccurrence($id, '-2 minutes', uri: '/a', trace: 'trace');
        $this->seedOccurrence($id, '-1 minute', uri: '/b', trace: 'trace');

        $writer = new DbalWriter(
            connectionFactory: fn () => $this->connection,
            scrubber: new DefaultPiiScrubber(),
            fingerprintTable: SchemaFactory::FINGERPRINT_TABLE,
            occurrenceTable: SchemaFactory::OCCURRENCE_TABLE,
        );
        self::assertSame(2, $writer->prune(new \DateTimeImmutable('+1 minute')));

        $json = $this->listJson([]);

        self::assertSame(1, $json['total']);
        self::assertSame(4, $json['items'][0]['occurrence_count']);
        self::assertNull($json['items'][0]['latest_occurrence']);
    }

    /**
     * @param array<string, mixed> $latestContext
     */
    #[DataProvider('releases')]
    public function testReleaseIsTakenFromTheLatestOccurrenceContext(array $latestContext, ?string $expected): void
    {
        $id = $this->seedFingerprint('abc', lastSeenAt: '-1 minute');
        $this->seedOccurrence($id, '-1 hour', uri: '/old', trace: 'trace', context: ['release' => '1.0.0']);
        $this->seedOccurrence($id, '-1 minute', uri: '/new', trace: 'trace', context: $latestContext);

        self::assertSame($expected, $this->listJson([])['items'][0]['latest_occurrence']['release']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?string}>
     */
    public static function releases(): iterable
    {
        yield 'string release' => [['release' => '2.0.0'], '2.0.0'];
        yield 'no release' => [['source_url' => '/app.js'], null];
        yield 'non-string release' => [['release' => 7], null];
    }

    public function testLatestOccurrenceIsTheLastInsertedWhenTimestampsTie(): void
    {
        $id = $this->seedFingerprint('abc', lastSeenAt: '-1 minute');
        $this->seedOccurrence($id, '2026-01-10 12:00:00', uri: '/first', trace: 'trace');
        $this->seedOccurrence($id, '2026-01-10 12:00:00', uri: '/second', trace: 'trace');

        self::assertSame('/second', $this->listJson([])['items'][0]['latest_occurrence']['request_uri']);
    }

    #[DataProvider('decoration')]
    public function testConsoleTagsInMessagesAreWrittenVerbatim(bool $decorated): void
    {
        $id = $this->seedFingerprint('abc', lastSeenAt: '-1 minute', message: 'Template <info>name</info> failed');
        $this->seedOccurrence($id, '-1 minute', uri: '/a', trace: 'RuntimeException: <error>boom</error>');

        self::assertSame(Command::SUCCESS, $this->tester->execute([], ['capture_stderr_separately' => true, 'decorated' => $decorated]));
        $item = json_decode($this->tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR)['items'][0];

        self::assertSame('Template <info>name</info> failed', $item['message']);
        self::assertSame('RuntimeException: <error>boom</error>', $item['latest_occurrence']['trace_preview']);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function decoration(): iterable
    {
        yield 'redirected' => [false];
        yield 'terminal' => [true];
    }

    public function testInvalidUtf8IsSubstitutedInsteadOfFailingTheListing(): void
    {
        $id = $this->seedFingerprint('abc', lastSeenAt: '-1 minute', message: "Bad byte \xB1 in input");
        $this->seedOccurrence($id, '-1 minute', uri: '/a', trace: "RuntimeException: Bad byte \xB1 in input");

        $item = $this->listJson([])['items'][0];

        self::assertSame("Bad byte \u{FFFD} in input", $item['message']);
        self::assertSame("RuntimeException: Bad byte \u{FFFD} in input", $item['latest_occurrence']['trace_preview']);
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
