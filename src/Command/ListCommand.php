<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Command;

use Hexis\ErrorDigestBundle\Digest\FingerprintSummary;
use Hexis\ErrorDigestBundle\Domain\PiiScrubber;
use Hexis\ErrorDigestBundle\Entity\ErrorFingerprint;
use Hexis\ErrorDigestBundle\Storage\FingerprintReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'error-digest:list',
    description: 'List error fingerprints as JSON for triage. Read-only.',
)]
final class ListCommand extends Command
{
    private const STATUSES = [ErrorFingerprint::STATUS_OPEN, ErrorFingerprint::STATUS_RESOLVED, ErrorFingerprint::STATUS_MUTED, 'all'];

    public function __construct(
        private readonly FingerprintReader $reader,
        private readonly PiiScrubber $scrubber,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('status', null, InputOption::VALUE_REQUIRED, 'open|resolved|muted|all.', ErrorFingerprint::STATUS_OPEN)
             ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only fingerprints last seen since, e.g. 7d, 12h, 90m, 2025-01-01 or "2025-01-01 08:00:00".')
             ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of fingerprints, most recently seen first.', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = (new SymfonyStyle($input, $output))->getErrorStyle();

        $status = strtolower((string) $input->getOption('status'));
        if (!\in_array($status, self::STATUSES, true)) {
            $io->error(sprintf('Unknown --status=%s. Use %s.', $status, implode('|', self::STATUSES)));

            return Command::INVALID;
        }

        $limit = filter_var($input->getOption('limit'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($limit === false) {
            $io->error('--limit must be a positive integer.');

            return Command::INVALID;
        }

        $since = null;
        if (($sinceOption = $input->getOption('since')) !== null) {
            $since = $this->parseSince((string) $sinceOption);
            if ($since === null) {
                $io->error(sprintf('Cannot parse --since=%s. Use 7d, 12h, 90m, Y-m-d or "Y-m-d H:i:s".', $sinceOption));

                return Command::INVALID;
            }
        }

        $result = $this->reader->list([
            'status' => $status === 'all' ? null : $status,
            'since' => $since,
        ], $limit, 0);

        $output->writeln(json_encode([
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'filters' => [
                'status' => $status,
                'since' => $since?->format(\DateTimeInterface::ATOM),
                'limit' => $limit,
            ],
            'total' => $result['total'],
            'items' => array_map($this->item(...), $result['rows']),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    /**
     * Only fields that help triage. Free text goes through the PII scrubber again: the
     * fingerprint message and trace are stored unscrubbed, and occurrence context,
     * user reference and query strings are left out.
     *
     * @return array<string, mixed>
     */
    private function item(FingerprintSummary $fp): array
    {
        $latest = $this->reader->occurrences($fp->id, 1)[0] ?? null;
        $uri = $latest['request_uri'] ?? null;

        $text = $this->scrubber->scrub([
            'message' => $fp->message,
            'trace' => $latest['trace_preview'] ?? null,
            'uri' => $uri === null ? null : preg_replace('/[?#].*$/s', '', $uri),
        ]);

        return [
            'id' => $fp->id,
            'fingerprint' => $fp->fingerprint,
            'status' => $fp->status,
            'level' => $fp->levelName,
            'channel' => $fp->channel,
            'environment' => $fp->environment,
            'exception_class' => $fp->exceptionClass,
            'message' => $text['message'],
            'file' => $fp->file,
            'line' => $fp->line,
            'occurrence_count' => $fp->occurrenceCount,
            'first_seen_at' => $fp->firstSeenAt->format(\DateTimeInterface::ATOM),
            'last_seen_at' => $fp->lastSeenAt->format(\DateTimeInterface::ATOM),
            'latest_occurrence' => $latest === null ? null : [
                'occurred_at' => $latest['occurred_at']->format(\DateTimeInterface::ATOM),
                'method' => $latest['method'],
                'request_uri' => $text['uri'],
                'release' => \is_string($latest['context']['release'] ?? null) ? $latest['context']['release'] : null,
                'trace_preview' => $text['trace'],
            ],
        ];
    }

    private function parseSince(string $since): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d+)([dhm])$/', $since, $match) === 1) {
            $unit = ['d' => 'days', 'h' => 'hours', 'm' => 'minutes'][$match[2]];

            return (new \DateTimeImmutable())->modify(sprintf('-%d %s', $match[1], $unit));
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $since)
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d', $since);

        // createFromFormat() rolls impossible dates over (2026-02-30 becomes 2026-03-02) and only warns.
        return $date !== false && \DateTimeImmutable::getLastErrors() === false ? $date : null;
    }
}
