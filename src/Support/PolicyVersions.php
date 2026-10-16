<?php

namespace CookieManagerKit\Support;

use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\{PolicyVersionChange, Settings};

/**
 * The only way to bump the policy version: it needs a reason, and the reason is kept. The caller flushes and
 * flushes the Catalog cache.
 */
final class PolicyVersions
{
    /**
     * @throws \InvalidArgumentException when the reason is empty after cleaning; nothing has changed then
     */
    public static function bump(EntityManagerInterface $em, Settings $settings, string $reason, int $adminUserId, ?\DateTimeImmutable $now = null): PolicyVersionChange
    {
        $change = (new PolicyVersionChange())->setReason($reason);

        $settings->bumpPolicyVersion();

        $change
            ->setVersion($settings->getPolicyVersion())
            ->setAdminUserId($adminUserId)
            ->setCreatedAt(($now ?? new \DateTimeImmutable())->getTimestamp());

        $em->persist($change);

        return $change;
    }

    /**
     * @return list<PolicyVersionChange>
     */
    public static function history(EntityManagerInterface $em, int $limit = 10): array
    {
        return array_values($em->getRepository(PolicyVersionChange::class)->findBy([], ['version' => 'DESC'], max(1, $limit)));
    }
}
