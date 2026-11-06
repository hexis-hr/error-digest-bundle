<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Integration\Digest;

use Doctrine\DBAL\Connection;
use Hexis\ErrorDigestBundle\Digest\DigestBuilder;
use Hexis\ErrorDigestBundle\Digest\DigestPayload;
use Hexis\ErrorDigestBundle\Domain\DefaultPiiScrubber;
use Hexis\ErrorDigestBundle\Storage\BufferedRecord;
use Hexis\ErrorDigestBundle\Storage\DbalWriter;
use Hexis\ErrorDigestBundle\Tests\Integration\SchemaFactory;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class DigestBuilderTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SchemaFactory::create();
    }

    public function testNewSectionContainsOnlyFingerprintsInWindowWithoutNotifiedAt(): void
    {
        $now = new \DateTimeImmutable('2026-04-21 12:00:00');

        $freshId = $this->seedFingerprint(
            fingerprint: 'fresh',
            firstSeenAt: $now->modify('-30 minutes'),
            lastSeenAt: $now->modify('-5 minutes'),
            notifiedInDigestAt: null,
        );
        $this->seedOccurrence($freshId, $now->modify('-5 minutes'));

        $alreadyNotifiedId = $this->seedFingerprint(
            fingerprint: 'already-notified',
            firstSeenAt: $now->modify('-30 minutes'),
            lastSeenAt: $now->modify('-5 minutes'),
            notifiedInDigestAt: $now->modify('-10 minutes'),
        );
        $this->seedOccurrence($alreadyNotifiedId, $now->modify('-5 minutes'));

        $outsideWindowId = $this->seedFingerprint(
            fingerprint: 'too-old',
            firstSeenAt: $now->modify('-3 days'),
            lastSeenAt: $now->modify('-3 days'),
            notifiedInDigestAt: null,
        );
        $this->seedOccurrence($outsideWindowId, $now->modify('-3 days'));

        $payload = $this->build($now);

        self::assertCount(1, $payload->sections['new']);
        self::assertSame('fresh', $payload->sections['new'][0]->fingerprint);
    }

    public function testTopSectionOrdersByWindowOccurrenceCount(): void
    {
        $now = new \DateTimeImmutable('2026-04-21 12:00:00');

        $quietId = $this->seedFingerprint('quiet', $now->modify('-6 hours'), $now->modify('-10 minutes'));
        $noisyId = $this->seedFingerprint('noisy', $now->modify('-6 hours'), $now->modify('-5 minutes'));

        for ($i = 0; $i < 3; $i++) {
            $this->seedOccurrence($quietId, $now->modify('-' . ($i + 1) . ' hours'));
        }
        for ($i = 0; $i < 10; $i++) {
            $this->seedOccurrence($noisyId, $now->modify('-' . ($i + 1) . ' minutes'));
        }

        $payload = $this->build($now);

        self::assertCount(2, $payload->sections['top']);
        self::assertSame('noisy', $payload->sections['top'][0]->fingerprint);
        self::assertSame(10, $payload->sections['top'][0]->windowOccurrences);
        self::assertSame('quiet', $payload->sections['top'][1]->fingerprint);
    }

    public function testSpikingSectionDetectsVelocityIncrease(): void
    {
        $now = new \DateTimeImmutable('2026-04-21 12:00:00');
        $window = '1 hour';
        $priorStart = $now->modify('-2 hours');
        $windowStart = $now->modify('-1 hour');

        $spikingId = $this->seedFingerprint('spike', $now->modify('-6 hours'), $now);
        for ($i = 0; $i < 2; $i++) {
            $this->seedOccurrence($spikingId, $priorStart->modify('+' . ($i * 5) . ' minutes'));
        }
        for ($i = 0; $i < 20; $i++) {
            $this->seedOccurrence($spikingId, $windowStart->modify('+' . ($i + 1) . ' minutes'));
        }

        $steadyId = $this->seedFingerprint('steady', $now->modify('-6 hours'), $now);
        for ($i = 0; $i < 5; $i++) {
            $this->seedOccurrence($steadyId, $priorStart->modify('+' . ($i * 5) . ' minutes'));
        }
        for ($i = 0; $i < 6; $i++) {
            $this->seedOccurrence($steadyId, $windowStart->modify('+' . ($i * 5) . ' minutes'));
        }

        $payload = $this->build($now, overrideConfig: ['window' => $window, 'spike_multiplier' => 3.0]);

        self::assertCount(1, $payload->sections['spiking']);
        self::assertSame('spike', $payload->sections['spiking'][0]->fingerprint);
        self::assertGreaterThan(3.0, $payload->sections['spiking'][0]->velocityMultiplier);
    }

    public function testStaleSectionReturnsOldOpenFingerprints(): void
    {
        $now = new \DateTimeImmutable('2026-04-21 12:00:00');

        $this->seedFingerprint('old-open', $now->modify('-14 days'), $now->modify('-1 hour'));
        $this->seedFingerprint('fresh-open', $now->modify('-1 day'), $now->modify('-1 hour'));
        $this->seedFingerprint('old-resolved', $now->modify('-20 days'), $now->modify('-10 days'), status: 'resolved');

        $payload = $this->build($now, overrideConfig: ['stale_days' => 7]);

        self::assertCount(1, $payload->sections['stale']);
        self::assertSame('old-open', $payload->sections['stale'][0]->fingerprint);
    }

    public function testReopenedSectionListsOldOpenFingerprintsNoDigestHasAnnounced(): void
    {
        $now = new \DateTimeImmutable('2026-04-21 12:00:00');

        $reopenedId = $this->seedFingerprint('reopened', $now->modify('-10 days'), $now->modify('-1 hour'));
        $this->seedOccurrence($reopenedId, $now->modify('-1 hour'));
        $this->seedFingerprint('announced', $now->modify('-10 days'), $now->modify('-1 hour'), notifiedInDigestAt: $now->modify('-2 hours'));
        $this->seedFingerprint('first-seen-in-window', $now->modify('-1 hour'), $now->modify('-1 hour'));
        $this->seedFingerprint('resolved', $now->modify('-10 days'), $now->modify('-3 days'), status: 'resolved');
        $this->seedFingerprint('muted', $now->modify('-10 days'), $now->modify('-1 hour'), status: 'muted');

        $payload = $this->build($now);

        self::assertSame(['reopened'], array_map(static fn ($row) => $row->fingerprint, $payload->sections['reopened']));
        self::assertSame(1, $payload->sections['reopened'][0]->windowOccurrences);
        self::assertSame(['first-seen-in-window'], array_map(static fn ($row) => $row->fingerprint, $payload->sections['new']));
    }

    public function testAResolvedFingerprintThatOccursAgainIsAnnouncedOnce(): void
    {
        $writer = new DbalWriter(
            connectionFactory: fn () => $this->connection,
            scrubber: new DefaultPiiScrubber(),
            fingerprintTable: SchemaFactory::FINGERPRINT_TABLE,
            occurrenceTable: SchemaFactory::OCCURRENCE_TABLE,
        );
        $builder = $this->makeBuilder();
        $occur = static function (\DateTimeImmutable $at) use ($writer): void {
            $record = new LogRecord($at, 'app', Level::Error, 'boom');
            $buffered = new BufferedRecord($record);
            $buffered->addOccurrence($record, rateLimitSeconds: 0);
            $writer->flush(['abc' => $buffered], 'test');
        };

        $day1 = new \DateTimeImmutable('2026-04-21 08:00:00');
        $occur($day1->modify('-1 hour'));
        $first = $builder->build($day1);
        $builder->markNotified($first);
        self::assertSame([], $first->sections['reopened'], 'A new fingerprint is new, not reopened.');
        self::assertCount(1, $first->sections['new']);

        $this->connection->executeStatement("UPDATE err_fingerprint SET status = 'resolved', resolved_at = '2026-04-21 09:00:00'");

        $day4 = $day1->modify('+3 days');
        self::assertSame([], $builder->build($day4)->sections['reopened'], 'Still resolved, nothing to announce.');

        $occur($day4->modify('-1 hour'));
        $second = $builder->build($day4);
        $builder->markNotified($second);
        self::assertCount(1, $second->sections['reopened']);
        self::assertSame([], $second->sections['new']);

        self::assertSame([], $builder->build($day4->modify('+1 day'))->sections['reopened'], 'Announced once.');
    }

    public function testMarkNotifiedStampsAllFingerprintsInPayload(): void
    {
        $now = new \DateTimeImmutable('2026-04-21 12:00:00');
        $id = $this->seedFingerprint('abc', $now->modify('-30 minutes'), $now->modify('-5 minutes'));
        $this->seedOccurrence($id, $now->modify('-5 minutes'));

        $builder = $this->makeBuilder();
        $payload = $builder->build($now);
        $builder->markNotified($payload);

        $stored = $this->connection->fetchOne('SELECT notified_in_digest_at FROM err_fingerprint WHERE fingerprint = ?', ['abc']);
        self::assertNotNull($stored);
    }

    private function build(\DateTimeImmutable $now, array $overrideConfig = []): DigestPayload
    {
        return $this->makeBuilder($overrideConfig)->build($now);
    }

    private function makeBuilder(array $overrideConfig = []): DigestBuilder
    {
        $config = array_merge([
            'enabled' => true,
            'schedule' => '0 8 * * *',
            'recipients' => [],
            'from' => null,
            'senders' => ['mailer'],
            'window' => '24 hours',
            'sections' => ['new', 'reopened', 'spiking', 'top', 'stale'],
            'top_limit' => 10,
            'stale_days' => 7,
            'spike_multiplier' => 3.0,
        ], $overrideConfig);

        return new DigestBuilder(
            connection: $this->connection,
            fingerprintTable: SchemaFactory::FINGERPRINT_TABLE,
            occurrenceTable: SchemaFactory::OCCURRENCE_TABLE,
            digestConfig: $config,
            environment: 'test',
        );
    }

    private function seedFingerprint(
        string $fingerprint,
        \DateTimeImmutable $firstSeenAt,
        \DateTimeImmutable $lastSeenAt,
        ?\DateTimeImmutable $notifiedInDigestAt = null,
        string $status = 'open',
    ): int {
        $this->connection->executeStatement(
            'INSERT INTO err_fingerprint (fingerprint, level, level_name, message, channel, environment, first_seen_at, last_seen_at, occurrence_count, status, notified_in_digest_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fingerprint,
                400,
                'error',
                'seed message for ' . $fingerprint,
                'app',
                'test',
                $firstSeenAt->format('Y-m-d H:i:s'),
                $lastSeenAt->format('Y-m-d H:i:s'),
                0,
                $status,
                $notifiedInDigestAt?->format('Y-m-d H:i:s'),
            ],
        );

        return (int) $this->connection->lastInsertId();
    }

    private function seedOccurrence(int $fingerprintId, \DateTimeImmutable $occurredAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO err_occurrence (fingerprint_id, occurred_at, context_json) VALUES (?, ?, ?)',
            [$fingerprintId, $occurredAt->format('Y-m-d H:i:s'), '{}'],
        );
    }
}
