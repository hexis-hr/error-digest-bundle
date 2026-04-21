<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Command;

use Hexis\ErrorDigestBundle\Storage\DbalWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'error-digest:prune',
    description: 'Prune occurrence rows older than the configured retention window.',
)]
final class PruneCommand extends Command
{
    public function __construct(
        private readonly DbalWriter $writer,
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Override retention window, e.g. 30d, 12h, 2025-01-01.')
             ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report how many rows would be deleted without deleting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $threshold = $this->resolveThreshold($input->getOption('older-than'));
        $io->text(sprintf('Pruning occurrences older than %s', $threshold->format(\DateTimeInterface::ATOM)));

        if ($input->getOption('dry-run')) {
            $io->note('Dry run — no rows deleted.');

            return Command::SUCCESS;
        }

        $deleted = $this->writer->prune($threshold);
        $io->success(sprintf('Deleted %d occurrence row(s).', $deleted));

        return Command::SUCCESS;
    }

    private function resolveThreshold(?string $olderThan): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        if ($olderThan === null || $olderThan === '') {
            return $now->modify(sprintf('-%d days', $this->retentionDays));
        }

        if (preg_match('/^(\d+)([dhm])$/', $olderThan, $match) === 1) {
            [$_, $value, $unit] = $match;
            $interval = match ($unit) {
                'd' => sprintf('-%d days', $value),
                'h' => sprintf('-%d hours', $value),
                'm' => sprintf('-%d minutes', $value),
            };

            return $now->modify($interval);
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $olderThan);
        if ($parsed instanceof \DateTimeImmutable) {
            return $parsed;
        }

        throw new \InvalidArgumentException(sprintf('Cannot parse --older-than=%s. Use 30d, 12h, 90m, or YYYY-MM-DD.', $olderThan));
    }
}
