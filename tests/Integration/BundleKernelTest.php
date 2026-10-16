<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Hexis\ErrorDigestBundle\Monolog\ErrorDigestHandler;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Boots the bundle in {@see TestKernel} and drives it the way a host does: the schema
 * comes from the bundle's migration, errors are logged through Monolog by
 * error-digest:fingerprint-test and flushed on console terminate, and the admin UI
 * answers HTTP requests.
 */
final class BundleKernelTest extends KernelTestCase
{
    private Application $application;
    private ApplicationTester $console;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        $this->application = new Application(self::bootKernel());
        $this->application->setAutoExit(false);
        $this->application->setCatchExceptions(false);
        $this->console = new ApplicationTester($this->application);

        $this->runConsoleCommand(['command' => 'doctrine:migrations:migrate']);
    }

    public function testRegistersTheBundleCommands(): void
    {
        $names = array_keys($this->application->all('error-digest'));
        sort($names);

        self::assertSame(
            ['error-digest:fingerprint-test', 'error-digest:list', 'error-digest:prune', 'error-digest:send-digest'],
            $names,
        );
    }

    public function testCapturesALoggedErrorAndListsItWithSecretsScrubbed(): void
    {
        $this->runConsoleCommand(['command' => 'error-digest:fingerprint-test', '--message' => 'Upstream rejected Bearer abc.def-123', '--count' => '3']);
        $this->runConsoleCommand(['command' => 'error-digest:fingerprint-test', '--ignored' => true]);

        $json = $this->listJson(['--status' => 'all']);

        self::assertSame(1, $json['total'], 'The NotFoundHttpException is dropped by the configured ignore rule.');
        $item = $json['items'][0];
        self::assertSame('open', $item['status']);
        self::assertSame('error', $item['level']);
        self::assertSame('app', $item['channel']);
        self::assertSame('test', $item['environment']);
        self::assertSame('RuntimeException', $item['exception_class']);
        self::assertSame('Upstream rejected Bearer [REDACTED]', $item['message']);
        self::assertStringEndsWith('FingerprintTestCommand.php', $item['file']);
        self::assertSame(3, $item['occurrence_count']);
        self::assertNull($item['latest_occurrence']['request_uri']);
        self::assertNull($item['latest_occurrence']['release']);
        self::assertStringStartsWith('RuntimeException: Upstream rejected Bearer [REDACTED]', $item['latest_occurrence']['trace_preview']);
        self::assertStringNotContainsString('abc.def-123', $this->console->getDisplay());
    }

    public function testTheConfiguredScrubberRedactsCaptureAndExport(): void
    {
        $message = 'Partner jane@example.com could not be saved';
        /** @var LoggerInterface $logger */
        $logger = self::getContainer()->get('logger');
        $logger->error($message, ['exception' => new \RuntimeException($message), 'contact' => 'jane@example.com']);
        /** @var ErrorDigestHandler $handler */
        $handler = self::getContainer()->get(ErrorDigestHandler::class);
        $handler->flushBuffer();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $context = json_decode((string) $connection->fetchOne('SELECT context_json FROM err_occurrence'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('[EMAIL]', $context['contact'], 'Capture scrubs the occurrence context with the configured scrubber.');

        $item = $this->listJson([])['items'][0];
        self::assertSame('Partner [EMAIL] could not be saved', $item['message']);
        self::assertStringStartsWith('RuntimeException: Partner [EMAIL] could not be saved', $item['latest_occurrence']['trace_preview']);
        self::assertStringNotContainsString('jane@example.com', $this->console->getDisplay());
    }

    public function testPruneDropsOccurrencesAndKeepsTheFingerprint(): void
    {
        $this->runConsoleCommand(['command' => 'error-digest:fingerprint-test', '--count' => '2']);
        $this->runConsoleCommand(['command' => 'error-digest:prune', '--older-than' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d')]);

        $item = $this->listJson([])['items'][0];

        self::assertSame(2, $item['occurrence_count']);
        self::assertNull($item['latest_occurrence']);
    }

    public function testSendDigestMailsNewErrorsAndMarksThemNotified(): void
    {
        $this->runConsoleCommand(['command' => 'error-digest:fingerprint-test']);
        $this->runConsoleCommand(['command' => 'error-digest:send-digest']);

        /** @var MessageLoggerListener $mailLog */
        $mailLog = self::getContainer()->get('mailer.message_logger_listener');
        $emails = array_values(array_filter(
            $mailLog->getEvents()->getMessages(),
            static fn (object $message): bool => $message instanceof Email,
        ));

        self::assertCount(1, $emails);
        self::assertSame(['admin@example.com'], array_map(static fn (Address $to): string => $to->getAddress(), $emails[0]->getTo()));
        self::assertStringContainsString('1 new', (string) $emails[0]->getSubject());
        self::assertStringContainsString('ErrorDigestBundle fingerprint-test', (string) $emails[0]->getTextBody());

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertNotNull($connection->fetchOne('SELECT notified_in_digest_at FROM err_fingerprint'));
    }

    public function testAdminPagesRenderForTheConfiguredRole(): void
    {
        $this->runConsoleCommand(['command' => 'error-digest:fingerprint-test', '--message' => 'Partner 4711 could not be saved']);
        $id = $this->listJson([])['items'][0]['id'];

        $dashboard = $this->request($this->url('error_digest_dashboard'), TestKernel::ADMIN);
        self::assertSame(Response::HTTP_OK, $dashboard->getStatusCode());
        self::assertStringContainsString('Partner 4711 could not be saved', (string) $dashboard->getContent());

        $detail = $this->request($this->url('error_digest_fingerprint_show', ['id' => $id]), TestKernel::ADMIN);
        self::assertSame(Response::HTTP_OK, $detail->getStatusCode());
        self::assertStringContainsString('Partner 4711 could not be saved', (string) $detail->getContent());
        self::assertStringContainsString('name="_token"', (string) $detail->getContent());
    }

    public function testAdminPagesRequireTheConfiguredRole(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->request($this->url('error_digest_dashboard'), null, catch: true)->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->request($this->url('error_digest_dashboard'), TestKernel::VIEWER, catch: true)->getStatusCode());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runConsoleCommand(array $input): void
    {
        $exitCode = $this->console->run($input, ['interactive' => false, 'capture_stderr_separately' => true]);

        self::assertSame(Command::SUCCESS, $exitCode, $this->console->getDisplay() . $this->console->getErrorOutput());
    }

    /**
     * @param array<string, string> $options
     * @return array<string, mixed>
     */
    private function listJson(array $options): array
    {
        $this->runConsoleCommand(['command' => 'error-digest:list'] + $options);

        return json_decode($this->console->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function url(string $route, array $parameters = []): string
    {
        /** @var UrlGeneratorInterface $router */
        $router = self::getContainer()->get('router');

        return $router->generate($route, $parameters);
    }

    private function request(string $uri, ?string $user, bool $catch = false): Response
    {
        $server = $user === null ? [] : ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => TestKernel::PASSWORD];
        $request = Request::create($uri, 'GET', server: $server);

        $kernel = self::$kernel;
        \assert($kernel instanceof TestKernel);
        $response = $kernel->handle($request, catch: $catch);
        $kernel->terminate($request, $response);

        return $response;
    }
}
