<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\StyleRules;
use Doctrine\ORM\EntityManagerInterface;
use ClassKit\Entity\Core\UpdatedGuidEntity;

/**
 * Package settings. There is only ever one row; nothing is stored in the package file config.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkSettings",
 *      options={"comment": "Cookie Manager settings"}
 * )
 */
class Settings extends UpdatedGuidEntity
{
    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "bottom-right", "comment": "Banner position"})
     */
    protected $position = 'bottom-right';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "stacked", "comment": "Button layout"})
     */
    protected $layout = 'stacked';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "inherit", "comment": "Font: inherit from the theme or system"})
     */
    protected $font = 'inherit';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 8, "comment": "Corner radius in px"})
     */
    protected $radius = 8;

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": true, "comment": "Drop shadow"})
     */
    protected $shadow = true;

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": true, "comment": "Show the floating cookie settings button"})
     */
    protected $widgetEnabled = true;

    /**
     * @ORM\Column(type="string", length=7, nullable=false, options={"default": "#ffffff", "comment": "Background colour"})
     */
    protected $tokenBg = '#ffffff';

    /**
     * @ORM\Column(type="string", length=7, nullable=false, options={"default": "#111111", "comment": "Text colour"})
     */
    protected $tokenFg = '#111111';

    /**
     * @ORM\Column(type="string", length=7, nullable=false, options={"default": "#0b5fff", "comment": "Accent colour"})
     */
    protected $tokenAccent = '#0b5fff';

    /**
     * @ORM\Column(type="string", length=7, nullable=false, options={"default": "#0b5fff", "comment": "Button background"})
     */
    protected $tokenButtonBg = '#0b5fff';

    /**
     * @ORM\Column(type="string", length=7, nullable=false, options={"default": "#ffffff", "comment": "Button text"})
     */
    protected $tokenButtonFg = '#ffffff';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 180, "comment": "Days a visitor's choice is remembered"})
     */
    protected $lifetimeDays = 180;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 1, "comment": "Bumping this asks every visitor again"})
     */
    protected $policyVersion = 1;

    /**
     * The saved settings, or an unsaved instance with the defaults if none have been saved yet. Never writes.
     */
    public static function get(): self
    {
        $settings = app(EntityManagerInterface::class)->getRepository(self::class)->findOneBy([]);

        return $settings instanceof self ? $settings : new self();
    }

    public function getPosition(): string
    {
        return StyleRules::choice($this->position, StyleRules::POSITIONS, 'bottom-right');
    }

    public function setPosition($value): self
    {
        $this->position = StyleRules::choice($value, StyleRules::POSITIONS, 'bottom-right');

        return $this;
    }

    public function getLayout(): string
    {
        return StyleRules::choice($this->layout, StyleRules::LAYOUTS, 'stacked');
    }

    public function setLayout($value): self
    {
        $this->layout = StyleRules::choice($value, StyleRules::LAYOUTS, 'stacked');

        return $this;
    }

    public function getFont(): string
    {
        return StyleRules::choice($this->font, StyleRules::FONTS, 'inherit');
    }

    public function setFont($value): self
    {
        $this->font = StyleRules::choice($value, StyleRules::FONTS, 'inherit');

        return $this;
    }

    public function getRadius(): int
    {
        return StyleRules::radius($this->radius);
    }

    public function setRadius($value): self
    {
        $this->radius = StyleRules::radius($value);

        return $this;
    }

    public function hasShadow(): bool
    {
        return (bool) $this->shadow;
    }

    public function setShadow($value): self
    {
        $this->shadow = (bool) $value;

        return $this;
    }

    public function isWidgetEnabled(): bool
    {
        return (bool) $this->widgetEnabled;
    }

    public function setWidgetEnabled($value): self
    {
        $this->widgetEnabled = (bool) $value;

        return $this;
    }

    private const TOKEN_PROPERTIES = [
        'bg' => 'tokenBg',
        'fg' => 'tokenFg',
        'accent' => 'tokenAccent',
        'buttonBg' => 'tokenButtonBg',
        'buttonFg' => 'tokenButtonFg',
    ];

    public function getToken(string $name): string
    {
        $property = self::TOKEN_PROPERTIES[$name] ?? null;

        return $property === null ? '' : StyleRules::hex($this->{$property}, StyleRules::DEFAULT_TOKENS[$name]);
    }

    public function setToken(string $name, $value): self
    {
        $property = self::TOKEN_PROPERTIES[$name] ?? null;

        if ($property !== null) {
            $this->{$property} = StyleRules::hex($value, StyleRules::DEFAULT_TOKENS[$name]);
        }

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getTokens(): array
    {
        $tokens = [];

        foreach (array_keys(self::TOKEN_PROPERTIES) as $name) {
            $tokens[$name] = $this->getToken($name);
        }

        return $tokens;
    }

    public function getLifetimeDays(): int
    {
        return StyleRules::lifetime($this->lifetimeDays);
    }

    public function setLifetimeDays($value): self
    {
        $this->lifetimeDays = StyleRules::lifetime($value);

        return $this;
    }

    public static function isValidLifetime(mixed $value): bool
    {
        return StyleRules::isValidLifetime($value);
    }

    public function getPolicyVersion(): int
    {
        return max(1, (int) $this->policyVersion);
    }

    /**
     * Visitors whose stored choice carries an older version are asked again.
     */
    public function bumpPolicyVersion(): self
    {
        $this->policyVersion = $this->getPolicyVersion() + 1;

        return $this;
    }
}
