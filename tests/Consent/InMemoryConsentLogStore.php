<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use CookieManagerKit\Consent\{ConsentChoice, ConsentLogStore};

/**
 * Same contract as the Doctrine store: an unknown or absent subject id creates a new subject.
 */
final class InMemoryConsentLogStore implements ConsentLogStore
{
    private int $counter = 0;
    /** @var array<string, int> subject id => last decision (unix seconds) */
    public array $subjects = [];

    /** @var list<array{subject: string, choice: ConsentChoice, at: int}> */
    public array $entries = [];

    public function append(ConsentChoice $choice, \DateTimeImmutable $now): string
    {
        $id = $choice->subjectId;

        if ($id === null || !isset($this->subjects[$id])) {
            $id = sprintf('00000000-0000-4000-8000-%012d', ++$this->counter);
        }

        $this->subjects[$id] = $now->getTimestamp();
        $this->entries[] = ['subject' => $id, 'choice' => $choice, 'at' => $now->getTimestamp()];

        return $id;
    }
}
