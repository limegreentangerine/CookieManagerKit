<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Scan\MailAlerts;
use CookieManagerKit\Scan\IngestSummary;

final class MailAlertsTest extends TestCase
{
    public function testSendsAPlainTextMailToTheConfiguredAddress(): void
    {
        $mailer = new RecordingMailer();
        $settings = (new Settings())->setScanAlertEmail('ops@example.com');
        $alerts = new MailAlerts($settings, 'example.com', 'https://example.com/f', static fn(): object => $mailer);

        $alerts->send(new ScanRun(), new IngestSummary(1, 0, 0, []), false);

        $this->assertSame(['to', 'ops@example.com'], $mailer->calls[0]);
        $this->assertSame('subject', $mailer->calls[1][0]);
        $this->assertSame('body', $mailer->calls[2][0]);
        $this->assertSame(['send'], $mailer->calls[3]);
        $this->assertCount(4, $mailer->calls);
    }

    public function testSendsNothingWithoutAnAddress(): void
    {
        $mailer = new RecordingMailer();
        $alerts = new MailAlerts(new Settings(), 'example.com', '', static fn(): object => $mailer);

        $alerts->send(new ScanRun(), new IngestSummary(1, 0, 0, []), false);

        $this->assertSame([], $mailer->calls);
    }

    public function testAMailFailureNeverEscapes(): void
    {
        $settings = (new Settings())->setScanAlertEmail('ops@example.com');
        $alerts = new MailAlerts($settings, 'example.com', '', static function (): object {
            throw new \RuntimeException('smtp down');
        });

        $alerts->send(new ScanRun(), new IngestSummary(1, 0, 0, []), false);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badRecipients(): array
    {
        return [
            'crlf header injection' => ["ops@example.com\r\nBcc: evil@example.com"],
            'lf only' => ["ops@example.com\nBcc: evil@example.com"],
            'two addresses' => ['a@example.com,b@example.com'],
            'semicolon list' => ['a@example.com;b@example.com'],
            'space' => ['a@example.com b@example.com'],
            'display name' => ['Ops <ops@example.com>'],
            'trailing newline' => ["ops@example.com\n"],
            'too long' => [str_repeat('a', 250) . '@example.com'],
        ];
    }

    /**
     * Settings validates on the way in; the mailer checks again so a value stored any other way (a direct database
     * edit, an older version) can never become a header.
     *
     * @dataProvider badRecipients
     */
    public function testARecipientThatIsNotOneAddressIsNeverMailed(string $recipient): void
    {
        $mailer = new RecordingMailer();
        $settings = new class($recipient) extends Settings {
            public function __construct(private readonly string $raw)
            {
                parent::__construct();
            }

            public function getScanAlertEmail(): string
            {
                return $this->raw;
            }
        };
        $alerts = new MailAlerts($settings, 'example.com', '', static fn(): object => $mailer);

        $alerts->send(new ScanRun(), new IngestSummary(1, 0, 0, []), false);

        $this->assertSame([], $mailer->calls);
    }

    public function testHostileFindingNamesReachTheMailerOnlyAsFlatText(): void
    {
        $mailer = new RecordingMailer();
        $settings = (new Settings())->setScanAlertEmail('ops@example.com');
        $alerts = new MailAlerts($settings, "example.com\r\nBcc: evil@example.com", '', static fn(): object => $mailer);
        $items = [
            ['kind' => 'cookie', 'name' => '<script>alert(1)</script>', 'host' => "evil\r\nBcc: x@example.com"],
            ['kind' => 'cookie', 'name' => str_repeat('z', 100000), 'host' => 'example.com'],
        ];

        $alerts->send(new ScanRun(), new IngestSummary(2, 0, 0, $items), false);

        $this->assertSame('ops@example.com', $mailer->calls[0][1]);
        $this->assertStringNotContainsString("\n", $mailer->calls[1][1]);
        $this->assertStringNotContainsString("\r", $mailer->calls[1][1] . $mailer->calls[2][1]);
        $this->assertSame(0, preg_match('/^Bcc:/mi', $mailer->calls[1][1] . "\n" . $mailer->calls[2][1]));
        $this->assertLessThan(2000, strlen($mailer->calls[2][1]));
    }
}
