<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Unit\Js;

use Hexis\ErrorDigestBundle\Js\JsEvent;
use Hexis\ErrorDigestBundle\Js\JsFingerprinter;
use PHPUnit\Framework\TestCase;

final class JsFingerprinterTest extends TestCase
{
    private JsFingerprinter $fingerprinter;

    protected function setUp(): void
    {
        $this->fingerprinter = new JsFingerprinter();
    }

    public function testIdenticalEventsShareFingerprint(): void
    {
        $a = $this->makeEvent();
        $b = $this->makeEvent();

        self::assertSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testNumericIdsInMessageAreNormalized(): void
    {
        $a = $this->makeEvent(message: 'Cannot read property foo of user 42');
        $b = $this->makeEvent(message: 'Cannot read property foo of user 17');

        self::assertSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testUrlsInMessageAreNormalized(): void
    {
        $a = $this->makeEvent(message: 'Failed to load https://api.example.com/v1/things?token=abc123');
        $b = $this->makeEvent(message: 'Failed to load https://api.example.com/v2/widgets?token=xyz789');

        self::assertSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testAssetHashInSourceUrlIsCollapsed(): void
    {
        $a = $this->makeEvent(sourceUrl: 'https://app.example.com/assets/bundle.abc1234567.js');
        $b = $this->makeEvent(sourceUrl: 'https://app.example.com/assets/bundle.def9876543.js');

        self::assertSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testQueryStringInSourceUrlIsIgnored(): void
    {
        $a = $this->makeEvent(sourceUrl: 'https://app.example.com/app.js?v=1');
        $b = $this->makeEvent(sourceUrl: 'https://app.example.com/app.js?v=2');

        self::assertSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testDifferentExceptionTypesProduceDifferentFingerprints(): void
    {
        $a = $this->makeEvent(exceptionType: 'TypeError');
        $b = $this->makeEvent(exceptionType: 'ReferenceError');

        self::assertNotSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testDifferentSourceUrlPathProducesDifferentFingerprint(): void
    {
        $a = $this->makeEvent(sourceUrl: 'https://app.example.com/checkout.js');
        $b = $this->makeEvent(sourceUrl: 'https://app.example.com/dashboard.js');

        self::assertNotSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    public function testStackTopFrameDisambiguatesCallers(): void
    {
        $a = $this->makeEvent(stack: "TypeError: x is null\n    at parseUser (user.js:42:3)\n    at init (app.js:10:5)");
        $b = $this->makeEvent(stack: "TypeError: x is null\n    at parseCompany (company.js:87:3)\n    at init (app.js:10:5)");

        self::assertNotSame($this->fingerprinter->fingerprint($a), $this->fingerprinter->fingerprint($b));
    }

    private function makeEvent(
        string $message = 'Cannot read property foo of undefined',
        ?string $exceptionType = 'TypeError',
        ?string $sourceUrl = 'https://app.example.com/bundle.js',
        ?int $line = 42,
        ?int $column = 15,
        ?string $stack = null,
    ): JsEvent {
        return new JsEvent(
            message: $message,
            exceptionType: $exceptionType,
            sourceUrl: $sourceUrl,
            line: $line,
            column: $column,
            stack: $stack,
            pageUrl: 'https://app.example.com/dashboard',
            userAgent: 'Mozilla/5.0',
            release: null,
            userRef: null,
            occurredAt: new \DateTimeImmutable('2026-04-21 10:00:00'),
        );
    }
}
