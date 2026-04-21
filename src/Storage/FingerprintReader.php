<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Hexis\ErrorDigestBundle\Digest\FingerprintSummary;
use Hexis\ErrorDigestBundle\Entity\ErrorFingerprint;

/**
 * Read-side companion to DbalWriter. Fetches fingerprint summaries and
 * occurrences for the dashboard and detail pages.
 */
final class FingerprintReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $fingerprintTable,
        private readonly string $occurrenceTable,
    ) {
    }

    /**
     * @param array{status?: ?string, level?: ?string, channel?: ?string, search?: ?string} $filters
     * @return array{rows: list<FingerprintSummary>, total: int}
     */
    public function list(array $filters, int $limit, int $offset): array
    {
        [$where, $params, $types] = $this->buildWhere($filters);
        $whereClause = $where === '' ? '' : ' WHERE ' . $where;

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT f.* FROM %s f%s ORDER BY f.last_seen_at DESC LIMIT %d OFFSET %d',
                $this->fingerprintTable,
                $whereClause,
                max(1, $limit),
                max(0, $offset),
            ),
            $params,
            $types,
        );

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s f%s', $this->fingerprintTable, $whereClause),
            $params,
            $types,
        );

        return [
            'rows' => array_map(fn (array $row) => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    public function find(int $id): ?FingerprintSummary
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->fingerprintTable),
            [$id],
            [ParameterType::INTEGER],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return list<array{
     *   id: int, occurred_at: \DateTimeImmutable, context: array<string, mixed>,
     *   request_uri: ?string, method: ?string, user_ref: ?string, trace_preview: ?string
     * }>
     */
    public function occurrences(int $fingerprintId, int $limit = 50): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT id, occurred_at, context_json, request_uri, method, user_ref, trace_preview
                 FROM %s
                 WHERE fingerprint_id = ?
                 ORDER BY occurred_at DESC
                 LIMIT %d',
                $this->occurrenceTable,
                max(1, $limit),
            ),
            [$fingerprintId],
            [ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row) => [
                'id' => (int) $row['id'],
                'occurred_at' => new \DateTimeImmutable((string) $row['occurred_at']),
                'context' => json_decode((string) $row['context_json'], true) ?: [],
                'request_uri' => $row['request_uri'] !== null ? (string) $row['request_uri'] : null,
                'method' => $row['method'] !== null ? (string) $row['method'] : null,
                'user_ref' => $row['user_ref'] !== null ? (string) $row['user_ref'] : null,
                'trace_preview' => $row['trace_preview'] !== null ? (string) $row['trace_preview'] : null,
            ],
            $rows,
        );
    }

    public function updateStatus(int $id, string $status): void
    {
        $allowed = [ErrorFingerprint::STATUS_OPEN, ErrorFingerprint::STATUS_RESOLVED, ErrorFingerprint::STATUS_MUTED];
        if (!\in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown status "%s".', $status));
        }

        $resolvedAt = $status === ErrorFingerprint::STATUS_RESOLVED
            ? (new \DateTimeImmutable())->format('Y-m-d H:i:s')
            : null;

        $this->connection->executeStatement(
            sprintf('UPDATE %s SET status = ?, resolved_at = ? WHERE id = ?', $this->fingerprintTable),
            [$status, $resolvedAt, $id],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    public function updateAssignee(int $id, ?string $assigneeId): void
    {
        $this->connection->executeStatement(
            sprintf('UPDATE %s SET assignee_id = ? WHERE id = ?', $this->fingerprintTable),
            [$assigneeId, $id],
            [ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    /**
     * @return list<string>
     */
    public function distinctChannels(): array
    {
        $rows = $this->connection->fetchFirstColumn(
            sprintf('SELECT DISTINCT channel FROM %s ORDER BY channel', $this->fingerprintTable),
        );

        return array_map('strval', $rows);
    }

    /**
     * @param array{status?: ?string, level?: ?string, channel?: ?string, search?: ?string} $filters
     * @return array{0: string, 1: list<mixed>, 2: list<int>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        $types = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'f.status = ?';
            $params[] = $filters['status'];
            $types[] = ParameterType::STRING;
        }
        if (!empty($filters['level'])) {
            $clauses[] = 'f.level_name = ?';
            $params[] = $filters['level'];
            $types[] = ParameterType::STRING;
        }
        if (!empty($filters['channel'])) {
            $clauses[] = 'f.channel = ?';
            $params[] = $filters['channel'];
            $types[] = ParameterType::STRING;
        }
        if (!empty($filters['search'])) {
            $clauses[] = '(f.message LIKE ? OR f.exception_class LIKE ? OR f.fingerprint LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
        }

        return [implode(' AND ', $clauses), $params, $types];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FingerprintSummary
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
            assigneeId: $row['assignee_id'] !== null ? (string) $row['assignee_id'] : null,
        );
    }
}
