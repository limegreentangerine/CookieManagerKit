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
    private const TOKEN_PROPERTIES = [
        'bg' => 'tokenBg',
        'fg' => 'tokenFg',
        'accent' => 'tokenAccent',
        'buttonBg' => 'tokenButtonBg',
        'buttonFg' => 'tokenButtonFg',
    ];
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
     * @ORM\Column(type="integer", nullable=false, options={"default": 50, "comment": "Most pages one crawl will fetch"})
     */
    protected $scanMaxPages = 50;

    /**
     * @ORM\Column(type="text", nullable=false, options={"default": "", "comment": "Path patterns the crawl skips, one per line"})
     */
    protected $scanExcludes = '';

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": false, "comment": "Run the scheduled crawl"})
     */
    protected $scanSchedule = false;

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Where scan alerts are emailed"})
     */
    protected $scanAlertEmail = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 60, "comment": "Days without a scan-mode run before it is flagged"})
     */
    protected $scanStaleDays = 60;

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": false, "comment": "Download newer classification data"})
     */
    protected $classificationRefresh = false;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 24, "comment": "Months a browser's consent history is kept after its last decision"})
     */
    protected $consentLogRetentionMonths = 24;

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": false, "comment": "Emit Google Consent Mode v2 signals"})
     */
    protected $consentMode = false;

    /**
     * @ORM\Column(type="string", length=8, nullable=false, options={"default": "opt_in", "comment": "opt_in or opt_out"})
     */
    protected $defaultModel = 'opt_in';

    private static function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        $number = is_int($value) || (is_string($value) && preg_match('/^-?\d{1,9}\z/', trim($value)) === 1) ? (int) $value : $default;

        return max($min, min($max, $number));
    }

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

    public function getScanMaxPages(): int
    {
        return self::clampInt($this->scanMaxPages, 1, 500, 50);
    }

    public function setScanMaxPages($value): self
    {
        $this->scanMaxPages = self::clampInt($value, 1, 500, 50);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getScanExcludes(): array
    {
        return $this->scanExcludes === '' ? [] : explode("\n", (string) $this->scanExcludes);
    }

    public function getScanExcludesText(): string
    {
        return (string) $this->scanExcludes;
    }

    /**
     * Keeps only plain path patterns: a leading slash, then path characters and `*`. Anything else is dropped.
     */
    public function setScanExcludesText($value): self
    {
        $patterns = [];

        foreach (preg_split('/\R/', is_string($value) ? $value : '') ?: [] as $line) {
            $line = trim($line);

            if (preg_match('#^/[A-Za-z0-9/_\-.~%*]{0,199}\z#', $line) === 1) {
                $patterns[] = $line;
            }
        }

        $this->scanExcludes = implode("\n", array_slice(array_values(array_unique($patterns)), 0, 50));

        return $this;
    }

    public function isScanScheduleEnabled(): bool
    {
        return (bool) $this->scanSchedule;
    }

    public function setScanScheduleEnabled($value): self
    {
        $this->scanSchedule = (bool) $value;

        return $this;
    }

    public function getScanAlertEmail(): string
    {
        return (string) $this->scanAlertEmail;
    }

    public function setScanAlertEmail($value): self
    {
        $email = is_string($value) ? trim($value) : '';

        $this->scanAlertEmail = strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';

        return $this;
    }

    public function getScanStaleDays(): int
    {
        return self::clampInt($this->scanStaleDays, 7, 365, 60);
    }

    public function setScanStaleDays($value): self
    {
        $this->scanStaleDays = self::clampInt($value, 7, 365, 60);

        return $this;
    }

    public function isClassificationRefreshEnabled(): bool
    {
        return (bool) $this->classificationRefresh;
    }

    public function setClassificationRefreshEnabled($value): self
    {
        $this->classificationRefresh = (bool) $value;

        return $this;
    }

    public function getConsentLogRetentionMonths(): int
    {
        return self::clampInt($this->consentLogRetentionMonths, 1, 120, 24);
    }

    public function setConsentLogRetentionMonths($value): self
    {
        $this->consentLogRetentionMonths = self::clampInt($value, 1, 120, 24);

        return $this;
    }

    public function isConsentModeEnabled(): bool
    {
        return (bool) $this->consentMode;
    }

    public function setConsentModeEnabled($value): self
    {
        $this->consentMode = (bool) $value;

        return $this;
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel === 'opt_out' ? 'opt_out' : 'opt_in';
    }

    public function setDefaultModel($value): self
    {
        $this->defaultModel = $value === 'opt_out' ? 'opt_out' : 'opt_in';

        return $this;
    }
}
