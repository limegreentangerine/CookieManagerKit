<?php

namespace CookieManagerKit\Consent;

use CookieManagerKit\Support\ErrorLog;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * Binds a ConsentRecorder to the audit log. Until an admin has run the package Update the tables do not exist; that
 * window is silent so a busy site does not flood its log. Any other failure is logged with a fixed-shape message.
 */
final class PersistingConsentRecorder implements ConsentRecorder
{
    /**
     * @param ?\Closure(\Throwable): void $onError receives every failure that is not a missing table; defaults to the error log
     */
    public function __construct(
        private ConsentLogStore $store,
        private ?\Closure $clock = null,
        private ?\Closure $onError = null,
    ) {}

    public function record(ConsentChoice $choice): ?string
    {
        try {
            $now = $this->clock === null ? new \DateTimeImmutable() : ($this->clock)();

            return $this->store->append($choice, $now);
        } catch (TableNotFoundException) {
            return null;
        } catch (\Throwable $e) {
            ($this->onError ?? ErrorLog::record(...))($e);

            return null;
        }
    }
}
