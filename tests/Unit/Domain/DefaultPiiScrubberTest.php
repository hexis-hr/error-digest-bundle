<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Unit\Domain;

use Hexis\ErrorDigestBundle\Domain\DefaultPiiScrubber;
use PHPUnit\Framework\TestCase;

final class DefaultPiiScrubberTest extends TestCase
{
    private DefaultPiiScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new DefaultPiiScrubber();
    }

    public function testRedactsSensitiveKeys(): void
    {
        $input = [
            'username' => 'alice',
            'password' => 'hunter2',
            'api_key' => 'sk_live_xxx',
            'nested' => [
                'refresh_token' => 'abc',
            ],
        ];

        $scrubbed = $this->scrubber->scrub($input);

        self::assertSame('alice', $scrubbed['username']);
        self::assertSame('[REDACTED]', $scrubbed['password']);
        self::assertSame('[REDACTED]', $scrubbed['api_key']);
        self::assertSame('[REDACTED]', $scrubbed['nested']['refresh_token']);
    }

    public function testKeyMatchIsCaseInsensitive(): void
    {
        $scrubbed = $this->scrubber->scrub(['Authorization' => 'Bearer abc']);

        self::assertSame('[REDACTED]', $scrubbed['Authorization']);
    }

    public function testKeyMatchCatchesSubstringVariants(): void
    {
        $scrubbed = $this->scrubber->scrub([
            'user_password_hash' => 'abc',
            'csrf_token_value' => 'xyz',
            'safe_field' => 'ok',
        ]);

        self::assertSame('[REDACTED]', $scrubbed['user_password_hash']);
        self::assertSame('[REDACTED]', $scrubbed['csrf_token_value']);
        self::assertSame('ok', $scrubbed['safe_field']);
    }

    public function testMaskCreditCardLikeNumbers(): void
    {
        $scrubbed = $this->scrubber->scrub([
            'message' => 'Processed 4111 1111 1111 1111 today',
        ]);

        self::assertStringContainsString('[REDACTED]', $scrubbed['message']);
        self::assertStringNotContainsString('4111 1111 1111 1111', $scrubbed['message']);
    }

    public function testMaskBearerTokens(): void
    {
        $scrubbed = $this->scrubber->scrub([
            'trace' => 'Authorization: Bearer abc.def.ghi',
        ]);

        self::assertStringContainsString('Bearer [REDACTED]', $scrubbed['trace']);
    }

    public function testMaskJwtShapedStrings(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.signature-part';
        $scrubbed = $this->scrubber->scrub(['note' => "got token $jwt here"]);

        self::assertStringNotContainsString($jwt, $scrubbed['note']);
        self::assertStringContainsString('[REDACTED]', $scrubbed['note']);
    }

    public function testLeavesNonStringScalarsAlone(): void
    {
        $scrubbed = $this->scrubber->scrub([
            'count' => 42,
            'enabled' => true,
            'threshold' => 3.14,
        ]);

        self::assertSame(42, $scrubbed['count']);
        self::assertTrue($scrubbed['enabled']);
        self::assertSame(3.14, $scrubbed['threshold']);
    }
}
