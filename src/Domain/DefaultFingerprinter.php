<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Domain;

use Monolog\LogRecord;

final class DefaultFingerprinter implements Fingerprinter
{
    public function fingerprint(LogRecord $record): string
    {
        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof \Throwable) {
            $parts = [
                $exception::class,
                $exception->getFile(),
                (string) $exception->getLine(),
                $this->normalizeMessage($exception->getMessage()),
            ];
        } else {
            $parts = [
                'log',
                $record->channel,
                (string) $record->level->value,
                $this->normalizeMessage($record->message),
            ];
        }

        return hash('sha256', implode('|', $parts));
    }

    private function normalizeMessage(string $message): string
    {
        $message = preg_replace('/[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}/', 'UUID', $message) ?? $message;
        $message = preg_replace('/\b[0-9a-fA-F]{16,}\b/', 'HASH', $message) ?? $message;
        $message = preg_replace('/\b\d+\b/', '0', $message) ?? $message;

        return trim($message);
    }
}
