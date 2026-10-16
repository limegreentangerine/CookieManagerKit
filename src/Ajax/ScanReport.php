<?php

namespace CookieManagerKit\Ajax;

use Concrete\Core\Http\Request;
use CookieManagerKit\Scan\Host;
use CookieManagerKit\Scan\ScanRuns;
use CookieManagerKit\Scan\Services;
use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Scan\ScanToken;
use CookieManagerKit\Scan\ScanContext;
use CookieManagerKit\Scan\BeaconReport;
use CookieManagerKit\Scan\BeaconScanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\DBAL\Exception\TableNotFoundException;
use CookieManagerKit\Support\{ErrorLog, Output, RateLimit, RequestGuard};

/**
 * Receives what the scan-mode beacon saw on a page. Public (pages are cached and anonymous), so it is defended like
 * the consent endpoint: same-origin only, JSON only, a strict schema and size cap, a per-run rate limit, and it
 * additionally needs the signed scan-mode token, which only an admin who started scan mode holds. It stores names and
 * hosts only, answers with a fixed body, and never reflects anything from the request.
 *
 * Status contract (the body and headers are identical for every refusal):
 *   200  accepted
 *   401  the token or run is the problem: missing, malformed, forged or expired token, no server secret, or the run
 *        is not a running beacon run / its window has closed. The beacon script must stop reporting.
 *   400  anything else: wrong method, cross-origin, bad content type, body, size or keys, rate limit, and any
 *        storage or ingest failure (logged server-side).
 */
class ScanReport
{
    private const PER_MINUTE = 120;

    /** Every refusal looks the same from outside, so a probe learns nothing about which check failed. */
    private const REFUSED = 400;
    private const TOKEN_REFUSED = 401;

    private function status(Request $request): int
    {
        if (!$request->isMethod('POST')) {
            return self::REFUSED;
        }

        if (!RequestGuard::isSameOrigin($request->headers->get('Origin'), $request->headers->get('Referer'), $request->getHttpHost())) {
            return self::REFUSED;
        }

        // Browsers say where a request came from; a cross-site (or unlabelled-but-hostile) fetch is refused outright.
        if (!in_array($request->headers->get('Sec-Fetch-Site'), [null, 'same-origin'], true)) {
            return self::REFUSED;
        }

        $now = time();
        $token = ScanToken::verify($request->cookies->get(ScanToken::COOKIE), $now, ScanToken::secret());

        if ($token === null) {
            return self::TOKEN_REFUSED;
        }

        if (!RateLimit::allow('scan/' . $token['run'], self::PER_MINUTE)) {
            return self::REFUSED;
        }

        $report = BeaconReport::parse($request->headers->get('Content-Type'), (string) $request->getContent());
        $host = Host::normalise($request->getHost());

        if ($report === null || $host === null) {
            return self::REFUSED;
        }

        $em = app(EntityManagerInterface::class);
        $run = $em->getRepository(ScanRun::class)->find($token['run']);

        // A token for a session the admin ended (or that ran out) must not keep feeding findings.
        if (!$run instanceof ScanRun || !ScanRuns::acceptsReports($run, $now)) {
            return self::TOKEN_REFUSED;
        }

        $result = (new BeaconScanner())->scan(new ScanContext($host, [], $report));
        $summary = Services::ingestor()->ingest($result->findings, ScanRun::BEACON, $now, false);

        $run->setPagesScanned($run->getPagesScanned() + 1)
            ->setCounts($run->getNewCount() + $summary->new, $run->getChangedCount() + $summary->changed, $summary->unclassified);
        $em->flush();

        return 200;
    }

    public function record(): Response
    {
        try {
            $status = $this->status(app(Request::class));
        } catch (TableNotFoundException) {
            // Before the package Update has created the tables there is nothing to record into.
            $status = self::REFUSED;
        } catch (\Throwable $e) {
            $status = self::REFUSED;
            // Fixed message plus the class name only: exception text can carry request-derived data.
            ErrorLog::record(new \RuntimeException('Scan report failed: ' . $e::class));
        }

        return new Response(
            Output::json($status === 200 ? ['ok' => true] : ['error' => 'invalid']),
            $status,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
