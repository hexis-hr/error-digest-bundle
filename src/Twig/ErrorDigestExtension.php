<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Twig;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig function that emits the <script> tag for the browser error-capture client.
 *
 *     {{ error_digest_script() }}
 *     {{ error_digest_script({release: app_version, user: app.user.id}) }}
 */
final class ErrorDigestExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly bool $enabled,
        private readonly ?string $release,
        private readonly int $maxPerPage,
        private readonly int $dedupWindowMs,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('error_digest_script', $this->renderScript(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param array{release?: string|null, user?: string|int|null} $options
     */
    public function renderScript(array $options = []): string
    {
        if (!$this->enabled) {
            return '';
        }

        try {
            $endpoint = $this->urlGenerator->generate(
                'error_digest_js_ingest',
                [],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            );
        } catch (RouteNotFoundException) {
            return '<!-- error-digest: ingest route not registered -->';
        }

        $release = $options['release'] ?? $this->release;
        $userRef = $options['user'] ?? null;

        $attrs = sprintf(
            ' src="/bundles/errordigest/error-digest.js" data-endpoint="%s" data-max-per-page="%d" data-dedup-window-ms="%d"',
            htmlspecialchars($endpoint, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
            $this->maxPerPage,
            $this->dedupWindowMs,
        );

        if ($release !== null && $release !== '') {
            $attrs .= sprintf(
                ' data-release="%s"',
                htmlspecialchars((string) $release, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
            );
        }

        if ($userRef !== null && $userRef !== '') {
            $attrs .= sprintf(
                ' data-user="%s"',
                htmlspecialchars((string) $userRef, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
            );
        }

        return '<script' . $attrs . ' defer></script>';
    }
}
