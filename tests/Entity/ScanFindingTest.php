<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\ScanFinding;

final class ScanFindingTest extends TestCase
{
    public function testIdentityIsAFingerprintOfKindNameAndHost(): void
    {
        $a = (new ScanFinding())->identify('cookie', '_ga', 'example.com');
        $b = (new ScanFinding())->identify('cookie', '_ga', 'example.com');
        $c = (new ScanFinding())->identify('storage', '_ga', 'example.com');

        $this->assertSame($a->getFingerprint(), $b->getFingerprint());
        $this->assertNotSame($a->getFingerprint(), $c->getFingerprint());
        $this->assertSame(40, strlen($a->getFingerprint()));
    }

    public function testFingerprintDoesNotCollideAcrossFieldBoundaries(): void
    {
        $this->assertNotSame(
            ScanFinding::fingerprintFor('cookie', 'ab', 'c.example.com'),
            ScanFinding::fingerprintFor('cookie', 'a', 'bc.example.com'),
        );
    }

    public function testTableAndUniqueFingerprint(): void
    {
        $doc = (string) (new \ReflectionClass(ScanFinding::class))->getDocComment();

        $this->assertStringContainsString('name="cmkScanFindings"', $doc);
        $this->assertStringContainsString('columns={"fingerprint"}', $doc);
    }

    public function testDeletingAServiceKeepsTheFinding(): void
    {
        $property = (new \ReflectionClass(ScanFinding::class))->getProperty('service');

        $this->assertStringContainsString('onDelete="SET NULL"', (string) $property->getDocComment());
    }

    public function testKindAndStatusFallBackToSafeValues(): void
    {
        $finding = (new ScanFinding())->identify('<b>', 'x', 'example.com')->setStatus('hacked');

        $this->assertSame(ScanFinding::RESOURCE, $finding->getKind());
        $this->assertSame(ScanFinding::UNCLASSIFIED, $finding->getStatus());
    }

    public function testSourcesAreAddedOnceAndRemoved(): void
    {
        $finding = (new ScanFinding())->addSource('crawl')->addSource('crawl')->addSource('beacon')->addSource('nonsense');

        $this->assertSame(['crawl', 'beacon'], $finding->sources());
        $this->assertTrue($finding->hasSource('beacon'));

        $finding->removeSource('crawl');
        $this->assertSame(['beacon'], $finding->sources());

        $finding->removeSource('beacon');
        $this->assertSame([], $finding->sources());
    }

    public function testGoneRemembersTheStatusItReplaced(): void
    {
        $finding = (new ScanFinding())->setStatus(ScanFinding::MATCHED);

        $finding->markGone();
        $this->assertSame(ScanFinding::GONE, $finding->getStatus());

        $finding->restore();
        $this->assertSame(ScanFinding::MATCHED, $finding->getStatus());
    }

    public function testMarkingGoneTwiceKeepsTheOriginalMemory(): void
    {
        $finding = (new ScanFinding())->setStatus(ScanFinding::APPROVED);

        $finding->markGone()->markGone();
        $finding->restore();

        $this->assertSame(ScanFinding::APPROVED, $finding->getStatus());
    }

    public function testRestoreWithoutAMemoryFallsBackToUnclassified(): void
    {
        $finding = (new ScanFinding())->setStatus(ScanFinding::GONE);

        $finding->restore();

        $this->assertSame(ScanFinding::UNCLASSIFIED, $finding->getStatus());
    }

    public function testOnlyUnreviewedStatusesAreReviewable(): void
    {
        foreach ([ScanFinding::MATCHED, ScanFinding::UNCLASSIFIED] as $status) {
            $this->assertTrue((new ScanFinding())->setStatus($status)->isReviewable(), $status);
        }

        foreach ([ScanFinding::APPROVED, ScanFinding::IGNORED, ScanFinding::GONE] as $status) {
            $this->assertFalse((new ScanFinding())->setStatus($status)->isReviewable(), $status);
        }
    }

    public function testSuggestionIsStoredAndCleared(): void
    {
        $finding = (new ScanFinding())->setSuggestion('analytics', 'Google');
        $this->assertSame(['analytics', 'Google'], [$finding->getSuggestedCategory(), $finding->getSuggestedVendor()]);

        $finding->setSuggestion(null, null);
        $this->assertNull($finding->getSuggestedCategory());
        $this->assertNull($finding->getSuggestedVendor());
    }
}
