<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use ClassKit\Entity\Core\BaseEntity;
use CookieManagerKit\Consent\ConsentTrigger;

/**
 * One decision by one browser. Append-only: it is never updated. Everything in it came from a public request and
 * was validated against an allowlist before it got here; it is still encoded wherever it is shown.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkConsentLog",
 *      indexes={
 *          @ORM\Index(name="idx_cmk_log_subject", columns={"subjectID"}),
 *          @ORM\Index(name="idx_cmk_log_created", columns={"createdAt"})
 *      },
 *      options={"comment": "Cookie Manager consent log: one row per decision"}
 * )
 */
class ConsentLogEntry extends BaseEntity
{
    /**
     * @ORM\ManyToOne(targetEntity="CookieManagerKit\Entity\ConsentSubject")
     * @ORM\JoinColumn(name="subjectID", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    protected $subject;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 1, "comment": "Policy version the choice was made under"})
     */
    protected $policyVersion = 1;

    /**
     * @ORM\Column(type="text", nullable=false, options={"comment": "JSON list of category handles"})
     */
    protected $categories = '[]';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "custom", "comment": "accept_all, reject_all, custom, gpc or reconsent"})
     */
    protected $triggerName = ConsentTrigger::CUSTOM;

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"default": "", "comment": "Site locale of the banner"})
     */
    protected $locale = '';

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"default": "", "comment": "Coarse browser/device bucket, never the raw user agent"})
     */
    protected $userAgentFamily = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "UTC unix seconds"})
     */
    protected $createdAt = 0;

    public function setSubject(ConsentSubject $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function getSubject(): ?ConsentSubject
    {
        return $this->subject;
    }

    public function setPolicyVersion(int $version): self
    {
        $this->policyVersion = max(1, $version);

        return $this;
    }

    public function getPolicyVersion(): int
    {
        return max(1, (int) $this->policyVersion);
    }

    /**
     * @param list<string> $handles
     */
    public function setCategories(array $handles): self
    {
        $this->categories = json_encode(array_values(array_map('strval', $handles)), JSON_THROW_ON_ERROR);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getCategories(): array
    {
        $decoded = json_decode((string) $this->categories, true);

        return is_array($decoded) && array_is_list($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    public function setTrigger(string $trigger): self
    {
        $this->triggerName = ConsentTrigger::isValid($trigger) ? $trigger : ConsentTrigger::CUSTOM;

        return $this;
    }

    public function getTrigger(): string
    {
        return (string) $this->triggerName;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = mb_substr($locale, 0, 32);

        return $this;
    }

    public function getLocale(): string
    {
        return (string) $this->locale;
    }

    public function setUserAgentFamily(string $family): self
    {
        $this->userAgentFamily = mb_substr($family, 0, 32);

        return $this;
    }

    public function getUserAgentFamily(): string
    {
        return (string) $this->userAgentFamily;
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
