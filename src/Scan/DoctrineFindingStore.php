<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanFinding;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineFindingStore implements FindingStore
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function all(): array
    {
        $found = [];

        foreach ($this->em->getRepository(ScanFinding::class)->findAll() as $finding) {
            $found[$finding->getFingerprint()] = $finding;
        }

        return $found;
    }

    public function add(ScanFinding $finding): void
    {
        $this->em->persist($finding);
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
