<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\EventListener;

use Hexis\ErrorDigestBundle\Monolog\ErrorDigestHandler;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class FlushBufferListener
{
    public function __construct(private readonly ErrorDigestHandler $handler)
    {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -1000)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->handler->flushBuffer();
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE, priority: -1000)]
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->handler->flushBuffer();
    }
}
