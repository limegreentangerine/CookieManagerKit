<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use CookieManagerKit\Support\Inert;
use PHPUnit\Framework\TestCase;

final class InertTest extends TestCase
{
    public function testBuildsAnInertExternalScript(): void
    {
        $this->assertSame(
            '<script type="text/plain" data-cmk-category="marketing" src="https://px.example/a.js?x=1&amp;y=2"></script>',
            Inert::script('marketing', 'https://px.example/a.js?x=1&y=2'),
        );
    }

    public function testBuildsAnInertInlineScript(): void
    {
        $this->assertSame(
            '<script type="text/plain" data-cmk-category="analytics">track();</script>',
            Inert::script('analytics', '', 'track();'),
        );
    }

    public function testInlineCodeCannotCloseTheScriptElement(): void
    {
        $html = Inert::script('analytics', '', 'var s="</script><img src=x onerror=alert(1)>";');

        $this->assertSame(1, substr_count(strtolower($html), '</script'));
    }

    public function testAModuleTypeIsRemembered(): void
    {
        $this->assertStringContainsString('data-cmk-type="module"', Inert::script('analytics', '/a.js', '', 'module'));
        $this->assertStringNotContainsString('data-cmk-type', Inert::script('analytics', '/a.js', '', 'x" onerror="y'));
    }

    public function testRefusesBadInput(): void
    {
        $this->assertSame('', Inert::script('Bad Category', '/a.js'));
        $this->assertSame('', Inert::script('analytics', 'javascript:alert(1)'));
        $this->assertSame('', Inert::script('analytics', '//evil.example/a.js'));
        $this->assertSame('', Inert::script('analytics', '', ''));
    }
}
