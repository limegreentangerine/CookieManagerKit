<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * An EntityManager double whose repositories hold no scan runs.
 */
final class EmFactory
{
    public static function make(TestCase $test): EntityManagerInterface&MockObject
    {
        $repository = (new \ReflectionMethod($test, 'createMock'))->invoke($test, ObjectRepository::class);
        $repository->method('findBy')->willReturn([]);
        $em = (new \ReflectionMethod($test, 'createMock'))->invoke($test, EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return $em;
    }
}
