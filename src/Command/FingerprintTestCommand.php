<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AsCommand(
    name: 'error-digest:fingerprint-test',
    description: 'Emit a synthetic exception via Monolog to verify the capture pipeline.',
)]
final class FingerprintTestCommand extends Command
{
    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('count', null, InputOption::VALUE_REQUIRED, 'How many synthetic occurrences to emit.', '1')
             ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Override the test exception message.', 'ErrorDigestBundle fingerprint-test')
             ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Log level: warning|error|critical.', 'error')
             ->addOption('ignored', null, InputOption::VALUE_NONE, 'Emit a NotFoundHttpException to verify ignore rules suppress capture.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = max(1, (int) $input->getOption('count'));
        $message = (string) $input->getOption('message');
        $level = strtolower((string) $input->getOption('level'));

        $exception = $input->getOption('ignored')
            ? new NotFoundHttpException($message)
            : new \RuntimeException($message);

        for ($i = 0; $i < $count; $i++) {
            match ($level) {
                'warning' => $this->logger->warning($message, ['exception' => $exception]),
                'critical' => $this->logger->critical($message, ['exception' => $exception]),
                default => $this->logger->error($message, ['exception' => $exception]),
            };
        }

        $io->success(sprintf('Emitted %d synthetic "%s" record(s). Flush runs on console terminate.', $count, $level));

        return Command::SUCCESS;
    }
}
