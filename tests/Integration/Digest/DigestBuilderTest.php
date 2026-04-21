<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Integration\Digest;

use Doctrine\DBAL\Connection;
use Hexis\ErrorDigestBundle\Digest\DigestBuilder;
use Hexis\ErrorDigestBundle\Digest\DigestPayload;
use Hexis\ErrorDigestBundle\Tests\Integration\SchemaFactory;
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
            'sections' => ['new', 'spiking', 'top', 'stale'],
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
