<?php

namespace CookieManagerKit\Scan;

use Doctrine\ORM\EntityRepository;
use CookieManagerKit\Entity\ScanFinding;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Counts for the dashboard badges and the run record. The ingest summary's `unclassified` only covers the findings the
 * ingest loaded (global only when gone-marking), so anything shown as a total comes from here instead.
 */
final class FindingCounts
{
    public static function unclassified(EntityManagerInterface $em): int
    {
        $repository = $em->getRepository(ScanFinding::class);

        return $repository instanceof EntityRepository ? $repository->count(['status' => ScanFinding::UNCLASSIFIED]) : 0;
    }
}
