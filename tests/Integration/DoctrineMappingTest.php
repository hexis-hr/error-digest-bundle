<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Hexis\ErrorDigestBundle\Entity\ErrorFingerprint;
use Hexis\ErrorDigestBundle\Entity\ErrorOccurrence;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The bundle maps its entities on the host's entity manager whether the host maps
 * bundles itself or not (the Symfony recipe turns doctrine.orm.auto_mapping on).
 */
final class DoctrineMappingTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environments(): iterable
    {
        yield 'the bundle maps its entities' => ['test'];
        yield 'the host auto-maps bundles too' => [TestKernel::AUTO_MAPPING];
    }

    #[DataProvider('environments')]
    public function testMapsTheEntitiesOnTheEntityManager(string $environment): void
    {
        self::bootKernel(['environment' => $environment]);

        /** @var ManagerRegistry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        $manager = $doctrine->getManager();
        \assert($manager instanceof EntityManagerInterface);

        self::assertSame('err_fingerprint', $manager->getClassMetadata(ErrorFingerprint::class)->getTableName());
        self::assertSame('err_occurrence', $manager->getClassMetadata(ErrorOccurrence::class)->getTableName());
    }
}
