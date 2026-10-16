<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Review;
use CookieManagerKit\Entity\Service;
use CookieManagerKit\Entity\Category;
use CookieManagerKit\Scan\Classifier;
use CookieManagerKit\Entity\ScanFinding;

final class ReviewTest extends TestCase
{
    private static function category(string $handle): Category
    {
        return (new Category())->setHandle($handle);
    }

    private static function finding(string $kind, string $name, string $host, string $status = ScanFinding::MATCHED): ScanFinding
    {
        return (new ScanFinding())->identify($kind, $name, $host)->setStatus($status);
    }

    public function testApproveAttachesToAnExistingServiceAndRecordsTheHost(): void
    {
        $analytics = self::category('analytics');
        $service = (new Service())->setName('GA')->setCategory($analytics)->setDomainsText('www.google-analytics.com');
        $finding = self::finding('script', 'cdn.example.net', 'cdn.example.net');

        $result = Review::approve($finding, $analytics, $service, '', '');

        $this->assertSame($service, $result);
        $this->assertSame(['www.google-analytics.com', 'cdn.example.net'], $service->getDomains());
        $this->assertSame(ScanFinding::APPROVED, $finding->getStatus());
        $this->assertSame($service, $finding->getService());
    }

    public function testApproveCreatesANewServiceInTheChosenCategory(): void
    {
        $marketing = self::category('marketing');
        $finding = self::finding('iframe', 'www.youtube.com', 'www.youtube.com');

        $service = Review::approve($finding, $marketing, null, 'YouTube', 'Google LLC');

        $this->assertSame(['YouTube', 'Google LLC', $marketing], [$service->getName(), $service->getVendor(), $service->getCategory()]);
        $this->assertSame(['www.youtube.com'], $service->getDomains());
        $this->assertSame($service, $finding->getService());
    }

    public function testANewServiceNeedsANameAndTheFindingIsLeftAlone(): void
    {
        $finding = self::finding('script', 'cdn.example.net', 'cdn.example.net');

        try {
            Review::approve($finding, self::category('analytics'), null, '   ', 'V');
            $this->fail('created a nameless service');
        } catch (\InvalidArgumentException) {
            $this->assertSame(ScanFinding::MATCHED, $finding->getStatus());
            $this->assertNull($finding->getService());
        }
    }

    public function testCookiesAndStorageKeysDoNotAddDomains(): void
    {
        $service = (new Service())->setName('GA')->setCategory(self::category('analytics'));

        Review::approve(self::finding('cookie', '_ga', 'example.com'), self::category('analytics'), $service, '', '');
        Review::approve(self::finding('storage', 'cart', 'example.com'), self::category('analytics'), $service, '', '');

        $this->assertSame([], $service->getDomains());
    }

    public function testAHostIsNotAddedTwiceAndAnIpAddressIsNotAddedAtAll(): void
    {
        $service = (new Service())->setName('X')->setCategory(self::category('analytics'))->setDomainsText('cdn.example.net');

        Review::approve(self::finding('script', 'cdn.example.net', 'cdn.example.net'), self::category('analytics'), $service, '', '');
        Review::approve(self::finding('script', '203.0.113.7', '203.0.113.7'), self::category('analytics'), $service, '', '');

        $this->assertSame(['cdn.example.net'], $service->getDomains());
    }

    public function testNamesAreReducedToPlainText(): void
    {
        $service = Review::approve(self::finding('script', 'a.example.net', 'a.example.net'), self::category('analytics'), null, '<script>x</script>Acme', '<b>Acme</b> Inc');

        $this->assertSame('xAcme', $service->getName());
        $this->assertSame('Acme Inc', $service->getVendor());
    }

