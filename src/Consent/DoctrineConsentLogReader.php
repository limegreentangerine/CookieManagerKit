<?php

namespace CookieManagerKit\Consent;

use Doctrine\DBAL\Connection;

final class DoctrineConsentLogReader implements ConsentLogReader
{
    private const FROM = 'FROM cmkConsentLog l
        INNER JOIN cmkConsentSubjects s ON s.id = l.subjectID
        LEFT JOIN cmkPolicyChanges p ON p.version = l.policyVersion';

    public function __construct(private Connection $connection) {}

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(ConsentLogFilter $f): array
    {
        $start = (new \DateTimeImmutable($f->from . ' 00:00:00 UTC'))->getTimestamp();
        $end = (new \DateTimeImmutable($f->to . ' 23:59:59 UTC'))->getTimestamp();
        $sql = ' WHERE l.createdAt BETWEEN ? AND ?';
        $params = [$start, $end];

        if ($f->trigger !== '') {
            $sql .= ' AND l.triggerName = ?';
            $params[] = $f->trigger;
        }

        if ($f->version > 0) {
            $sql .= ' AND l.policyVersion = ?';
            $params[] = $f->version;
        }

        if ($f->category !== '') {
            $sql .= ' AND l.categories LIKE ?';
            $params[] = '%"' . $f->category . '"%';
        }

        if ($f->consentId !== '') {
            $sql .= ' AND s.id = ?';
            $params[] = $f->consentId;
        }

        return [$sql, $params];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{createdAt: int, consentId: string, version: int, categories: list<string>, trigger: string, locale: string, userAgentFamily: string, reason: string}
     */
    private static function map(array $row): array
    {
        $categories = json_decode((string) ($row['categories'] ?? '[]'), true);

        $trigger = (string) $row['triggerName'];

        return [
            'createdAt' => (int) $row['createdAt'],
            'consentId' => (string) $row['consentId'],
            'version' => (int) $row['policyVersion'],
            'categories' => is_array($categories) && array_is_list($categories) ? array_values(array_filter($categories, 'is_string')) : [],
            'trigger' => $trigger,
            'locale' => (string) $row['locale'],
            'userAgentFamily' => (string) $row['userAgentFamily'],
            /* The policy version's reason is only the visitor's reason for this row when they were asked again. */
            'reason' => $trigger === ConsentTrigger::RECONSENT ? (string) ($row['reason'] ?? '') : '',
        ];
    }

    private function select(ConsentLogFilter $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($f);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT l.createdAt, s.id AS consentId, l.policyVersion, l.categories, l.triggerName, l.locale, l.userAgentFamily, p.reason AS reason '
            . self::FROM . $where . ' ORDER BY l.createdAt DESC, l.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params,
        );

        return array_map(self::map(...), $rows);
    }

    public function page(ConsentLogFilter $filter, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filter);
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) ' . self::FROM . $where, $params);

        return ['rows' => $this->select($filter, $limit, $offset), 'total' => $total];
    }

    public function export(ConsentLogFilter $filter, int $max): array
    {
        return $this->select($filter, $max, 0);
    }
}
