<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use CookieManagerKit\Support\ErrorLog;
use Symfony\Component\HttpFoundation\Cookie;
use Concrete\Core\Page\Controller\DashboardPageController;
use CookieManagerKit\Entity\{ScanFinding, ScanRun, Settings};
use CookieManagerKit\Scan\{CrawlAlreadyRunning, CrawlRunner, ScanRuns, ScanToken, Services, StaleCheck, UnsafeUrl};

/**
 * Overview of scanning: run a crawl now, start or stop scan mode, and see how recent runs went. Every action is a
 * POST with a CSRF token. Nothing from a scan is shown here except counts and times.
 */
class Scanner extends DashboardPageController
{
    private const URL = '/dashboard/cookie_manager/scanner';

    public function view()
    {
        $now = time();

        ScanRuns::closeExpired($this->entityManager, $now);

        $settings = Settings::get();
        $lastBeacon = ScanRuns::lastBeaconAt($this->entityManager);
        $active = $this->entityManager->getRepository(ScanRun::class)->findOneBy(['source' => ScanRun::BEACON, 'status' => ScanRun::RUNNING], ['startedAt' => 'DESC']);
        $counts = [];
        $findings = $this->entityManager->getRepository(ScanFinding::class);

        foreach (ScanFinding::STATUSES as $status) {
            $counts[$status] = $findings->count(['status' => $status]);
        }

        $lastCrawl = $this->entityManager->getRepository(ScanRun::class)->findOneBy(['source' => ScanRun::CRAWL], ['id' => 'DESC']);

        $this->set('lastCrawl', $lastCrawl instanceof ScanRun ? $lastCrawl : null);
        $this->set('runs', ScanRuns::recent($this->entityManager, 20));
        $this->set('counts', $counts);
        $this->set('lastBeacon', $lastBeacon);
        $this->set('stale', StaleCheck::isStale($lastBeacon, $now, $settings->getScanStaleDays()));
        $this->set('staleDays', $settings->getScanStaleDays());
        $this->set('scanModeActive', ScanRuns::acceptsReports($active instanceof ScanRun ? $active : null, $now));
        $this->set('secretMissing', ScanToken::secret() === '');
    }

    public function crawl()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        try {
            @set_time_limit(300);
            $run = Services::crawlRunner()->run();

            if ($run->getStatus() === ScanRun::COMPLETE) {
                $this->flash('success', t('Crawled %1$s pages: %2$s new, %3$s changed.', $run->getPagesScanned(), $run->getNewCount(), $run->getChangedCount()));
            } else {
                $this->flash('error', t('The crawl could not finish. Details are in the cookie_manager log.'));
            }
        } catch (CrawlAlreadyRunning) {
            $this->flash('error', h(CrawlRunner::ALREADY_RUNNING));
        } catch (UnsafeUrl $e) {
            ErrorLog::record($e);
            $this->flash('error', t('The site cannot be crawled. Set the site canonical URL; see the help on the Scanner Settings page for local sites.'));
        } catch (\Throwable $e) {
            ErrorLog::record($e);
            $this->flash('error', t('The crawl could not finish. Details are in the cookie_manager log.'));
        }

        return $this->buildRedirect(self::URL)->send();
    }

    public function startScanMode()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $secret = ScanToken::secret();

        if ($secret === '') {
            $this->flash('error', t('Scan mode needs the site security token, which this installation does not have yet.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $now = time();

        ScanRuns::endRunning($this->entityManager, $now);

        $run = (new ScanRun())->start(ScanRun::BEACON, $now);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $response = $this->buildRedirect(self::URL);
        $expires = $now + ScanToken::LIFETIME;
        $secure = $this->request->isSecure();

        // The signed token is HttpOnly; the marker only tells the page's runtime that this browser is in scan mode.
        $response->headers->setCookie(new Cookie(ScanToken::COOKIE, ScanToken::issue((int) $run->getID(), $now, $secret), $expires, '/', null, $secure, true, false, Cookie::SAMESITE_STRICT));
        $response->headers->setCookie(new Cookie(ScanToken::MARKER, '1', $expires, '/', null, $secure, false, false, Cookie::SAMESITE_STRICT));

        $this->flash('success', t('Scan mode is on in this browser for 30 minutes. Accept all cookies on the banner, then browse the site.'));

        return $response->send();
    }

    public function stopScanMode()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        ScanRuns::endRunning($this->entityManager, time());

        $response = $this->buildRedirect(self::URL);
        $secure = $this->request->isSecure();

        // The same Path, SameSite and Secure attributes the cookies were set with, or the browser keeps them.
        $response->headers->clearCookie(ScanToken::COOKIE, '/', null, $secure, true, Cookie::SAMESITE_STRICT);
        $response->headers->clearCookie(ScanToken::MARKER, '/', null, $secure, false, Cookie::SAMESITE_STRICT);

        $this->flash('success', t('Scan mode is off.'));

        return $response->send();
    }
}
