<?php

declare(strict_types=1);

use Hexis\ErrorDigestBundle\Command\PruneCommand;
use Hexis\ErrorDigestBundle\Digest\DigestBuilder;
use Hexis\ErrorDigestBundle\Digest\Sender\DigestSender;
use Hexis\ErrorDigestBundle\Digest\Sender\MailerDigestSender;
use Hexis\ErrorDigestBundle\Digest\Sender\NotifierDigestSender;
use Hexis\ErrorDigestBundle\Doctrine\TablePrefixListener;
use Hexis\ErrorDigestBundle\Domain\DefaultFingerprinter;
use Hexis\ErrorDigestBundle\Domain\DefaultPiiScrubber;
use Hexis\ErrorDigestBundle\Domain\Fingerprinter;
use Hexis\ErrorDigestBundle\Controller\Ingest\JsController;
use Hexis\ErrorDigestBundle\Domain\PiiScrubber;
use Hexis\ErrorDigestBundle\Js\JsIngester;
use Hexis\ErrorDigestBundle\Js\JsPayloadValidator;
use Hexis\ErrorDigestBundle\Js\JsRateLimiter;
use Hexis\ErrorDigestBundle\MessageHandler\SendDailyDigestHandler;
use Hexis\ErrorDigestBundle\Monolog\ErrorDigestHandler;
use Hexis\ErrorDigestBundle\Storage\DbalWriter;
use Hexis\ErrorDigestBundle\Storage\FingerprintReader;
use Hexis\ErrorDigestBundle\Storage\Writer;
use Hexis\ErrorDigestBundle\Twig\ErrorDigestExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->instanceof(DigestSender::class)
        ->tag('error_digest.sender');

    $services->load(
        'Hexis\\ErrorDigestBundle\\',
        __DIR__ . '/../src/'
    )->exclude([
        __DIR__ . '/../src/Entity/',
        __DIR__ . '/../src/Resources/',
        __DIR__ . '/../src/Message/',
        __DIR__ . '/../src/ErrorDigestBundle.php',
    ]);

    $services->alias(Fingerprinter::class, DefaultFingerprinter::class);
    $services->alias(PiiScrubber::class, DefaultPiiScrubber::class);
    $services->alias(Writer::class, DbalWriter::class);

    $services->set(TablePrefixListener::class)
        ->arg('$tablePrefix', param('error_digest.storage.table_prefix'));

    $services->set(DbalWriter::class)
        ->arg('$connection', service('error_digest.connection'))
        ->arg('$scrubber', service(PiiScrubber::class))
        ->arg('$fingerprintTable', param('error_digest.table.fingerprint'))
        ->arg('$occurrenceTable', param('error_digest.table.occurrence'));

    $services->set(PruneCommand::class)
        ->arg('$retentionDays', param('error_digest.storage.occurrence_retention_days'));

    $services->set(ErrorDigestHandler::class)
        ->arg('$writer', service(Writer::class))
        ->arg('$fingerprinter', service(Fingerprinter::class))
        ->arg('$kernelEnvironment', param('kernel.environment'))
        ->arg('$minimumLevel', param('error_digest.minimum_level'))
        ->arg('$channels', param('error_digest.channels'))
        ->arg('$environments', param('error_digest.environments'))
        ->arg('$ignoreRules', param('error_digest.ignore'))
        ->arg('$rateLimitSeconds', param('error_digest.rate_limit.per_fingerprint_seconds'))
        ->public();

    $services->set(DigestBuilder::class)
        ->arg('$connection', service('error_digest.connection'))
        ->arg('$fingerprintTable', param('error_digest.table.fingerprint'))
        ->arg('$occurrenceTable', param('error_digest.table.occurrence'))
        ->arg('$digestConfig', param('error_digest.digest'))
        ->arg('$environment', param('kernel.environment'));

    $services->set(FingerprintReader::class)
        ->arg('$connection', service('error_digest.connection'))
        ->arg('$fingerprintTable', param('error_digest.table.fingerprint'))
        ->arg('$occurrenceTable', param('error_digest.table.occurrence'));

    $services->set(MailerDigestSender::class)
        ->arg('$recipients', param('error_digest.digest.recipients'))
        ->arg('$from', param('error_digest.digest.from'));

    $services->set(NotifierDigestSender::class)
        ->arg('$chatter', service(\Symfony\Component\Notifier\ChatterInterface::class)->nullOnInvalid())
        ->arg('$transports', param('error_digest.digest.notifier_transports'));

    $services->set(SendDailyDigestHandler::class)
        ->arg('$senders', tagged_iterator('error_digest.sender'))
        ->arg('$enabledSenderNames', param('error_digest.digest.senders'));

    // ----- JS ingest -----

    $services->set(JsPayloadValidator::class)
        ->arg('$maxStackLines', param('error_digest.js.max_stack_lines'));

    $services->set(JsRateLimiter::class)
        ->arg('$cache', service('cache.app'))
        ->arg('$maxRequestsPerMinute', param('error_digest.js.rate_limit_per_minute'));

    $services->set(JsIngester::class)
        ->arg('$connection', service('error_digest.connection'))
        ->arg('$scrubber', service(PiiScrubber::class))
        ->arg('$fingerprintTable', param('error_digest.table.fingerprint'))
        ->arg('$occurrenceTable', param('error_digest.table.occurrence'));

    $services->set(JsController::class)
        ->tag('controller.service_arguments')
        ->arg('$kernelEnvironment', param('kernel.environment'))
        ->arg('$allowedOrigins', param('error_digest.js.allowed_origins'))
        ->arg('$maxPayloadBytes', param('error_digest.js.max_payload_bytes'));

    $services->set(ErrorDigestExtension::class)
        ->arg('$enabled', param('error_digest.js.enabled'))
        ->arg('$release', param('error_digest.js.release'))
        ->arg('$maxPerPage', param('error_digest.js.client_max_per_page'))
        ->arg('$dedupWindowMs', param('error_digest.js.client_dedup_window_ms'));
};
