<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\EmbedConfig;

final class EmbedConfigTest extends TestCase
{
    private function snapshot(): array
    {
        return [
            'defaults' => ['embedMessage' => 'Default {service}', 'embedAcceptLabel' => 'Default {category}', 'customise' => 'Customise'],
            'banner' => [
                'en_GB' => ['embedMessage' => 'English {service}', 'embedAcceptLabel' => '', 'customise' => 'Customise it'],
                'fr_FR' => ['embedMessage' => '', 'embedAcceptLabel' => 'Accepter {category}', 'customise' => ''],
            ],
            'categories' => [
                ['handle' => 'necessary', 'necessary' => true, 'content' => [], 'services' => [['name' => 'Session', 'domains' => ['example.com']]]],
                ['handle' => 'marketing', 'necessary' => false, 'content' => ['en_GB' => ['title' => 'Marketing']], 'services' => [
                    ['name' => 'YouTube', 'domains' => ['youtube.com']],
                    ['name' => 'NoDomains', 'domains' => []],
                ]],
                ['handle' => 'Bad Handle', 'necessary' => false, 'content' => [], 'services' => [['name' => 'X', 'domains' => ['x.com']]]],
            ],
        ];
    }

    public function testOnlyNonNecessaryServicesWithDomainsAreOffered(): void
    {
        $services = EmbedConfig::fromSnapshot($this->snapshot(), 'en_GB', 'en_GB')['services'];

        $this->assertSame(['YouTube'], array_column($services, 'name'));
        $this->assertSame('marketing', $services[0]['category']);
        $this->assertSame('Marketing', $services[0]['categoryTitle']);
    }

    public function testTextFallsBackFieldByFieldToTheDefaultLocaleThenThePackageDefault(): void
    {
        $en = EmbedConfig::fromSnapshot($this->snapshot(), 'en_GB', 'en_GB')['text'];
        $fr = EmbedConfig::fromSnapshot($this->snapshot(), 'fr_FR', 'en_GB')['text'];

        $this->assertSame(['message' => 'English {service}', 'accept' => 'Default {category}', 'customise' => 'Customise it'], $en);
        $this->assertSame(['message' => 'English {service}', 'accept' => 'Accepter {category}', 'customise' => 'Customise it'], $fr);
    }

    public function testAMalformedSnapshotGivesNoServicesAndBuiltInText(): void
    {
        $config = EmbedConfig::fromSnapshot([], 'en_GB', 'en_GB');

        $this->assertSame([], $config['services']);
        $this->assertNotSame('', $config['text']['message']);
        $this->assertNotSame('', $config['text']['accept']);
    }
}
