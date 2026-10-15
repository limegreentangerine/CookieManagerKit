<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Settings;

final class SettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = new Settings();

        $this->assertSame('bottom-right', $settings->getPosition());
        $this->assertSame('stacked', $settings->getLayout());
        $this->assertSame('inherit', $settings->getFont());
        $this->assertSame(8, $settings->getRadius());
        $this->assertTrue($settings->hasShadow());
        $this->assertTrue($settings->isWidgetEnabled());
        $this->assertSame(180, $settings->getLifetimeDays());
        $this->assertSame(1, $settings->getPolicyVersion());
        $this->assertSame('#ffffff', $settings->getToken('bg'));
        $this->assertSame(['bg', 'fg', 'accent', 'buttonBg', 'buttonFg'], array_keys($settings->getTokens()));
    }

    public function testHostileValuesFallBackToDefaults(): void
    {
        $settings = (new Settings())
            ->setPosition('top"><script>')
            ->setLayout('x')
            ->setFont('Comic Sans; }')
            ->setToken('bg', 'red;}</style>')
            ->setRadius('999');

        $this->assertSame('bottom-right', $settings->getPosition());
        $this->assertSame('stacked', $settings->getLayout());
        $this->assertSame('inherit', $settings->getFont());
        $this->assertSame('#ffffff', $settings->getToken('bg'));
        $this->assertSame(32, $settings->getRadius());
    }

    public function testValidValuesAreStored(): void
    {
        $settings = (new Settings())
            ->setPosition('center-modal')
            ->setLayout('inline')
            ->setFont('system')
            ->setToken('accent', '#AA00FF')
            ->setShadow(false)
            ->setWidgetEnabled(false)
            ->setLifetimeDays('365');

        $this->assertSame('center-modal', $settings->getPosition());
        $this->assertSame('inline', $settings->getLayout());
        $this->assertSame('system', $settings->getFont());
        $this->assertSame('#aa00ff', $settings->getToken('accent'));
        $this->assertFalse($settings->hasShadow());
        $this->assertFalse($settings->isWidgetEnabled());
        $this->assertSame(365, $settings->getLifetimeDays());
    }

    public function testUnknownTokenNamesAreIgnored(): void
    {
        $settings = (new Settings())->setToken('evil', '#000000');

        $this->assertArrayNotHasKey('evil', $settings->getTokens());
        $this->assertSame('', $settings->getToken('evil'));
    }

    public function testBumpPolicyVersion(): void
    {
        $settings = new Settings();
        $settings->bumpPolicyVersion()->bumpPolicyVersion();

        $this->assertSame(3, $settings->getPolicyVersion());
    }
}
