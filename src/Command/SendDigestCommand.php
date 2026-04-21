<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Command;

use Hexis\ErrorDigestBundle\Message\SendDailyDigest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'error-digest:send-digest',
    description: 'Build and send the error digest to configured recipients.',
)]
final class SendDigestCommand extends Command
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Build the payload but do not send — logs summary only.')
             ->addOption('as-of', null, InputOption::VALUE_REQUIRED, 'Pretend "now" is this timestamp (Y-m-d H:i:s).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $now = null;
        if ($asOf = $input->getOption('as-of')) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $asOf)
                ?: \DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);
            if (!$parsed instanceof \DateTimeImmutable) {
                $io->error(sprintf('Cannot parse --as-of=%s. Use "Y-m-d" or "Y-m-d H:i:s".', $asOf));

                return Command::FAILURE;
            }
            $now = $parsed;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        $this->bus->dispatch(new SendDailyDigest(now: $now, dryRun: $dryRun));

        $io->success($dryRun ? 'Digest built in dry-run mode.' : 'Digest dispatched.');

        return Command::SUCCESS;
    }
}
