<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Controller\Ingest;

use Hexis\ErrorDigestBundle\Js\InvalidJsPayloadException;
use Hexis\ErrorDigestBundle\Js\JsIngester;
use Hexis\ErrorDigestBundle\Js\JsPayloadValidator;
use Hexis\ErrorDigestBundle\Js\JsRateLimiter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives JS error reports from browsers. Always returns 204 on success
 * and on validation/rate-limit rejections — the client's job is fire-and-forget,
 * not to learn anything about server-side policy.
 */
final class JsController
{
    public function __construct(
        private readonly JsIngester $ingester,
        private readonly JsPayloadValidator $validator,
        private readonly JsRateLimiter $limiter,
        private readonly string $kernelEnvironment,
        /** @var list<string> */
        private readonly array $allowedOrigins,
        private readonly int $maxPayloadBytes,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Route('/js', name: 'error_digest_js_ingest', methods: ['POST', 'OPTIONS'])]
    public function ingest(Request $request): Response
    {
        $origin = (string) $request->headers->get('Origin', '');
        $allowedOrigin = $this->resolveAllowedOrigin($origin);

        if ($request->getMethod() === 'OPTIONS') {
            return $this->corsPreflight($allowedOrigin);
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $this->applyCorsHeaders($response, $allowedOrigin);

        $clientKey = (string) ($request->getClientIp() ?? 'unknown');
        if (!$this->limiter->accept($clientKey)) {
            return $response;
        }

        $content = $request->getContent();
        if ($content === '' || \strlen($content) > $this->maxPayloadBytes) {
            return $response;
        }

        try {
            $payload = json_decode($content, true, 10, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $response;
        }

        if (!\is_array($payload)) {
            return $response;
        }

        try {
            $event = $this->validator->validate($payload);
            $this->ingester->ingest($event, $this->kernelEnvironment);
        } catch (InvalidJsPayloadException) {
            // malformed — silently drop; don't leak schema back to untrusted clients
        } catch (\Throwable $e) {
            // storage failure — log, don't rethrow (never let ingest take the page down)
            $this->logger->error('ErrorDigest JS ingest failure', ['exception' => $e]);
        }

        return $response;
    }

    private function resolveAllowedOrigin(string $origin): ?string
    {
        if ($origin === '' || $this->allowedOrigins === []) {
            return null;
        }

        foreach ($this->allowedOrigins as $pattern) {
            if ($pattern === '*' || $pattern === $origin) {
                return $pattern === '*' ? $origin : $pattern;
            }

            // Wildcard subdomain support: `https://*.example.com` matches `https://foo.example.com`
            if (str_contains($pattern, '*')) {
                $regex = '#^' . str_replace(['\\*', '\\.'], ['[^/]+', '\\.'], preg_quote($pattern, '#')) . '$#';
                if (preg_match($regex, $origin) === 1) {
                    return $origin;
                }
            }
        }

        return null;
    }

    private function corsPreflight(?string $allowedOrigin): Response
    {
        $response = new Response('', Response::HTTP_NO_CONTENT);
        $this->applyCorsHeaders($response, $allowedOrigin);
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }

    private function applyCorsHeaders(Response $response, ?string $allowedOrigin): void
    {
        if ($allowedOrigin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
            $response->headers->set('Vary', 'Origin');
        }
    }
}
