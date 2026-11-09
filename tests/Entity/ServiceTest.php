<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Service;

final class ServiceTest extends TestCase
{
    public function testNameAndVendorArePlainText(): void
    {
        $service = (new Service())->setName('<i>Google Analytics</i>')->setVendor("Google\nLLC");

        $this->assertSame('Google Analytics', $service->getName());
        $this->assertSame('Google LLC', $service->getVendor());
    }

    public function testDomainsAreValidatedLowerCasedAndDeduplicated(): void
    {
        $service = (new Service())->setDomainsText(
            "Example.COM\n*.google-analytics.com\nhttps://bad.example.com\njavascript:alert(1)\n<script>\nexample.com\nnot a host\n-bad.com\n",
        );

        $this->assertSame(['example.com', '*.google-analytics.com'], $service->getDomains());
        $this->assertSame("example.com\n*.google-analytics.com", $service->getDomainsText());
    }

    public function testActiveDefaultsToTrue(): void
    {
        $this->assertTrue((new Service())->isActive());
        $this->assertFalse((new Service())->setActive(false)->isActive());
    }

    public function testDescriptionIsPerLocale(): void
    {
        $service = new Service();
        $service->setContent('en_GB', 'Measures <b>visits</b>');
        $service->setContent('en_GB', 'Measures visits');
        $service->setContent('cy_GB', 'Mesur ymweliadau');

        $this->assertCount(2, $service->getContents());
        $this->assertSame('Measures visits', $service->getContent('en_GB')?->getDescription());
        $this->assertNull($service->getContent('de_DE'));
    }
}
