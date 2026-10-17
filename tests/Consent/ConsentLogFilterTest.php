<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\ConsentLogFilter;

final class ConsentLogFilterTest extends TestCase
{
    private const GUID = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

    private function parse(array $in = []): ConsentLogFilter
    {
        $in += ['from' => null, 'to' => null, 'trigger' => null, 'version' => null, 'category' => null, 'id' => null];

        return ConsentLogFilter::parse($in['from'], $in['to'], $in['trigger'], $in['version'], $in['category'], $in['id'], '2026-10-16');
    }

    public function testEmptyInputMeansNoFilters(): void
    {
        $f = $this->parse();

        $this->assertSame('', $f->trigger);
        $this->assertSame(0, $f->version);
        $this->assertSame('', $f->category);
        $this->assertSame('', $f->consentId);
        $this->assertSame('', $f->rejectedId);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}\z/', $f->from);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}\z/', $f->to);
    }

    public function testValidValuesPass(): void
    {
        $f = $this->parse(['trigger' => 'gpc', 'version' => '3', 'category' => 'analytics', 'id' => strtoupper(self::GUID)]);

        $this->assertSame('gpc', $f->trigger);
        $this->assertSame(3, $f->version);
        $this->assertSame('analytics', $f->category);
        $this->assertSame(self::GUID, $f->consentId);
    }

    public function testHostileValuesAreIgnored(): void
    {
        $f = $this->parse(['trigger' => '"><script>', 'version' => '3 OR 1=1', 'category' => "x' OR '1'='1", 'from' => 'nope', 'to' => ['x']]);

        $this->assertSame('', $f->trigger);
        $this->assertSame(0, $f->version);
        $this->assertSame('', $f->category);
    }

    public function testASearchThatIsNotAGuidIsKeptOnlyForRedisplayAndNeverQueried(): void
    {
        // Review focus 5.
        $f = $this->parse(['id' => '"><script>alert(1)</script>' . str_repeat('x', 200)]);

        $this->assertSame('', $f->consentId);
        $this->assertSame(64, mb_strlen($f->rejectedId));
    }
}
