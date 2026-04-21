<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Digest;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Hexis\ErrorDigestBundle\Entity\ErrorFingerprint;

final class DigestBuilder
{
    /**
     * @param array{
     *   enabled: bool,
     *   schedule: string,
     *   recipients: list<string>,
     *   from: ?string,
     *   senders: list<string>,
     *   window: string,
     *   sections: list<string>,
     *   top_limit: int,
     *   stale_days: int,
     *   spike_multiplier: float,
     * } $digestConfig
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $fingerprintTable,
        private readonly string $occurrenceTable,
        private readonly array $digestConfig,
        private readonly string $environment,
    ) {
    }

    public function build(?\DateTimeImmutable $now = null): DigestPayload
    {
        $now ??= new \DateTimeImmutable();
        $windowEnd = $now;
        $windowStart = $now->modify('-' . $this->digestConfig['window']);

        $sections = [];
        $sectionNames = $this->digestConfig['sections'];

        if (\in_array('new', $sectionNames, true)) {
            $sections['new'] = $this->queryNew($windowStart, $windowEnd);
        }
        if (\in_array('spiking', $sectionNames, true)) {
            $sections['spiking'] = $this->querySpiking($windowStart, $windowEnd);
        }
        if (\in_array('top', $sectionNames, true)) {
            $sections['top'] = $this->queryTop($windowStart, $windowEnd);
        }
        if (\in_array('stale', $sectionNames, true)) {
            $sections['stale'] = $this->queryStale($now);
        }

        $levelCounts = $this->queryLevelCounts($windowStart, $windowEnd);

        return new DigestPayload(
            generatedAt: $now,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            environment: $this->environment,
            sections: $sections,
            levelCounts: $levelCounts,
        );
    }

    public function markNotified(DigestPayload $payload): void
    {
        $ids = $payload->allFingerprintIds();
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, \count($ids), '?'));
        $this->connection->executeStatement(
            sprintf('UPDATE %s SET notified_in_digest_at = ? WHERE id IN (%s)', $this->fingerprintTable, $placeholders),
            array_merge([$payload->generatedAt->format('Y-m-d H:i:s')], $ids),
        );
    }

    /**
     * @return list<FingerprintSummary>
     */
    private function queryNew(\DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): array
    {
        $sql = sprintf(
            'SELECT f.*, (
                 SELECT COUNT(*) FROM %s o WHERE o.fingerprint_id = f.id AND o.occurred_at BETWEEN ? AND ?
             ) AS window_occurrences
             FROM %s f
             WHERE f.first_seen_at >= ?
               AND f.first_seen_at <= ?
               AND f.notified_in_digest_at IS NULL
               AND f.status = ?
             ORDER BY f.first_seen_at DESC',
            $this->occurrenceTable,
            $this->fingerprintTable,
        );

