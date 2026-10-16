<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Exception\TableNotFoundException;
use CookieManagerKit\Consent\{ConsentChoice, ConsentLogStore, PersistingConsentRecorder};

final class PersistingConsentRecorderTest extends TestCase
{
    private function choice(?string $subject = null, array $categories = ['necessary'], string $trigger = 'reject_all'): ConsentChoice
    {
        return new ConsentChoice(2, $categories, $trigger, 'en_GB', 'Chrome/desktop', $subject);
    }

    public function testTheFirstChoiceCreatesASubjectAndReturnsItsId(): void
    {
        $store = new InMemoryConsentLogStore();
        $id = (new PersistingConsentRecorder($store))->record($this->choice());

        $this->assertNotNull($id);
        $this->assertCount(1, $store->subjects);
        $this->assertCount(1, $store->entries);
        $this->assertSame($id, $store->entries[0]['subject']);
    }

    public function testALaterChoiceKeepsTheSameIdAndAppendsAnEntry(): void
    {
        $store = new InMemoryConsentLogStore();
        $recorder = new PersistingConsentRecorder($store);

        $first = $recorder->record($this->choice());
        $second = $recorder->record($this->choice($first, ['necessary', 'analytics'], 'custom'));

        $this->assertSame($first, $second);
        $this->assertCount(1, $store->subjects);
        $this->assertCount(2, $store->entries);
        $this->assertSame(['necessary', 'analytics'], $store->entries[1]['choice']->categories);
    }

    public function testAnUnknownIdCreatesANewSubject(): void
    {
        // Review focus 1: the subject was purged by retention, or the id was made up.
        $store = new InMemoryConsentLogStore();
        $id = (new PersistingConsentRecorder($store))->record($this->choice('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        $this->assertNotNull($id);
        $this->assertNotSame('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $id);
        $this->assertCount(1, $store->subjects);
    }

    public function testAStorageFailureReturnsNullAndDoesNotThrow(): void
    {
        $store = new class implements ConsentLogStore {
            public function append(ConsentChoice $choice, \DateTimeImmutable $now): string
            {
                throw new \RuntimeException('disk full');
            }
        };

        $this->assertNull((new PersistingConsentRecorder($store))->record($this->choice()));
    }

    public function testAMissingTableIsSilentBeforeTheUpdateHasRun(): void
    {
        // Review focus 2.
        $driver = new class('no such table') extends \Exception implements \Doctrine\DBAL\Driver\DriverException {
            public function getErrorCode(): int
            {
                return 0;
            }

            public function getSQLState(): ?string
            {
                return null;
            }
        };
        $store = new class(new TableNotFoundException('no such table', $driver)) implements ConsentLogStore {
            public function __construct(private \Throwable $e) {}

            public function append(ConsentChoice $choice, \DateTimeImmutable $now): string
            {
                throw $this->e;
            }
        };

        $this->assertNull((new PersistingConsentRecorder($store))->record($this->choice()));
    }
}
