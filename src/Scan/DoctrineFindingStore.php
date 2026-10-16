<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanFinding;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Doctrine-backed storage. A concurrent insert of the same fingerprint makes flush() throw a
 * UniqueConstraintViolationException, which closes the EntityManager: callers must treat any exception from the
 * ingestor as a failed run and not reuse the EntityManager. Reads and the single flush take no row locks.
 */
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

    public function findByFingerprints(array $fingerprints): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($fingerprints)), 500) as $chunk) {
            $rows = $this->em->createQueryBuilder()
                ->select('f')
                ->from(ScanFinding::class, 'f')
                ->where('f.fingerprint IN (:fingerprints)')
                ->setParameter('fingerprints', $chunk)
                ->getQuery()
                ->getResult();

            foreach ($rows as $finding) {
                $found[$finding->getFingerprint()] = $finding;
            }
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