        return $this->fetchSummaries($sql, [
            $windowStart->format('Y-m-d H:i:s'),
            $windowEnd->format('Y-m-d H:i:s'),
            $windowStart->format('Y-m-d H:i:s'),
            $windowEnd->format('Y-m-d H:i:s'),
            ErrorFingerprint::STATUS_OPEN,
        ]);
    }

    /**
     * @return list<FingerprintSummary>
     */
    private function querySpiking(\DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): array
    {
        $windowSeconds = max(1, $windowEnd->getTimestamp() - $windowStart->getTimestamp());
        $priorStart = $windowStart->modify('-' . $windowSeconds . ' seconds');

        $multiplier = $this->digestConfig['spike_multiplier'];

        $sql = sprintf(
            'SELECT * FROM (
                SELECT f.*,
                    (SELECT COUNT(*) FROM %1$s o WHERE o.fingerprint_id = f.id AND o.occurred_at BETWEEN ? AND ?) AS window_occurrences,
                    (SELECT COUNT(*) FROM %1$s o2 WHERE o2.fingerprint_id = f.id AND o2.occurred_at BETWEEN ? AND ?) AS prior_occurrences
                FROM %2$s f
                WHERE f.status = ?
             ) AS ranked
             WHERE window_occurrences > 0
                AND window_occurrences > (CASE WHEN prior_occurrences > 1 THEN prior_occurrences ELSE 1 END * ?)
             ORDER BY window_occurrences DESC',
            $this->occurrenceTable,
            $this->fingerprintTable,
        );

        $rows = $this->connection->fetchAllAssociative($sql, [
            $windowStart->format('Y-m-d H:i:s'),
            $windowEnd->format('Y-m-d H:i:s'),
            $priorStart->format('Y-m-d H:i:s'),
            $windowStart->format('Y-m-d H:i:s'),
            ErrorFingerprint::STATUS_OPEN,
            $multiplier,
        ]);

        return array_map(
            fn (array $row) => $this->hydrateSummary($row, (float) ($row['window_occurrences'] / max(1, (int) $row['prior_occurrences']))),
            $rows,
        );
    }

    /**
     * @return list<FingerprintSummary>
     */
    private function queryTop(\DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): array
    {
        $limit = max(1, $this->digestConfig['top_limit']);

        $sql = sprintf(
            'SELECT * FROM (
                SELECT f.*,
                       (SELECT COUNT(*) FROM %1$s o WHERE o.fingerprint_id = f.id AND o.occurred_at BETWEEN ? AND ?) AS window_occurrences
                FROM %2$s f
                WHERE f.status = ?
             ) AS ranked
             WHERE window_occurrences > 0
             ORDER BY window_occurrences DESC
             LIMIT %3$d',
            $this->occurrenceTable,
            $this->fingerprintTable,
            $limit,
        );

        return $this->fetchSummaries($sql, [
            $windowStart->format('Y-m-d H:i:s'),
            $windowEnd->format('Y-m-d H:i:s'),
            ErrorFingerprint::STATUS_OPEN,
        ]);
    }

    /**
     * @return list<FingerprintSummary>
     */
    private function queryStale(\DateTimeImmutable $now): array
    {
        $staleThreshold = $now->modify('-' . $this->digestConfig['stale_days'] . ' days');

        $sql = sprintf(
            'SELECT f.*, 0 AS window_occurrences
             FROM %s f
             WHERE f.status = ?
               AND f.first_seen_at < ?
             ORDER BY f.last_seen_at DESC',
            $this->fingerprintTable,
        );

        return $this->fetchSummaries($sql, [
            ErrorFingerprint::STATUS_OPEN,
            $staleThreshold->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function queryLevelCounts(\DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): array
    {
        $sql = sprintf(
            'SELECT f.level_name, COUNT(o.id) AS total
             FROM %s o
             INNER JOIN %s f ON f.id = o.fingerprint_id
             WHERE o.occurred_at BETWEEN ? AND ?
             GROUP BY f.level_name',
            $this->occurrenceTable,
            $this->fingerprintTable,
        );

        $counts = [];
        foreach ($this->connection->fetchAllAssociative($sql, [
            $windowStart->format('Y-m-d H:i:s'),
            $windowEnd->format('Y-m-d H:i:s'),
        ]) as $row) {
            $counts[(string) $row['level_name']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param list<mixed> $params
     * @return list<FingerprintSummary>
     */
    private function fetchSummaries(string $sql, array $params): array
    {
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(fn (array $row) => $this->hydrateSummary($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSummary(array $row, ?float $velocityMultiplier = null): FingerprintSummary
    {
        return new FingerprintSummary(
            id: (int) $row['id'],
            fingerprint: (string) $row['fingerprint'],
            level: (int) $row['level'],
            levelName: (string) $row['level_name'],
            message: (string) $row['message'],
            exceptionClass: $row['exception_class'] !== null ? (string) $row['exception_class'] : null,
            file: $row['file'] !== null ? (string) $row['file'] : null,
            line: $row['line'] !== null ? (int) $row['line'] : null,
            channel: (string) $row['channel'],
            environment: (string) $row['environment'],
            firstSeenAt: new \DateTimeImmutable((string) $row['first_seen_at']),
            lastSeenAt: new \DateTimeImmutable((string) $row['last_seen_at']),
            occurrenceCount: (int) $row['occurrence_count'],
            status: (string) $row['status'],
            assigneeId: isset($row['assignee_id']) && $row['assignee_id'] !== null ? (string) $row['assignee_id'] : null,
            windowOccurrences: (int) ($row['window_occurrences'] ?? 0),
            velocityMultiplier: $velocityMultiplier,
        );
    }
}
