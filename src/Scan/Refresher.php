<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Support\ErrorLog;
use CookieManagerKit\Entity\ClassificationEntry;

/**
 * Replaces the `refreshed` classification rows with a validated download. Nothing is stored unless the whole file
 * passes; any failure leaves the previous rows exactly as they were.
 *
 * Whether a refresh is allowed at all (Settings::classificationRefresh) is the caller's responsibility: this class
 * does not check it.
 */
final class Refresher
{
    public function __construct(
        private readonly SnapshotSource $source,
        private readonly ClassificationStore $store,
    ) {}

    /**
     * @return int how many rows were stored
     *
     * @throws FetchFailed       when the download fails
     * @throws \RuntimeException when the download is not a valid snapshot
     */
    public function refresh(): int
    {
        try {
            $rows = ClassificationFile::parseOpenCookieDatabase($this->source->fetch());

            if ($rows === null) {
                throw new \RuntimeException('The downloaded classification data failed validation and was discarded.');
            }

            $this->store->replaceOrigin(ClassificationEntry::REFRESHED, $rows);
        } catch (\Throwable $e) {
            // Fixed message only: neither the response body nor any exception text is logged. The old rows stay.
            ErrorLog::record(new \RuntimeException('The classification refresh failed; the previous rows were kept.'));

            throw $e;
        }

        return count($rows);
    }
}
