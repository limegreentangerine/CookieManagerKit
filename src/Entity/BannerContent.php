<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\UpdatedEntity;

/**
 * One locale's banner text. All text is plain; empty labels fall back to built-in English at build time.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkBannerContents",
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uniq_cmk_banner_locale", columns={"locale"})},
 *      options={"comment": "Per-locale banner content"}
 * )
 */
class BannerContent extends UpdatedEntity
{
    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"comment": "Locale ID, eg. en_GB"})
     */
    protected $locale = '';

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Heading"})
     */
    protected $title = '';

    /**
     * @ORM\Column(type="text", nullable=false, options={"comment": "Body text (plain text, line breaks kept)"})
     */
    protected $body = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"default": ""})
     */
    protected $acceptAllLabel = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"default": ""})
     */
    protected $rejectAllLabel = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"default": ""})
     */
    protected $customiseLabel = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"default": ""})
     */
    protected $saveLabel = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"default": ""})
     */
    protected $widgetLabel = '';

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": ""})
     */
    protected $policyLinkText = '';

    /**
     * @ORM\Column(type="integer", nullable=true, options={"comment": "Cookie policy page cID"})
     */
    protected $policyPageId = null;

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

    public function getTitle(): string
    {
        return (string) $this->title;
    }

    public function setTitle(?string $value): self
    {
        $this->title = Text::plain($value);

        return $this;
    }

    public function getBody(): string
    {
        return (string) $this->body;
    }

    public function setBody(?string $value): self
    {
        $this->body = Text::multiline($value);

        return $this;
    }

    public function getAcceptAllLabel(): string
    {
        return (string) $this->acceptAllLabel;
    }

    public function setAcceptAllLabel(?string $value): self
    {
        $this->acceptAllLabel = Text::plain($value, 64);

        return $this;
    }

    public function getRejectAllLabel(): string
    {
        return (string) $this->rejectAllLabel;
    }

    public function setRejectAllLabel(?string $value): self
    {
        $this->rejectAllLabel = Text::plain($value, 64);

        return $this;
    }

    public function getCustomiseLabel(): string
    {
        return (string) $this->customiseLabel;
    }

    public function setCustomiseLabel(?string $value): self
    {
        $this->customiseLabel = Text::plain($value, 64);

        return $this;
    }

    public function getSaveLabel(): string
    {
        return (string) $this->saveLabel;
    }

    public function setSaveLabel(?string $value): self
    {
        $this->saveLabel = Text::plain($value, 64);

        return $this;
    }

    public function getWidgetLabel(): string
    {
        return (string) $this->widgetLabel;
    }

    public function setWidgetLabel(?string $value): self
    {
        $this->widgetLabel = Text::plain($value, 64);

        return $this;
    }

    public function getPolicyLinkText(): string
    {
        return (string) $this->policyLinkText;
    }

    public function setPolicyLinkText(?string $value): self
    {
        $this->policyLinkText = Text::plain($value);

        return $this;
    }

    public function getPolicyPageId(): ?int
    {
        return $this->policyPageId === null ? null : (int) $this->policyPageId;
    }

    public function setPolicyPageId($value): self
    {
        $this->policyPageId = (is_int($value) || (is_string($value) && preg_match('/^\d+\z/', $value) === 1)) && (int) $value > 0
            ? (int) $value
            : null;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->title === '' && $this->body === '' && $this->acceptAllLabel === '' && $this->rejectAllLabel === ''
            && $this->customiseLabel === '' && $this->saveLabel === '' && $this->widgetLabel === ''
            && $this->policyLinkText === '' && $this->policyPageId === null;
    }
}