    public function testApproveMatchedGroupsByCategoryAndVendorAndReusesExistingServices(): void
    {
        $analytics = self::category('analytics');
        $marketing = self::category('marketing');
        $existing = (new Service())->setName('google')->setCategory($analytics);

        $a = self::finding('cookie', '_ga', 'example.com')->setSuggestion('analytics', 'Google');
        $b = self::finding('script', 'www.google-analytics.com', 'www.google-analytics.com')->setSuggestion('analytics', 'Google');
        $c = self::finding('cookie', '_fbp', 'example.com')->setSuggestion('marketing', 'Meta');
        $d = self::finding('script', 'connect.facebook.net', 'connect.facebook.net')->setSuggestion('marketing', 'Meta');

        $created = Review::approveMatched([$a, $b, $c, $d], ['analytics' => $analytics, 'marketing' => $marketing], [$existing]);

        $this->assertCount(1, $created);
        $this->assertSame('Meta', $created[0]->getName());
        $this->assertSame($existing, $a->getService());
        $this->assertSame($existing, $b->getService());
        $this->assertSame($created[0], $c->getService());
        $this->assertSame($created[0], $d->getService());
        $this->assertSame(['www.google-analytics.com'], $existing->getDomains());

        foreach ([$a, $b, $c, $d] as $finding) {
            $this->assertSame(ScanFinding::APPROVED, $finding->getStatus());
        }
    }

    public function testApproveMatchedSkipsUnmatchedNoVendorAndUnknownCategoryFindings(): void
    {
        $analytics = self::category('analytics');
        $unclassified = self::finding('cookie', 'a', 'e.com', ScanFinding::UNCLASSIFIED);
        $noVendor = self::finding('cookie', 'b', 'e.com')->setSuggestion('analytics', '');
        $gone = self::finding('cookie', 'c', 'e.com')->setSuggestion('deleted_category', 'V');
        $ignored = self::finding('cookie', 'd', 'e.com', ScanFinding::IGNORED)->setSuggestion('analytics', 'V');

        $created = Review::approveMatched([$unclassified, $noVendor, $gone, $ignored], ['analytics' => $analytics], []);

        $this->assertSame([], $created);
        $this->assertSame(ScanFinding::UNCLASSIFIED, $unclassified->getStatus());
        $this->assertSame(ScanFinding::MATCHED, $noVendor->getStatus());
        $this->assertSame(ScanFinding::MATCHED, $gone->getStatus());
        $this->assertSame(ScanFinding::IGNORED, $ignored->getStatus());
    }

    public function testIgnoreOnlyAffectsUnreviewedFindings(): void
    {
        $open = self::finding('cookie', 'a', 'e.com');
        $approved = self::finding('cookie', 'b', 'e.com', ScanFinding::APPROVED);

        Review::ignore($open);
        Review::ignore($approved);

        $this->assertSame(ScanFinding::IGNORED, $open->getStatus());
        $this->assertSame(ScanFinding::APPROVED, $approved->getStatus());
    }

    public function testUnignoreClassifiesAgain(): void
    {
        $classifier = new Classifier([['kind' => 'cookie', 'pattern' => '_ga', 'category' => 'analytics', 'vendor' => 'Google', 'origin' => 'bundled']], static fn(string $name): bool => false);
        $known = self::finding('cookie', '_ga', 'e.com', ScanFinding::IGNORED);
        $unknown = self::finding('cookie', 'zzz', 'e.com', ScanFinding::IGNORED);
        $approved = self::finding('cookie', '_ga', 'f.com', ScanFinding::APPROVED);

        Review::unignore($known, $classifier);
        Review::unignore($unknown, $classifier);
        Review::unignore($approved, $classifier);

        $this->assertSame([ScanFinding::MATCHED, 'analytics'], [$known->getStatus(), $known->getSuggestedCategory()]);
        $this->assertSame(ScanFinding::UNCLASSIFIED, $unknown->getStatus());
        $this->assertSame(ScanFinding::APPROVED, $approved->getStatus());
    }

    public function testReassignChangesTheSuggestedCategoryOfAnUnreviewedFinding(): void
    {
        $open = self::finding('cookie', 'a', 'e.com', ScanFinding::UNCLASSIFIED);
        $approved = self::finding('cookie', 'b', 'e.com', ScanFinding::APPROVED)->setSuggestion('analytics', 'V');

        Review::reassign($open, 'marketing');
        Review::reassign($approved, 'marketing');

        $this->assertSame([ScanFinding::MATCHED, 'marketing'], [$open->getStatus(), $open->getSuggestedCategory()]);
        $this->assertSame('analytics', $approved->getSuggestedCategory());
    }

