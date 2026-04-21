<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Unit\Monolog;

use Hexis\ErrorDigestBundle\Domain\DefaultFingerprinter;
use Hexis\ErrorDigestBundle\Monolog\ErrorDigestHandler;
use Hexis\ErrorDigestBundle\Storage\Writer;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ErrorDigestHandlerTest extends TestCase
{
    public function testHandlerSkipsRecordsBelowMinimumLevel(): void
    {
        $handler = $this->makeHandler(minimumLevel: 'error');

        self::assertFalse($handler->isHandling($this->record(Level::Warning)));
        self::assertTrue($handler->isHandling($this->record(Level::Error)));
        self::assertTrue($handler->isHandling($this->record(Level::Critical)));
    }

    public function testHandlerSkipsRecordsFromDisabledEnvironments(): void
    {
        $handler = $this->makeHandler(environments: ['prod']);

        self::assertFalse($handler->isHandling($this->record(Level::Error)));
    }

    public function testHandlerRespectsChannelAllowlist(): void
    {
        $handler = $this->makeHandler(channels: ['security']);

        self::assertFalse($handler->isHandling($this->record(channel: 'app')));
        self::assertTrue($handler->isHandling($this->record(channel: 'security')));
    }

    public function testEmptyChannelListMeansAllChannels(): void
    {
        $handler = $this->makeHandler(channels: []);

        self::assertTrue($handler->isHandling($this->record(channel: 'app')));
        self::assertTrue($handler->isHandling($this->record(channel: 'security')));
    }

    public function testIgnoreRuleByExceptionClassSuppressesRecord(): void
    {
        $handler = $this->makeHandler(ignoreRules: [
            ['class' => NotFoundHttpException::class, 'channel' => null, 'level' => null, 'message' => null],
        ]);

        $ignored = $this->record(Level::Error, exception: new NotFoundHttpException('nope'));
        $captured = $this->record(Level::Error, exception: new \RuntimeException('boom'));

        self::assertFalse($handler->isHandling($ignored));
        self::assertTrue($handler->isHandling($captured));
    }

    public function testIgnoreRuleByChannelOnlySuppressesThatChannel(): void
    {
        $handler = $this->makeHandler(ignoreRules: [
            ['class' => null, 'channel' => 'deprecation', 'level' => null, 'message' => null],
        ]);

        self::assertFalse($handler->isHandling($this->record(channel: 'deprecation')));
        self::assertTrue($handler->isHandling($this->record(channel: 'app')));
    }

    public function testIgnoreRuleByMessageRegex(): void
    {
        $handler = $this->makeHandler(ignoreRules: [
            ['class' => null, 'channel' => null, 'level' => null, 'message' => '/health.?check/i'],
        ]);

        self::assertFalse($handler->isHandling($this->record(Level::Error, message: 'HealthCheck failed')));
        self::assertTrue($handler->isHandling($this->record(Level::Error, message: 'Real error')));
    }

    public function testIgnoreRuleRequiresAllSpecifiedFieldsToMatch(): void
    {
        $handler = $this->makeHandler(ignoreRules: [
            ['class' => NotFoundHttpException::class, 'channel' => 'security', 'level' => null, 'message' => null],
        ]);

        $matching = $this->record(Level::Error, exception: new NotFoundHttpException('x'), channel: 'security');
        $wrongChannel = $this->record(Level::Error, exception: new NotFoundHttpException('x'), channel: 'app');

        self::assertFalse($handler->isHandling($matching));
        self::assertTrue($handler->isHandling($wrongChannel));
    }

    public function testBufferDedupsByFingerprint(): void
    {
        $handler = $this->makeHandler();
        $exception = new \RuntimeException('same');

        $handler->handle($this->record(Level::Error, exception: $exception));
        $handler->handle($this->record(Level::Error, exception: $exception));
        $handler->handle($this->record(Level::Error, exception: $exception));

        $buffer = $handler->peekBuffer();
        self::assertCount(1, $buffer);
        $first = array_values($buffer)[0];
        self::assertSame(3, $first->count);
    }

    public function testFlushBufferClearsState(): void
    {
        $writer = $this->createMock(Writer::class);
        $writer->expects(self::once())->method('flush');

        $handler = $this->makeHandler(writer: $writer);
        $handler->handle($this->record(Level::Error, exception: new \RuntimeException('boom')));

        self::assertNotEmpty($handler->peekBuffer());
        $handler->flushBuffer();
        self::assertEmpty($handler->peekBuffer());
    }

    public function testFlushDoesNotRethrowWriterErrors(): void
    {
        $writer = $this->createMock(Writer::class);
        $writer->method('flush')->willThrowException(new \RuntimeException('db down'));

        $handler = $this->makeHandler(writer: $writer);
        $handler->handle($this->record(Level::Error, exception: new \RuntimeException('boom')));

        $handler->flushBuffer();
        self::assertEmpty($handler->peekBuffer(), 'buffer should be cleared even when writer throws');
    }

    private function makeHandler(
        ?Writer $writer = null,
        string $minimumLevel = 'warning',
        array $channels = [],
        array $environments = ['test'],
        array $ignoreRules = [],
        int $rateLimitSeconds = 1,
    ): ErrorDigestHandler {
        return new ErrorDigestHandler(
            writer: $writer ?? $this->createStub(Writer::class),
            fingerprinter: new DefaultFingerprinter(),
            kernelEnvironment: 'test',
            minimumLevel: $minimumLevel,
            channels: $channels,
            environments: $environments,
            ignoreRules: $ignoreRules,
            rateLimitSeconds: $rateLimitSeconds,
        );
    }

    private function record(
        Level $level = Level::Error,
        string $channel = 'app',
        string $message = 'boom',
        ?\Throwable $exception = null,
    ): LogRecord {
        $context = [];
        if ($exception !== null) {
            $context['exception'] = $exception;
        }

        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: $channel,
            level: $level,
            message: $message,
            context: $context,
        );
    }
}
