<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Digest\Sender;

use Hexis\ErrorDigestBundle\Digest\DigestPayload;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final class MailerDigestSender implements DigestSender
{
    /**
     * @param list<string> $recipients
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly array $recipients,
        private readonly ?string $from,
    ) {
    }

    public function name(): string
    {
        return 'mailer';
    }

    public function send(DigestPayload $payload): void
    {
        if ($this->recipients === []) {
            throw new \LogicException('ErrorDigestBundle: no recipients configured for mailer sender (error_digest.digest.recipients).');
        }

        $email = (new TemplatedEmail())
            ->subject(sprintf(
                '[%s] Error digest — %d new, %d spiking, %s',
                $payload->environment,
                \count($payload->sections['new'] ?? []),
                \count($payload->sections['spiking'] ?? []),
                $this->summarizeLevels($payload->levelCounts),
            ))
            ->htmlTemplate('@ErrorDigest/email/digest.html.twig')
            ->textTemplate('@ErrorDigest/email/digest.txt.twig')
            ->context(['payload' => $payload]);

        if ($this->from !== null && $this->from !== '') {
            $email->from(new Address($this->from));
        }

        foreach ($this->recipients as $recipient) {
            $email->addTo($recipient);
        }

        $this->mailer->send($email);
    }

    /**
     * @param array<string, int> $counts
     */
    private function summarizeLevels(array $counts): string
    {
        if ($counts === []) {
            return 'no activity';
        }

        $parts = [];
        foreach ($counts as $level => $count) {
            $parts[] = sprintf('%s: %d', $level, $count);
        }

        return implode(', ', $parts);
    }
}
