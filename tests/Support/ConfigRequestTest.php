<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\ConfigRequest;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConfigRequestTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string}>
     */
    public static function pathProvider(): array
    {
        return [
            'plain' => ['/cookie-manager/config/en_GB.json', 'en_GB'],
            'with index.php' => ['/index.php/cookie-manager/config/cy_GB.json', 'cy_GB'],
            'subdirectory install' => ['/site/cookie-manager/config/en.json', 'en'],
            'script payload' => ['/cookie-manager/config/<script>.json', null],
            'traversal' => ['/cookie-manager/config/../../etc/passwd.json', null],
            'encoded traversal' => ['/cookie-manager/config/..%2F..%2Fx.json', null],
            'too long' => ['/cookie-manager/config/' . str_repeat('a', 33) . '.json', null],
            'wrong extension' => ['/cookie-manager/config/en_GB.php', null],
            'no extension' => ['/cookie-manager/config/en_GB', null],
            'trailing newline' => ["/cookie-manager/config/en_GB.json\n", null],
            'empty segment' => ['/cookie-manager/config/.json', null],
            'other route' => ['/something/else', null],
        ];
    }

    #[DataProvider('pathProvider')]
    public function testLocaleFromPath(string $path, ?string $expected): void
    {
        $this->assertSame($expected, ConfigRequest::localeFromPath($path));
    }

    public function testMatchesEtag(): void
    {
        $this->assertTrue(ConfigRequest::matchesEtag('"abc"', '"abc"'));
        $this->assertTrue(ConfigRequest::matchesEtag('W/"abc"', '"abc"'));
        $this->assertTrue(ConfigRequest::matchesEtag('"zzz", "abc"', '"abc"'));
        $this->assertFalse(ConfigRequest::matchesEtag('"abd"', '"abc"'));
        $this->assertFalse(ConfigRequest::matchesEtag(null, '"abc"'));
        $this->assertFalse(ConfigRequest::matchesEtag('', '"abc"'));
    }
}
