<?php

namespace CookieManagerKit\Scan;

use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\ClassificationEntry;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * Reads and writes classification rows. Replacing an origin is one transaction: either every new row is stored or
 * the old rows stay.
 */
class ClassificationStore
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @return list<array{kind: string, pattern: string, category: string, vendor: string, origin: string}>
     */
    public function entries(): array
    {
        try {
            $rows = $this->em->getRepository(ClassificationEntry::class)->findAll();
        } catch (TableNotFoundException) {
            // The package Update has not run yet; there is simply nothing to match against.
            return [];
        }

        $entries = [];

        foreach ($rows as $row) {
            $entries[] = [
                'kind' => $row->getKind(),
                'pattern' => $row->getPattern(),
                'category' => $row->getCategoryHandle(),
                'vendor' => $row->getVendor(),
                'origin' => $row->getOrigin(),
            ];
        }

        return $entries;
    }

    /**
     * @param list<array{kind: string, pattern: string, category: string, vendor: string, description: string, duration: string}> $rows
     */
    public function replaceOrigin(string $origin, array $rows): void
    {
        $this->em->transactional(function () use ($origin, $rows): void {
            $this->em->getConnection()->executeStatement('DELETE FROM cmkClassificationEntries WHERE origin = ?', [$origin]);

            foreach ($rows as $row) {
                $this->em->persist((new ClassificationEntry())->define($row['kind'], $row['pattern'], $row['category'], $row['vendor'], $row['description'], $row['duration'], $origin));
            }

            $this->em->flush();
        });
    }

    /**
     * Loads the shipped baseline over the previous bundled rows. Refreshed rows and overrides are untouched.
     */
    public function syncBundled(?string $path = null): void
    {
        $csv = @file_get_contents($path ?? ClassificationFile::baselinePath());
        $rows = $csv === false ? null : ClassificationFile::parseBundled($csv);

        if ($rows === null) {
            throw new \RuntimeException('The bundled classification file is missing or invalid.');
        }

        $this->replaceOrigin(ClassificationEntry::BUNDLED, $rows);
    }

    public function addOverride(string $kind, string $pattern, string $category, string $vendor): void
    {
        $this->em->persist((new ClassificationEntry())->define($kind, $pattern, $category, $vendor, '', '', ClassificationEntry::OVERRIDE));
        $this->em->flush();
    }
}
