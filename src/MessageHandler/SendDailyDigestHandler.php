<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\MessageHandler;

use Hexis\ErrorDigestBundle\Digest\DigestBuilder;
use Hexis\ErrorDigestBundle\Digest\Sender\DigestSender;
use Hexis\ErrorDigestBundle\Message\SendDailyDigest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendDailyDigestHandler
{
    /**
     * @param iterable<DigestSender> $senders All senders registered with the digest.sender tag.
     * @param list<string>           $enabledSenderNames Subset selected by host config.
     */
    public function __construct(
        private readonly DigestBuilder $builder,
        private readonly iterable $senders,
        private readonly array $enabledSenderNames,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(SendDailyDigest $message): void
    {
        $payload = $this->builder->build($message->now);

        if ($message->dryRun) {
            $this->logger->info('ErrorDigest dry-run — payload built but not sent', [
                'new' => \count($payload->sections['new'] ?? []),
                'spiking' => \count($payload->sections['spiking'] ?? []),
                'top' => \count($payload->sections['top'] ?? []),
                'stale' => \count($payload->sections['stale'] ?? []),
            ]);

            return;
        }

        $sentAny = false;
        foreach ($this->senders as $sender) {
            if (!\in_array($sender->name(), $this->enabledSenderNames, true)) {
                continue;
            }

            try {
                $sender->send($payload);
                $sentAny = true;
            } catch (\Throwable $e) {
                $this->logger->error('ErrorDigest sender failed', [
                    'sender' => $sender->name(),
                    'exception' => $e,
                ]);
            }
        }

        if ($sentAny) {
            $this->builder->markNotified($payload);
        }
    }
}
