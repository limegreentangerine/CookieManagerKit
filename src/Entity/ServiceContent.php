<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\UpdatedEntity;

/**
 * One locale's description of a service.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkServiceContents",
 *      indexes={@ORM\Index(name="idx_cmk_sc_locale", columns={"locale"})},
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uniq_cmk_sc_service_locale", columns={"serviceID","locale"})},
 *      options={"comment": "Per-locale content for services"}
 * )
 */
class ServiceContent extends UpdatedEntity
{
    /**
     * @ORM\ManyToOne(targetEntity="Service", inversedBy="contents")
     * @ORM\JoinColumn(name="serviceID", referencedColumnName="id", onDelete="CASCADE", nullable=false)
     */
    protected $service;

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"comment": "Locale ID, eg. en_GB"})
     */
    protected $locale = '';

    /**
     * @ORM\Column(type="text", nullable=false, options={"comment": "Description (plain text)"})
     */
    protected $description = '';

    public function getService(): ?Service
    {
        return $this->service;
    }

    public function setService(Service $service): self
    {
        $this->service = $service;

        return $this;
    }

    public function getLocale(): string
    {
        return (string) $this->locale;
    }

    public function setLocale(string $locale): self
    {
        if (!Text::isLocale($locale)) {
            throw new \InvalidArgumentException('Invalid locale.');
        }

        $this->locale = $locale;

        return $this;
    }

    public function getDescription(): string
    {
        return (string) $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = Text::multiline($description, 1000);

        return $this;
    }
}
