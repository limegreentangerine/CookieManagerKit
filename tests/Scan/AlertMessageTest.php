<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\AlertMessage;
use CookieManagerKit\Scan\IngestSummary;

final class AlertMessageTest extends TestCase
{
    private static function summary(int $new = 2, int $changed = 1, int $unclassified = 3, array $items = []): IngestSummary
    {
        return new IngestSummary($new, $changed, $unclassified, $items);
    }

    public function testSubjectAndBodyCarryTheCounts(): void
    {
        $message = AlertMessage::build('example.com', self::summary(2, 1, 3, [['kind' => 'cookie', 'name' => '_ga', 'host' => 'example.com']]), false, 60, 'https://example.com/dashboard/cookie_manager/findings');

        $this->assertSame('Cookie scan: 2 new, 1 changed on example.com', $message['subject']);
        $this->assertStringContainsString('2 new', $message['body']);
        $this->assertStringContainsString('1 changed', $message['body']);
        $this->assertStringContainsString('3 findings still need a category', $message['body']);
        $this->assertStringContainsString('- cookie: _ga (example.com)', $message['body']);
        $this->assertStringContainsString('https://example.com/dashboard/cookie_manager/findings', $message['body']);
        $this->assertStringNotContainsString('runtime scan', $message['body']);
    }

    public function testTheStaleWarningAppearsOnlyWhenStale(): void
    {
        $message = AlertMessage::build('example.com', self::summary(), true, 60, '');

        $this->assertStringContainsString('No runtime scan has run in the last 60 days', $message['body']);
    }

    public function testNamesCannotInjectLinesOrHeaders(): void
    {
        $hostile = "x\r\nBcc: evil@example.com\r\n\r\nClick https://evil.example.net";
        $message = AlertMessage::build("example.com\r\nBcc: a@b.c", self::summary(1, 0, 0, [['kind' => 'cookie', 'name' => $hostile, 'host' => "h\nost"]]), false, 60, '');

        $this->assertStringNotContainsString("\r", $message['subject'] . $message['body']);
        $this->assertStringNotContainsString("\n", $message['subject']);
        $this->assertSame(1, substr_count($message['body'], 'Bcc:'));
        $this->assertStringContainsString('- cookie: x Bcc: evil@example.com Click https://evil.example.net (h ost)', $message['body']);
    }

    public function testLongNamesAreCapped(): void
    {
        $message = AlertMessage::build('example.com', self::summary(1, 0, 0, [['kind' => 'cookie', 'name' => str_repeat('n', 5000), 'host' => 'example.com']]), false, 60, '');

        $this->assertLessThan(1000, strlen($message['body']));
    }

    public function testAnUnsafeFindingsUrlIsLeftOut(): void
    {
        $message = AlertMessage::build('example.com', self::summary(), false, 60, 'javascript:alert(1)');

        $this->assertStringNotContainsString('javascript', $message['body']);
    }

    public function testTheSiteHostInTheSubjectIsReducedToHostCharacters(): void
    {
        $message = AlertMessage::build('<b>exa mple.com</b>', self::summary(), false, 60, '');

        $this->assertSame('Cookie scan: 2 new, 1 changed on bexample.comb', $message['subject']);
    }

    public function testMarkupInNamesIsPlainTextNotAnHtmlPart(): void
    {
        $message = AlertMessage::build('example.com', self::summary(1, 0, 0, [['kind' => 'cookie', 'name' => '<script>alert(1)</script>', 'host' => '<img src=x onerror=1>']]), false, 60, '');

        // The message is sent as text/plain only, so markup is inert; it is still carried as literal text, never decoded or wrapped.
        $this->assertStringContainsString('- cookie: <script>alert(1)</script> (<img src=x onerror=1>)', $message['body']);
        $this->assertStringNotContainsString('<html', strtolower($message['body']));
    }

    public function testBidiAndZeroWidthControlsAreFlattened(): void
    {
        $name = "a\u{202E}b\u{200B}c\u{2028}d\u{0085}e";
        $message = AlertMessage::build('example.com', self::summary(1, 0, 0, [['kind' => 'cookie', 'name' => $name, 'host' => 'h.example.com']]), false, 60, '');

        $this->assertStringContainsString('- cookie: a b c d e (h.example.com)', $message['body']);
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        $message = AlertMessage::build('example.com', self::summary(1, 0, 0, [['kind' => 'cookie', 'name' => "bad\xC3\x28name", 'host' => 'h.example.com']]), false, 60, '');

        $this->assertTrue(mb_check_encoding($message['body'], 'UTF-8'));
    }

    public function testAHostileFindingsUrlIsFlattenedToOneLine(): void
    {
        $message = AlertMessage::build('example.com', self::summary(), false, 60, "https://example.com/f\r\nBcc: evil@example.com");

        $this->assertStringNotContainsString("\r", $message['body']);
        $this->assertSame(0, substr_count($message['body'], "\nBcc:"));
    }

    public function testTheSubjectIsBoundedWhateverTheHost(): void
    {
        $message = AlertMessage::build(str_repeat('a', 5000), self::summary(), false, 60, '');

        $this->assertLessThan(300, strlen($message['subject']));
    }
}
