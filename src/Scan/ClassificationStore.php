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
            // Newest first, so the classifier's stable sort lets the most recent rule win a tie.
            $rows = $this->em->getRepository(ClassificationEntry::class)->findBy([], ['id' => 'DESC']);
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
     * Replaces every row of one origin. The whole replacement runs on the DBAL connection in one transaction, so a
     * failure rolls back and the previous rows stay, and the EntityManager is never involved (a failed flush would
     * close it for the rest of the request). Overrides can never be replaced here.
     *
     * @param list<array{kind: string, pattern: string, category: string, vendor: string, description: string, duration: string}> $rows
     *
     * @throws \InvalidArgumentException for any origin other than bundled or refreshed
     */
    public function replaceOrigin(string $origin, array $rows): void
    {
        if (!in_array($origin, [ClassificationEntry::BUNDLED, ClassificationEntry::REFRESHED], true)) {
            throw new \InvalidArgumentException('Only bundled and refreshed classification rows can be replaced.');
        }

        $metadata = $this->em->getClassMetadata(ClassificationEntry::class);
        $table = $metadata->getTableName();
        $column = static fn(string $field): string => $metadata->getColumnName($field);
        $connection = $this->em->getConnection();

        $connection->transactional(function () use ($connection, $table, $column, $origin, $rows): void {
            $connection->executeStatement('DELETE FROM ' . $table . ' WHERE ' . $column('origin') . ' = ?', [$origin]);

            foreach ($rows as $row) {
                $entry = (new ClassificationEntry())->define($row['kind'], $row['pattern'], $row['category'], $row['vendor'], $row['description'], $row['duration'], $origin);

                $connection->insert($table, [
                    $column('kind') => $entry->getKind(),
                    $column('pattern') => $entry->getPattern(),
                    $column('categoryHandle') => $entry->getCategoryHandle(),
                    $column('vendor') => $entry->getVendor(),
                    $column('description') => $entry->getDescription(),
                    $column('duration') => $entry->getDuration(),
                    $column('origin') => $entry->getOrigin(),
                ]);
            }
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

    /**
     * Stores an override. An existing override for the same kind and pattern is replaced (never duplicated); the delete
     * and the insert run on the DBAL connection in one transaction, and no other origin is touched.
     */
    public function addOverride(string $kind, string $pattern, string $category, string $vendor): void
    {
        $entry = (new ClassificationEntry())->define($kind, $pattern, $category, $vendor, '', '', ClassificationEntry::OVERRIDE);
        $metadata = $this->em->getClassMetadata(ClassificationEntry::class);
        $table = $metadata->getTableName();
        $column = static fn(string $field): string => $metadata->getColumnName($field);
        $connection = $this->em->getConnection();

        $connection->transactional(function () use ($connection, $table, $column, $entry): void {
            $connection->executeStatement(
                'DELETE FROM ' . $table . ' WHERE ' . $column('origin') . ' = ? AND ' . $column('kind') . ' = ? AND ' . $column('pattern') . ' = ?',
                [ClassificationEntry::OVERRIDE, $entry->getKind(), $entry->getPattern()],
            );

            $connection->insert($table, [
                $column('kind') => $entry->getKind(),
                $column('pattern') => $entry->getPattern(),
                $column('categoryHandle') => $entry->getCategoryHandle(),
                $column('vendor') => $entry->getVendor(),
                $column('description') => $entry->getDescription(),
                $column('duration') => $entry->getDuration(),
                $column('origin') => $entry->getOrigin(),
            ]);
        });
    }
}
