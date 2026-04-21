<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Digest\Sender;

use Hexis\ErrorDigestBundle\Digest\DigestPayload;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

/**
 * Posts a compact digest summary to a chat channel (Slack / Teams / Rocket.Chat
 * / Discord, whichever transports the host's Notifier has configured).
 *
 * The full rendered digest still goes via MailerDigestSender — this is the
 * "something is on fire, go look" ping.
 */
final class NotifierDigestSender implements DigestSender
{
    /**
     * @param list<string> $transports Named Notifier chat transports to fan out to. Empty = default transport.
     */
    public function __construct(
        private readonly ?ChatterInterface $chatter,
        private readonly array $transports = [],
    ) {
    }

    public function name(): string
    {
        return 'notifier';
    }

    public function send(DigestPayload $payload): void
    {
        if ($this->chatter === null) {
            throw new \LogicException('ErrorDigestBundle: notifier sender is enabled but ChatterInterface is not available. Install symfony/notifier and configure at least one chat transport.');
        }

        $text = $this->formatMessage($payload);

        if ($this->transports === []) {
            $this->chatter->send(new ChatMessage($text));

            return;
        }

        foreach ($this->transports as $transport) {
            $this->chatter->send((new ChatMessage($text))->transport($transport));
        }
    }

    private function formatMessage(DigestPayload $payload): string
    {
        $newCount = \count($payload->sections['new'] ?? []);
        $spikingCount = \count($payload->sections['spiking'] ?? []);
        $staleCount = \count($payload->sections['stale'] ?? []);
        $levelSummary = $this->formatLevelCounts($payload->levelCounts);

        $lines = [
            sprintf('*Error digest — %s* (%s → %s)',
                $payload->environment,
                $payload->windowStart->format('Y-m-d H:i'),
                $payload->windowEnd->format('Y-m-d H:i'),
            ),
            sprintf('%d new · %d spiking · %d stale · %s', $newCount, $spikingCount, $staleCount, $levelSummary),
        ];

        foreach (['new', 'spiking'] as $section) {
            $rows = $payload->sections[$section] ?? [];
            if ($rows === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = sprintf('_%s_', ucfirst($section));
            foreach (\array_slice($rows, 0, 5) as $row) {
                $suffix = $row->velocityMultiplier !== null
                    ? sprintf(' _(%.1f×)_', $row->velocityMultiplier)
                    : sprintf(' _(×%d)_', $row->windowOccurrences);
                $lines[] = sprintf(
                    '• `%s` [%s] %s%s',
                    $row->shortFingerprint(),
                    strtoupper($row->levelName),
                    $this->truncate($row->message, 120),
                    $suffix,
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, int> $counts
     */
    private function formatLevelCounts(array $counts): string
    {
        if ($counts === []) {
            return 'no occurrences';
        }

        $parts = [];
        foreach ($counts as $level => $count) {
            $parts[] = sprintf('%s:%d', $level, $count);
        }

        return implode(' ', $parts);
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1) . '…';
    }
}