    /**
     * @return array<string, array{ScanFinding, ?array{kind: string, pattern: string}}>
     */
    public static function rules(): array
    {
        return [
            'cookie' => [self::finding('cookie', '_ga', 'e.com'), ['kind' => 'cookie', 'pattern' => '_ga']],
            'storage' => [self::finding('storage', 'cart', 'e.com'), ['kind' => 'storage', 'pattern' => 'cart']],
            'script host' => [self::finding('script', 'cdn.example.net', 'cdn.example.net'), ['kind' => 'host', 'pattern' => 'cdn.example.net']],
            'iframe host' => [self::finding('iframe', 'x', 'www.youtube.com'), ['kind' => 'host', 'pattern' => 'www.youtube.com']],
            'markup' => [self::finding('cookie', '<b>x</b>', 'e.com'), null],
            'space' => [self::finding('cookie', 'a b', 'e.com'), null],
            'wildcard in a literal name' => [self::finding('cookie', 'a*b', 'e.com'), null],
            'too long' => [self::finding('cookie', str_repeat('a', 129), 'e.com'), null],
            'empty' => [self::finding('cookie', '', 'e.com'), null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rules')]
    public function testOverrideRule(ScanFinding $finding, ?array $expected): void
    {
        $this->assertSame($expected, Review::overrideRule($finding));
    }

    public function testDefaultCategoryPrefersTheSuggestionThenTheFirstThenNothing(): void
    {
        $this->assertSame('marketing', Review::defaultCategory('marketing', ['necessary', 'marketing']));
        $this->assertSame('necessary', Review::defaultCategory('deleted', ['necessary', 'marketing']));
        $this->assertSame('necessary', Review::defaultCategory(null, ['necessary', 'marketing']));
        $this->assertNull(Review::defaultCategory('x', []));
    }

    public function testApprovingTheSameFindingTwiceChangesNothingTheSecondTime(): void
    {
        $analytics = self::category('analytics');
        $finding = self::finding('script', 'cdn.example.net', 'cdn.example.net');
        $first = Review::approve($finding, $analytics, null, 'First', '');

        try {
            Review::approve($finding, $analytics, null, 'Second', '');
            $this->fail('approved twice');
        } catch (\DomainException) {
            $this->assertSame($first, $finding->getService());
            $this->assertSame('First', $finding->getService()->getName());
        }
    }

    public function testIgnoredAndGoneFindingsCannotBeApproved(): void
    {
        $analytics = self::category('analytics');
        $service = (new Service())->setName('GA')->setCategory($analytics);

        foreach ([ScanFinding::IGNORED, ScanFinding::GONE] as $status) {
            $finding = self::finding('script', 'cdn.example.net', 'cdn.example.net', $status);

            try {
                Review::approve($finding, $analytics, $service, '', '');
                $this->fail('approved a ' . $status . ' finding');
            } catch (\DomainException) {
                $this->assertSame([$status, null], [$finding->getStatus(), $finding->getService()]);
                $this->assertSame([], $service->getDomains());
            }
        }
    }

    public function testAnExistingServiceNeedsNoCategoryButANewOneDoes(): void
    {
        $service = (new Service())->setName('GA')->setCategory(self::category('analytics'));

        $this->assertSame($service, Review::approve(self::finding('cookie', '_ga', 'e.com'), null, $service, '', ''));

        $this->expectException(\InvalidArgumentException::class);
        Review::approve(self::finding('cookie', '_x', 'e.com'), null, null, 'New', '');
    }

    public function testApproveMatchedNeverAttachesToAnInactiveService(): void
    {
        $analytics = self::category('analytics');
        $inactive = (new Service())->setName('Google')->setCategory($analytics)->setActive(false);
        $finding = self::finding('cookie', '_ga', 'example.com')->setSuggestion('analytics', 'Google');

        $created = Review::approveMatched([$finding], ['analytics' => $analytics], [$inactive]);

        $this->assertCount(1, $created);
        $this->assertNotSame($inactive, $finding->getService());
        $this->assertSame($created[0], $finding->getService());
    }
}
