<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Support\Text;
use CookieManagerKit\Entity\Cookie;
use CookieManagerKit\Entity\Service;
use CookieManagerKit\Entity\Category;
use CookieManagerKit\Entity\ScanFinding;

/**
 * What an admin can do with a finding. Approving is the only path from a finding to a Service, which is what
 * visitors see in the banner; nothing here runs without an admin's action (the dashboard controllers check the
 * permission and the CSRF token before calling it). Pure: it changes the entities it is given and persists nothing.
 */
final class Review
{
    private const HOST_KINDS = [ScanFinding::SCRIPT, ScanFinding::IFRAME, ScanFinding::IMAGE, ScanFinding::RESOURCE];

    /**
     * The pre-filled reason for asking visitors again after a service was added to the banner, or null when the
     * service's category is always on (visitors are not asked about necessary cookies) or it has none.
     */
    public static function reconsentReason(Service $service): ?string
    {
        $category = $service->getCategory();

        if ($category === null || $category->isNecessary()) {
            return null;
        }

        return Text::plain('Added ' . $service->getName() . ' to ' . $category->getHandle(), 500);
    }

    /**
     * Attaches the finding to $service, or to a new service named $name in $category, and records the host on the
     * service (and, for a cookie, the cookie name on its cookie list) so the banner and the later declaration can say where it loads from.
     *
     * @throws \DomainException          when the finding is not awaiting review (already approved, ignored or gone); nothing changes
     * @throws \InvalidArgumentException when a new service is wanted without a name or a category
     */
    public static function approve(ScanFinding $finding, ?Category $category, ?Service $service, string $name, string $vendor): Service
    {
        if (!$finding->isReviewable()) {
            throw new \DomainException('This finding is not awaiting review.');
        }

        if ($service === null) {
            if ($category === null) {
                throw new \InvalidArgumentException('A new service needs a category.');
            }

            $name = Text::plain($name);

            if ($name === '') {
                throw new \InvalidArgumentException('A new service needs a name.');
            }

            $service = (new Service())->setName($name)->setVendor(Text::plain($vendor))->setCategory($category);
        }

        if (in_array($finding->getKind(), self::HOST_KINDS, true) && Service::parseDomains($finding->getHost()) !== []) {
            $domains = $service->getDomains();

            if (!in_array($finding->getHost(), $domains, true)) {
                $service->setDomainsText(implode("\n", [...$domains, $finding->getHost()]));
            }
        }

        // The declaration block lists cookies per service; a cookie the scanner found starts as a row the admin completes.
        if ($finding->getKind() === ScanFinding::COOKIE && mb_strlen($finding->getName()) <= Cookie::MAX_NAME) {
            $service->addCookie($finding->getName());
        }

        $finding->setService($service)->setStatus(ScanFinding::APPROVED);

        return $service;
    }

    /**
     * Approves every matched finding that has a vendor and a category that still exists, one service per category and
     * vendor (an existing service with the vendor's name in that category is reused).
     *
     * @param list<ScanFinding>       $findings
     * @param array<string, Category> $categories by handle
     * @param list<Service>           $services   existing services
     *
     * @return list<Service> the services that were created and still need persisting
     */
    public static function approveMatched(array $findings, array $categories, array $services): array
    {
        $byKey = [];
        $created = [];

        foreach ($services as $service) {
            $category = $service->getCategory();

            // An inactive service is hidden from visitors on purpose; approving must not quietly attach to it.
            if ($category !== null && $service->isActive()) {
                $byKey[$category->getHandle() . '|' . mb_strtolower($service->getName())] ??= $service;
            }
        }

        foreach ($findings as $finding) {
            $handle = $finding->getSuggestedCategory();
            $vendor = Text::plain($finding->getSuggestedVendor());

            if ($finding->getStatus() !== ScanFinding::MATCHED || $handle === null || $vendor === '' || !isset($categories[$handle])) {
                continue;
            }

            $key = $handle . '|' . mb_strtolower($vendor);

            if (!isset($byKey[$key])) {
                $byKey[$key] = (new Service())->setName($vendor)->setVendor($vendor)->setCategory($categories[$handle]);
                $created[] = $byKey[$key];
            }

            self::approve($finding, $categories[$handle], $byKey[$key], '', '');
        }

        return $created;
    }

    public static function ignore(ScanFinding $finding): void
    {
        if ($finding->isReviewable()) {
            $finding->setStatus(ScanFinding::IGNORED);
        }
    }

    public static function unignore(ScanFinding $finding, Classifier $classifier): void
    {
        if ($finding->getStatus() === ScanFinding::IGNORED) {
            self::reclassify($finding, $classifier);
        }
    }

    public static function reassign(ScanFinding $finding, string $handle): void
    {
        if ($finding->isReviewable()) {
            $finding->setSuggestion($handle, $finding->getSuggestedVendor())->setStatus(ScanFinding::MATCHED);
        }
    }

    public static function reclassify(ScanFinding $finding, Classifier $classifier): void
    {
        $suggestion = $classifier->classify($finding->getKind(), $finding->getName(), $finding->getHost());

        $finding->setSuggestion($suggestion?->category, $suggestion?->vendor);
        $finding->setStatus($suggestion === null ? ScanFinding::UNCLASSIFIED : ScanFinding::MATCHED);
    }

    /**
     * The classification rule "save as override" would store for this finding, or null when its name could not be
     * stored as a pattern (unusual characters, too long, or a `*` that would turn a literal name into a wildcard).
     *
     * @return array{kind: string, pattern: string}|null
     */
    public static function overrideRule(ScanFinding $finding): ?array
    {
        if (in_array($finding->getKind(), [ScanFinding::COOKIE, ScanFinding::STORAGE], true)) {
            $name = $finding->getName();

            return preg_match('/^[A-Za-z0-9_.:~%+\-]{1,128}\z/', $name) === 1 ? ['kind' => $finding->getKind(), 'pattern' => $name] : null;
        }

        $host = $finding->getHost();

        return Host::normalise($host) === $host ? ['kind' => 'host', 'pattern' => $host] : null;
    }

    /**
     * The category to preselect in an approve form: the suggestion if it still exists, otherwise the first category.
     *
     * @param list<string> $handles
     */
    public static function defaultCategory(?string $suggested, array $handles): ?string
    {
        if ($suggested !== null && in_array($suggested, $handles, true)) {
            return $suggested;
        }

        return $handles[0] ?? null;
    }
}
