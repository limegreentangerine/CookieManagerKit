<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanRun;

interface AlertSender
{
    public function send(ScanRun $run, IngestSummary $summary, bool $runtimeStale): void;
}
