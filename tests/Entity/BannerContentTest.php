<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\BannerContent;

final class BannerContentTest extends TestCase
{
    public function testAllTextIsPlain(): void
    {
        $content = (new BannerContent())
            ->setLocale('en_GB')
            ->setTitle('<script>alert(1)</script>Privacy')
            ->setBody("Line one\n<img src=x onerror=alert(1)>Line two")
            ->setAcceptAllLabel('<b>Accept</b>');

        $this->assertSame('alert(1)Privacy', $content->getTitle());
        $this->assertSame("Line one\nLine two", $content->getBody());
        $this->assertSame('Accept', $content->getAcceptAllLabel());
    }

    public function testLabelsAreCapped(): void
    {
        $this->assertSame(64, mb_strlen((new BannerContent())->setSaveLabel(str_repeat('a', 500))->getSaveLabel()));
        $this->assertSame(255, mb_strlen((new BannerContent())->setTitle(str_repeat('a', 500))->getTitle()));
    }

    public function testPolicyPageIdMustBePositive(): void
    {
        $this->assertSame(12, (new BannerContent())->setPolicyPageId('12')->getPolicyPageId());
        $this->assertNull((new BannerContent())->setPolicyPageId('0')->getPolicyPageId());
        $this->assertNull((new BannerContent())->setPolicyPageId('abc')->getPolicyPageId());
        $this->assertNull((new BannerContent())->setPolicyPageId('-3')->getPolicyPageId());
        $this->assertNull((new BannerContent())->setPolicyPageId(null)->getPolicyPageId());
    }

    public function testInvalidLocaleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BannerContent())->setLocale('en-GB');
    }

    public function testIsEmpty(): void
    {
        $this->assertTrue((new BannerContent())->setLocale('cy_GB')->isEmpty());
        $this->assertFalse((new BannerContent())->setLocale('cy_GB')->setTitle('Hi')->isEmpty());
        $this->assertFalse((new BannerContent())->setLocale('cy_GB')->setPolicyPageId(4)->isEmpty());
    }
}
