<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Settings;

final class SettingsComplianceTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = new Settings();

        $this->assertSame(24, $settings->getConsentLogRetentionMonths());
        $this->assertFalse($settings->isConsentModeEnabled());
        $this->assertSame('opt_in', $settings->getDefaultModel());
    }

    public function testRetentionIsClamped(): void
    {
        $settings = new Settings();

        $this->assertSame(1, $settings->setConsentLogRetentionMonths(0)->getConsentLogRetentionMonths());
        $this->assertSame(120, $settings->setConsentLogRetentionMonths(9999)->getConsentLogRetentionMonths());
        $this->assertSame(24, $settings->setConsentLogRetentionMonths('abc')->getConsentLogRetentionMonths());
        $this->assertSame(36, $settings->setConsentLogRetentionMonths('36')->getConsentLogRetentionMonths());
    }

    public function testOnlyTwoModelsExist(): void
    {
        $settings = new Settings();

        $this->assertSame('opt_out', $settings->setDefaultModel('opt_out')->getDefaultModel());
        $this->assertSame('opt_in', $settings->setDefaultModel('"><script>')->getDefaultModel());
        $this->assertSame('opt_in', $settings->setDefaultModel(null)->getDefaultModel());
    }

    public function testConsentModeToggle(): void
    {
        $this->assertTrue((new Settings())->setConsentModeEnabled('1')->isConsentModeEnabled());
        $this->assertFalse((new Settings())->setConsentModeEnabled(null)->isConsentModeEnabled());
    }
}
