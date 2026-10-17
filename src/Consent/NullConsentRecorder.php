<?php

namespace CookieManagerKit\Consent;

final class NullConsentRecorder implements ConsentRecorder
{
    public function record(ConsentChoice $choice): ?string
    {
        return null;
    }
}
