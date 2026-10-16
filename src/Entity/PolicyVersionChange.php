<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\BaseEntity;

/**
 * Why a policy version exists. Append-only. The reason is admin-typed (or pre-filled from a scanner finding, which
 * came from the public internet), so it is plain text, length-capped here and encoded wherever it is shown.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkPolicyChanges",
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uniq_cmk_policy_version", columns={"version"})},
 *      options={"comment": "Cookie Manager policy version history"}
 * )
 */
class PolicyVersionChange extends BaseEntity
{
    public const MAX_REASON = 500;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 1, "comment": "The policy version this change created"})
     */
    protected $version = 1;

    /**
     * @ORM\Column(type="string", length=500, nullable=false, options={"default": "", "comment": "Why visitors were asked again"})
     */
    protected $reason = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Admin user who made the change, 0 for the system"})
     */
    protected $adminUserId = 0;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "UTC unix seconds"})
     */
    protected $createdAt = 0;

    public function setVersion(int $version): self
    {
        $this->version = max(1, $version);

        return $this;
    }

    public function getVersion(): int
    {
        return max(1, (int) $this->version);
    }

    /**
     * @throws \InvalidArgumentException when nothing is left after cleaning
     */
    public function setReason(string $reason): self
    {
        $clean = Text::plain($reason, self::MAX_REASON);

        if ($clean === '') {
            throw new \InvalidArgumentException('A reason is required.');
        }

        $this->reason = $clean;

        return $this;
    }

    public function getReason(): string
    {
        return (string) $this->reason;
    }

    public function setAdminUserId(int $id): self
    {
        $this->adminUserId = max(0, $id);

        return $this;
    }

    public function getAdminUserId(): int
    {
        return (int) $this->adminUserId;
    }

    public function setCreatedAt(int $seconds): self
    {
        $this->createdAt = max(0, $seconds);

        return $this;
    }

    public function getCreatedAt(): int
    {
        return (int) $this->createdAt;
    }
}
