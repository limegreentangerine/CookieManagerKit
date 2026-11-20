<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Settings;

final class SettingsTest extends TestCase
{
    public function testIconFileIdIsOptionalAndSanitised(): void
    {
        $settings = new Settings();
        $this->assertNull($settings->getIconFileId());

        $this->assertSame(42, $settings->setIconFileId('42')->getIconFileId());
        $this->assertNull($settings->setIconFileId('')->getIconFileId());
        $this->assertNull($settings->setIconFileId('abc')->getIconFileId());
        $this->assertNull($settings->setIconFileId(-3)->getIconFileId());
        $this->assertNull($settings->setIconFileId(null)->getIconFileId());
    }

    public function testDefaults(): void
    {
        $settings = new Settings();

        $this->assertSame('bottom-right', $settings->getPosition());
        $this->assertSame('stacked', $settings->getLayout());
        $this->assertSame('inherit', $settings->getFont());
        $this->assertSame(8, $settings->getRadius());
        $this->assertTrue($settings->hasShadow());
        $this->assertFalse($settings->ignoresContrast());
        $this->assertTrue($settings->isWidgetEnabled());
        $this->assertSame(180, $settings->getLifetimeDays());
        $this->assertSame(1, $settings->getPolicyVersion());
        $this->assertSame('#ffffff', $settings->getToken('bg'));
        $this->assertSame(['bg', 'fg', 'accent', 'buttonBg', 'buttonFg', 'widgetBg', 'widgetFg'], array_keys($settings->getTokens()));
        $this->assertSame('text', $settings->getWidgetDisplay());
        $this->assertFalse($settings->hasCustomWidgetColours());
    }

    public function testSettingsButtonColoursFollowTheButtonUntilSet(): void
    {
        $settings = (new Settings())->setToken('buttonBg', '#123456')->setToken('buttonFg', '#fefefe');
        $this->assertSame('#123456', $settings->getToken('widgetBg'));
        $this->assertSame('#fefefe', $settings->getToken('widgetFg'));

        $settings->setToken('widgetBg', '#ABCDEF')->setToken('widgetFg', '#000000');
        $this->assertTrue($settings->hasCustomWidgetColours());
        $this->assertSame('#abcdef', $settings->getToken('widgetBg'));

        $settings->setToken('widgetBg', null)->setToken('widgetFg', null);
        $this->assertFalse($settings->hasCustomWidgetColours());
        $this->assertSame('#123456', $settings->getToken('widgetBg'));
    }

    public function testSettingsButtonDisplayIsAllowlisted(): void
    {
        $settings = new Settings();
        $this->assertSame('both', $settings->setWidgetDisplay('both')->getWidgetDisplay());
        $this->assertSame('text', $settings->setWidgetDisplay('<img>')->getWidgetDisplay());
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
            ->setIgnoreContrast('1')
            ->setWidgetEnabled(false)
            ->setLifetimeDays('365');

        $this->assertSame('center-modal', $settings->getPosition());
        $this->assertSame('inline', $settings->getLayout());
        $this->assertSame('system', $settings->getFont());
        $this->assertSame('#aa00ff', $settings->getToken('accent'));
        $this->assertFalse($settings->hasShadow());
        $this->assertTrue($settings->ignoresContrast());
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
