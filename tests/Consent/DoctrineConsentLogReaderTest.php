<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\{ConsentLogFilter, DoctrineConsentLogReader};

final class DoctrineConsentLogReaderTest extends TestCase
{
    public function testEveryFilterValueIsBoundNeverConcatenated(): void
    {
        $captured = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('0');
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql, array $params) use (&$captured): array {
            $captured = [$sql, $params];

            return [];
        });

        $filter = ConsentLogFilter::parse('2026-10-01', '2026-10-16', 'gpc', '3', 'analytics', '3f2504e0-4f89-11d3-9a0c-0305e82c3301', '2026-10-16');
        (new DoctrineConsentLogReader($connection))->page($filter, 50, 0);

        [$sql, $params] = $captured;
        $this->assertStringNotContainsString('gpc', $sql);
        $this->assertStringNotContainsString('analytics', $sql);
        $this->assertStringNotContainsString('3f2504e0', $sql);
        $this->assertContains('gpc', $params);
        $this->assertContains(3, $params);
        $this->assertContains('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $params);
        $this->assertContains('%"analytics"%', $params);
    }

    public function testRowsAreMappedToTheDocumentedShape(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('1');
        $connection->method('fetchAllAssociative')->willReturn([[
            'createdAt' => '1760616000',
            'consentId' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            'policyVersion' => '2',
            'categories' => '["necessary","analytics"]',
            'triggerName' => 'custom',
            'locale' => 'en_GB',
            'userAgentFamily' => 'Chrome/desktop',
            'reason' => null,
        ]]);

        $result = (new DoctrineConsentLogReader($connection))->page(ConsentLogFilter::parse(null, null, null, null, null, null, '2026-10-16'), 50, 0);

        $this->assertSame(1, $result['total']);
        $this->assertSame([
            'createdAt' => 1760616000,
            'consentId' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            'version' => 2,
            'categories' => ['necessary', 'analytics'],
            'trigger' => 'custom',
            'locale' => 'en_GB',
            'userAgentFamily' => 'Chrome/desktop',
            'reason' => '',
        ], $result['rows'][0]);
    }

    public function testTheReasonIsOnlyShownOnReconsentRows(): void
    {
        $row = static fn(string $trigger): array => [
            'createdAt' => '1760616000',
            'consentId' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            'policyVersion' => '2',
            'categories' => '["necessary"]',
            'triggerName' => $trigger,
            'locale' => 'en_GB',
            'userAgentFamily' => 'Chrome/desktop',
            'reason' => 'New analytics vendor',
        ];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('2');
        $connection->method('fetchAllAssociative')->willReturn([$row('custom'), $row('reconsent')]);

        $rows = (new DoctrineConsentLogReader($connection))->page(ConsentLogFilter::parse(null, null, null, null, null, null, '2026-10-16'), 50, 0)['rows'];

        $this->assertSame('', $rows[0]['reason']);
        $this->assertSame('New analytics vendor', $rows[1]['reason']);
    }
}
