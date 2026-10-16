<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Scan\AlertSender;
use CookieManagerKit\Scan\IngestSummary;

/**
 * Remembers every alert it was asked to send.
 */
final class RecordingAlerts implements AlertSender
{
    /** @var list<array{ScanRun, IngestSummary, bool}> */
    public array $sent = [];

    public function send(ScanRun $run, IngestSummary $summary, bool $runtimeStale): void
    {
        $this->sent[] = [$run, $summary, $runtimeStale];
    }
}
