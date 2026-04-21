<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Unit\Domain;

use Hexis\ErrorDigestBundle\Domain\DefaultFingerprinter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class DefaultFingerprinterTest extends TestCase
{
    private DefaultFingerprinter $fingerprinter;

    protected function setUp(): void
    {
        $this->fingerprinter = new DefaultFingerprinter();
    }

    public function testSameExceptionInstanceProducesSameFingerprint(): void
    {
        $exception = $this->makeException(\RuntimeException::class, 'Boom');

        $a = $this->recordWithException($exception);
        $b = $this->recordWithException($exception);

        self::assertSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testDifferentExceptionInstancesAtSameLocationWithSameMessageShareFingerprint(): void
    {
        $a = $this->recordWithException($this->makeException(\RuntimeException::class, 'Boom'));
        $b = $this->recordWithException($this->makeException(\RuntimeException::class, 'Boom'));

        self::assertSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testDifferentMessagesProduceDifferentFingerprints(): void
    {
        $a = $this->recordWithException($this->makeException(\RuntimeException::class, 'Boom'));
        $b = $this->recordWithException($this->makeException(\RuntimeException::class, 'Different'));

        self::assertNotSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testDifferentExceptionClassesProduceDifferentFingerprints(): void
    {
        $a = $this->recordWithException($this->makeException(\RuntimeException::class, 'Boom'));
        $b = $this->recordWithException($this->makeException(\LogicException::class, 'Boom'));

        self::assertNotSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testDifferentLinesProduceDifferentFingerprints(): void
    {
        $a = $this->makeException(\RuntimeException::class, 'Boom');
        // Construct on a different line in this test file — file same, line differs.
        $b = new \RuntimeException('Boom');

        self::assertNotSame(
            $this->fingerprinter->fingerprint($this->recordWithException($a)),
            $this->fingerprinter->fingerprint($this->recordWithException($b)),
        );
    }

    public function testNumericIdsAreStrippedBeforeHashing(): void
    {
        $a = $this->recordWithException($this->makeException(\RuntimeException::class, 'User 42 not found'));
        $b = $this->recordWithException($this->makeException(\RuntimeException::class, 'User 4711 not found'));

        self::assertSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testUuidsAreStrippedBeforeHashing(): void
    {
        $a = $this->recordWithException($this->makeException(\RuntimeException::class, 'Entity 550e8400-e29b-41d4-a716-446655440000 missing'));
        $b = $this->recordWithException($this->makeException(\RuntimeException::class, 'Entity 6ba7b810-9dad-11d1-80b4-00c04fd430c8 missing'));

        self::assertSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testLongHexHashesAreStripped(): void
    {
        $a = $this->recordWithException($this->makeException(\RuntimeException::class, 'Token aabbccddeeff0011 invalid'));
        $b = $this->recordWithException($this->makeException(\RuntimeException::class, 'Token 9988776655443322 invalid'));

        self::assertSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testRecordWithoutExceptionFallsBackToChannelAndLevel(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Warning,
            message: 'Something odd',
        );

        $hash = $this->fingerprinter->fingerprint($record);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function testNonExceptionRecordsDifferByLevel(): void
    {
        $warning = new LogRecord(new \DateTimeImmutable(), 'app', Level::Warning, 'Odd');
        $error = new LogRecord(new \DateTimeImmutable(), 'app', Level::Error, 'Odd');

        self::assertNotSame($this->fingerprinter->fingerprint($warning), $this->fingerprinter->fingerprint($error));
    }

    /**
     * Constructs an exception at a fixed source location so tests can vary only the
     * message (or class) while keeping getFile()/getLine() stable across calls.
     *
     * @template T of \Throwable
     * @param class-string<T> $class
     * @return T
     */
    private function makeException(string $class, string $message): \Throwable
    {
        return new $class($message);
    }

    private function recordWithException(\Throwable $exception): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: $exception->getMessage(),
            context: ['exception' => $exception],
        );
    }
}
