<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Support\ErrorLog;

/**
 * Emails the alert to the configured address through Concrete's mail service (plain text only). The recipient is
 * checked again here as exactly one plain address: Concrete's `to()` accepts comma-separated lists, so anything that is
 * not a single address (a list, a display name, a line break) is not mailed. A failure is logged and swallowed: a mail
 * problem must never fail a scan.
 */
final class MailAlerts implements AlertSender
{
    private const MAX_ADDRESS = 255;

    /**
     * @param (\Closure(): object)|null $mail a factory for the mail service; defaults to Concrete's
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly string $siteHost,
        private readonly string $findingsUrl,
        private readonly ?\Closure $mail = null,
    ) {}

    private static function isSingleAddress(string $address): bool
    {
        return $address !== ''
            && strlen($address) <= self::MAX_ADDRESS
            && preg_match('/^[^\s,;<>"\x00-\x1f\x7f]+\z/', $address) === 1
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function send(ScanRun $run, IngestSummary $summary, bool $runtimeStale): void
    {
        $to = $this->settings->getScanAlertEmail();

        if (!self::isSingleAddress($to)) {
            return;
        }

        try {
            $message = AlertMessage::build($this->siteHost, $summary, $runtimeStale, $this->settings->getScanStaleDays(), $this->findingsUrl);
            $mail = ($this->mail ?? static fn(): object => app('mail'))();

            $mail->to($to);
            $mail->setSubject($message['subject']);
            $mail->setBody($message['body']);
            $mail->sendMail();
        } catch (\Throwable $e) {
            ErrorLog::record($e);
        }
    }
}
