<?php

namespace CookieManagerKit\Consent;

/**
 * Extension point: phase 1 binds a no-op; the audit log (phase 3) binds an implementation that stores the choice.
 */
interface ConsentRecorder
{
    public function record(ConsentChoice $choice): void;
}
