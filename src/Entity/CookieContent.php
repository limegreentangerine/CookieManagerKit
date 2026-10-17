<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\UpdatedEntity;

/**
 * One locale's purpose text for a cookie.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkCookieContents",
 *      indexes={@ORM\Index(name="idx_cmk_cc2_locale", columns={"locale"})},
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uniq_cmk_cc2_cookie_locale", columns={"cookieID","locale"})},
 *      options={"comment": "Per-locale content for cookies"}
 * )
 */
class CookieContent extends UpdatedEntity
{
    /**
     * @ORM\ManyToOne(targetEntity="Cookie", inversedBy="contents")
     * @ORM\JoinColumn(name="cookieID", referencedColumnName="id", onDelete="CASCADE", nullable=false)
     */
    protected $cookie;

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"comment": "Locale ID, eg. en_GB"})
     */
    protected $locale = '';

    /**
     * @ORM\Column(type="text", nullable=false, options={"comment": "Purpose (plain text)"})
     */
    protected $purpose = '';

    public function getCookie(): ?Cookie
    {
        return $this->cookie;
    }

    public function setCookie(Cookie $cookie): self
    {
        $this->cookie = $cookie;

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

    public function getPurpose(): string
    {
        return (string) $this->purpose;
    }

    public function setPurpose(?string $purpose): self
    {
        $this->purpose = Text::multiline($purpose, 500);

        return $this;
    }
}
