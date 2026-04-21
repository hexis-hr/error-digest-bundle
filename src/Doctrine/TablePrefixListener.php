<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Hexis\ErrorDigestBundle\Entity\ErrorFingerprint;
use Hexis\ErrorDigestBundle\Entity\ErrorOccurrence;

#[AsDoctrineListener(event: Events::loadClassMetadata)]
final class TablePrefixListener
{
    private const DEFAULT_PREFIX = 'err_';

    public function __construct(private readonly string $tablePrefix)
    {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        $class = $metadata->getName();

        if ($class !== ErrorFingerprint::class && $class !== ErrorOccurrence::class) {
            return;
        }

        if ($this->tablePrefix === self::DEFAULT_PREFIX) {
            return;
        }

        $currentTable = $metadata->getTableName();
        if (!str_starts_with($currentTable, self::DEFAULT_PREFIX)) {
            return;
        }

        $metadata->setPrimaryTable([
            'name' => $this->tablePrefix . substr($currentTable, \strlen(self::DEFAULT_PREFIX)),
        ]);
    }
}
