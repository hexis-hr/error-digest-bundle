<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Monolog;

use Hexis\ErrorDigestBundle\Domain\Fingerprinter;
use Hexis\ErrorDigestBundle\Storage\BufferedRecord;
use Hexis\ErrorDigestBundle\Storage\Writer;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Captures Monolog records into the err_fingerprint / err_occurrence tables.
 *
 * Does not write inline. Records are deduplicated into an in-memory buffer
 * during the request / console run; the buffer is flushed on terminate events
 * by FlushBufferListener.
 */
final class ErrorDigestHandler extends AbstractProcessingHandler
{
    /** @var array<string, BufferedRecord> */
    private array $buffer = [];

    /**
     * @param list<string> $channels  Allowlist; empty means all channels.
     * @param list<string> $environments Kernel environments where capture is active.
     * @param list<array{class: ?string, channel: ?string, level: ?string, message: ?string}> $ignoreRules
     */
    public function __construct(
        private readonly Writer $writer,
        private readonly Fingerprinter $fingerprinter,
        private readonly string $kernelEnvironment,
        private readonly string $minimumLevel,
        private readonly array $channels,
        private readonly array $environments,
        private readonly array $ignoreRules,
        private readonly int $rateLimitSeconds,
        private readonly LoggerInterface $internalLogger = new NullLogger(),
    ) {
        parent::__construct(Level::fromName($this->minimumLevel), true);
    }

    public function isHandling(LogRecord $record): bool
    {
        if (!\in_array($this->kernelEnvironment, $this->environments, true)) {
            return false;
        }

        if ($this->channels !== [] && !\in_array($record->channel, $this->channels, true)) {
            return false;
        }

        if (!parent::isHandling($record)) {
            return false;
        }

        return !$this->isIgnored($record);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $fingerprint = $this->fingerprinter->fingerprint($record);

            if (!isset($this->buffer[$fingerprint])) {
                $this->buffer[$fingerprint] = new BufferedRecord($record);
            }

            $this->buffer[$fingerprint]->addOccurrence($record, $this->rateLimitSeconds);
        } catch (\Throwable $e) {
            $this->reportInternalError($e);
        }
    }

    public function flushBuffer(): void
    {
        if ($this->buffer === []) {
            return;
        }

        try {
            $this->writer->flush($this->buffer, $this->kernelEnvironment);
        } catch (\Throwable $e) {
            $this->reportInternalError($e);
        } finally {
            $this->buffer = [];
        }
    }

    public function close(): void
    {
        $this->flushBuffer();
    }

    /**
     * @return array<string, BufferedRecord>
     */
    public function peekBuffer(): array
    {
        return $this->buffer;
    }

    private function isIgnored(LogRecord $record): bool
    {
        foreach ($this->ignoreRules as $rule) {
            if ($this->ruleMatches($rule, $record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{class: ?string, channel: ?string, level: ?string, message: ?string} $rule
     */
    private function ruleMatches(array $rule, LogRecord $record): bool
    {
        $matched = false;

        if ($rule['class'] !== null) {
            $exception = $record->context['exception'] ?? null;
            if (!$exception instanceof \Throwable || !is_a($exception, $rule['class'])) {
                return false;
            }
            $matched = true;
        }

        if ($rule['channel'] !== null) {
            if ($record->channel !== $rule['channel']) {
                return false;
            }
            $matched = true;
        }

        if ($rule['level'] !== null) {
            if (strtolower($record->level->getName()) !== strtolower($rule['level'])) {
                return false;
            }
            $matched = true;
        }

        if ($rule['message'] !== null) {
            if (@preg_match($rule['message'], $record->message) !== 1) {
                return false;
            }
            $matched = true;
        }

        return $matched;
    }

    private function reportInternalError(\Throwable $e): void
    {
        error_log(sprintf('[error-digest-bundle] capture failure: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));

        try {
            $this->internalLogger->error('ErrorDigestBundle capture failure', ['exception' => $e]);
        } catch (\Throwable) {
            // intentionally ignored — never re-enter Monolog from the handler
        }
    }
}
