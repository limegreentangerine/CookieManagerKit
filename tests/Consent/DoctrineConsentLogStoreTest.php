<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use CookieManagerKit\Entity\{ConsentLogEntry, ConsentSubject};
use CookieManagerKit\Consent\{ConsentChoice, DoctrineConsentLogStore};

final class DoctrineConsentLogStoreTest extends TestCase
{
    /**
     * @return array{0: EntityManagerInterface, 1: \ArrayObject<int, object>}
     */
    private function em(?ConsentSubject $existing): array
    {
        /** @var \ArrayObject<int, object> $persisted */
        $persisted = new \ArrayObject();
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('find')->willReturn($existing);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->method('transactional')->willReturnCallback(static fn(callable $fn) => $fn());
        $em->method('persist')->willReturnCallback(static function (object $entity) use ($persisted): void {
            $persisted->append($entity);
        });
        // The database assigns a subject's GUID when the row is inserted, which is on flush.
        $em->method('flush')->willReturnCallback(static function () use ($persisted): void {
            foreach ($persisted as $entity) {
                if ($entity instanceof ConsentSubject && !(new \ReflectionProperty($entity, 'id'))->isInitialized($entity)) {
                    (new \ReflectionProperty($entity, 'id'))->setValue($entity, '3f2504e0-4f89-11d3-9a0c-0305e82c3301');
                }
            }
        });

        return [$em, $persisted];
    }

    public function testANewBrowserGetsASubjectAndAnEntry(): void
    {
        [$em, $persisted] = $this->em(null);
        $id = (new DoctrineConsentLogStore($em))->append(
            new ConsentChoice(4, ['necessary', 'analytics'], 'custom', 'en_GB', 'Firefox/desktop', null),
            new \DateTimeImmutable('2026-10-16 12:00:00 UTC'),
        );

        $this->assertSame('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $id);
        $classes = array_map(static fn(object $o): string => $o::class, $persisted->getArrayCopy());
        $this->assertSame([ConsentSubject::class, ConsentLogEntry::class], $classes);

        $entry = $persisted[1];
        $this->assertSame(4, $entry->getPolicyVersion());
        $this->assertSame(['necessary', 'analytics'], $entry->getCategories());
        $this->assertSame('custom', $entry->getTrigger());
        $this->assertSame('Firefox/desktop', $entry->getUserAgentFamily());
        $this->assertSame((new \DateTimeImmutable('2026-10-16 12:00:00 UTC'))->getTimestamp(), $entry->getCreatedAt());
    }

    public function testAKnownSubjectIsReusedAndTouched(): void
    {
        $subject = new ConsentSubject();
        (new \ReflectionProperty($subject, 'id'))->setValue($subject, '3f2504e0-4f89-11d3-9a0c-0305e82c3301');
        $subject->setDateUpdated(new \DateTimeImmutable('2020-01-01'));
        [$em, $persisted] = $this->em($subject);

        $now = new \DateTimeImmutable('2026-10-16 12:00:00');
        $id = (new DoctrineConsentLogStore($em))->append(
            new ConsentChoice(4, ['necessary'], 'reject_all', 'en_GB', 'Other/unknown', '3f2504e0-4f89-11d3-9a0c-0305e82c3301'),
            $now,
        );

        $this->assertSame('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $id);
        $this->assertCount(1, $persisted);
        $this->assertInstanceOf(ConsentLogEntry::class, $persisted[0]);
        $this->assertEquals($now, $subject->getDateUpdated());
    }

    public function testAnUnknownWellFormedIdGetsANewSubjectAndNeverReusesTheClaimedId(): void
    {
        $claimed = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        [$em, $persisted] = $this->em(null);

        $id = (new DoctrineConsentLogStore($em))->append(
            new ConsentChoice(4, ['necessary'], 'reject_all', 'en_GB', 'Other/unknown', $claimed),
            new \DateTimeImmutable('2026-10-16 12:00:00 UTC'),
        );

        $this->assertNotSame($claimed, $id);
        $this->assertSame('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $id);
        $classes = array_map(static fn(object $o): string => $o::class, $persisted->getArrayCopy());
        $this->assertSame([ConsentSubject::class, ConsentLogEntry::class], $classes);
        $this->assertSame($persisted[0], $persisted[1]->getSubject());
        $this->assertSame($id, $persisted[0]->getId());
    }
}
